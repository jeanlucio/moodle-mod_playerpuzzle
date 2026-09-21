<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Service to build PlayerPuzzle Lobby (view.php) page state.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context_module;
use core_useragent;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\security;
use moodle_url;
use stdClass;

/**
 * Builds the mod_playerpuzzle/view_lobby template context for view.php.
 */
class lobby_page_service {
    /**
     * Builds full page data for Lobby rendering.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $course Course record.
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param context_module $context Module context, used for the Manage Questions capability
     *  check.
     * @return array Template context for mod_playerpuzzle/view_lobby.
     */
    public static function build_page_data(
        stdClass $cm,
        stdClass $course,
        stdClass $instance,
        int $userid,
        context_module $context
    ): array {
        global $DB, $OUTPUT;
        // Carried through to play.php's own game config purely as a CSS sizing hint for the
        // question modal's answer buttons (see game_page_service::build_game_config()) — it no
        // longer picks a different page layout or opens a new window.
        $ismobile = core_useragent::is_ios() || core_useragent::is_webkit_android();
        $playparams = ['id' => $cm->id];
        if ($ismobile) {
            $playparams['mobile'] = 1;
        }

        $data = [
            'playurl' => (new moodle_url('/mod/playerpuzzle/play.php', $playparams))->out(false),
            'playtext' => get_string('playgame', 'mod_playerpuzzle'),
            // The Lobby's on-demand practice fight: reuses play.php's own
            // isdemo flag, posted as a hidden field rather than a query param — same pattern
            // as the real Play form's difficulty choice.
            'demourl' => (new moodle_url('/mod/playerpuzzle/play.php', $playparams))->out(false),
            'playdemotext' => get_string('playdemo', 'mod_playerpuzzle'),
            'sesskey' => sesskey(),
            'readytext' => get_string('lobby_ready', 'mod_playerpuzzle'),
            // The .png hero sprite is resolved through the theme like any other plugin pix;
            // the .webp panel/banner are not, since theme_config::image_url() never resolves
            // that extension (confirmed in its own source, see game_boot.js's loader for the
            // in-game copy of these same assets), so those two are a direct URL to the
            // plugin's own pix/ folder instead — the same technique game_boot.js already uses.
            'heroimageurl' => $OUTPUT->image_url('player', 'mod_playerpuzzle')->out(false),
            'panelstoneurl' => (new moodle_url('/mod/playerpuzzle/pix/panel_stone.webp'))->out(false),
            'scrollbannerurl' => (new moodle_url('/mod/playerpuzzle/pix/scroll_banner.webp'))->out(false),
        ];

        if (has_capability('mod/playerpuzzle:managequestions', $context, $userid)) {
            $data['canmanagequestions'] = true;
            $data['managequestionsurl'] = (new moodle_url(
                '/mod/playerpuzzle/managequestions.php',
                ['id' => $cm->id]
            ))->out(false);
            $data['managequestionslabel'] = get_string('managequestions', 'mod_playerpuzzle');
        }

        // The most recently started in-progress attempt, if any — shared by the progress and
        // difficulty panels below. Resuming this attempt keeps its own locked difficulty, so
        // the Lobby offers no difficulty choice while it exists.
        // Isolated from Demo attempts: a lingering in-progress Demo (abandoned mid-practice)
        // must never be mistaken here for a real attempt to resume, difficulty to report, or
        // progress to display — see security::resume_or_create_attempt_token()'s own isdemo
        // resume namespace for the matching server-side guarantee.
        $inprogress = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'status' => 'inprogress', 'isdemo' => 0],
            'timecreated DESC',
            'id, currentlevel, currentphase, difficulty',
            0,
            1
        );
        $attempt = reset($inprogress) ?: null;

        $data['cmid'] = $cm->id;
        $data += self::build_shop_context((int) $course->id, $instance, $userid);
        $data += self::build_progress_context($instance, $attempt, $userid);
        $data += self::build_minquestions_context($instance);
        $data += self::build_difficulty_context($attempt);

        return $data;
    }

    /**
     * Display label string keys per consumable type, in the same order the shop lists them.
     */
    private const SHOP_TYPE_LABELS = [
        'potion' => 'shop_type_potion',
        'shield' => 'shop_type_shield',
        'magic'  => 'shop_type_magic',
        'sword'  => 'shop_type_sword',
        'hint'   => 'shop_type_hint',
    ];

    /**
     * Builds the PuzzleCoin balance, the pre-match loadout shop, and (when a PlayerHUD coin
     * item is configured) the transfer widget that converts PlayerHUD coins into PuzzleCoin.
     *
     * The shop itself always appears — PuzzleCoin is PlayerPuzzle's own balance, credited by
     * playing regardless of whether PlayerHUD is installed. PlayerHUD only ever adds a way to
     * top PuzzleCoin up, never a way to spend directly.
     *
     * A single user_stock::get_all() call reads all five types' quantities in one query
     * (rather than one lookup per type), the same bulk-read shape attempt_consumables::
     * get_uses_by_type() already uses for the equivalent per-attempt count.
     *
     * @param int $courseid Course ID.
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @return array
     */
    private static function build_shop_context(int $courseid, stdClass $instance, int $userid): array {
        $data = [];
        $playerpuzzleid = (int) $instance->id;

        $puzzlecoinbalance = user_stock::get_quantity($userid, $playerpuzzleid, user_stock::CURRENCY_TYPE);
        $data['coinvalue'] = $puzzlecoinbalance;
        $data['coinstext'] = get_string('lobby_puzzlecoinbalance', 'mod_playerpuzzle', $puzzlecoinbalance);

        $stock = user_stock::get_all($userid, $playerpuzzleid);
        $shopitems = [];
        foreach (attempt_consumables::TYPES as $type) {
            $label = get_string(self::SHOP_TYPE_LABELS[$type], 'mod_playerpuzzle');
            $price = combat::consumable_price($type);
            $shopitems[] = [
                'type'      => $type,
                'label'     => $label,
                'quantity'  => $stock[$type],
                'ownedtext' => get_string('lobby_stockowned', 'mod_playerpuzzle', $stock[$type]),
                'price'     => $price,
                'buylabel'  => get_string('lobby_buy', 'mod_playerpuzzle'),
                'arialabel' => get_string('lobby_buy_arialabel', 'mod_playerpuzzle', (object) [
                    'label' => $label,
                    'price' => $price,
                ]),
            ];
        }
        $data['shoptitle'] = get_string('lobby_shop_title', 'mod_playerpuzzle');
        $data['shopitems'] = $shopitems;

        $blockinstanceid = hud_service::is_available_for_course($courseid)
            ? hud_service::get_block_instance_id($courseid)
            : null;
        $coinitemid = (int) $instance->hud_coin_item;
        $data['hastransfer'] = $blockinstanceid !== null && $coinitemid > 0;

        if ($data['hastransfer']) {
            $hudbalance = hud_service::get_upgrade_level($blockinstanceid, $userid, $coinitemid);
            $data['hudcoinvalue'] = $hudbalance;
            $data['hudcoinstext'] = get_string('lobby_hudcoinbalance', 'mod_playerpuzzle', $hudbalance);
            $data['transfertitle'] = get_string('lobby_transfer_title', 'mod_playerpuzzle');
            $data['transferbuttonlabel'] = get_string('lobby_transfer_button', 'mod_playerpuzzle');
            $data['transferamountlabel'] = get_string('lobby_transfer_amount_label', 'mod_playerpuzzle');
        }

        return $data;
    }

    /**
     * Builds the current Campaign progress context. With an in-progress attempt, shows its
     * live level/phase. With none, a new attempt about to be created might still not start
     * at Level 1/Phase 1 — see security::determine_start_level() — so this shows where that
     * next attempt will actually resume, rather than implying a fresh start when it is not
     * one. Single Match mode has no levels/phases to show, so this is skipped entirely for it.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass|null $attempt The most recent in-progress attempt, or null.
     * @param int $userid Current user ID.
     * @return array
     */
    private static function build_progress_context(stdClass $instance, ?stdClass $attempt, int $userid): array {
        if ($instance->gamemode !== PLAYERPUZZLE_GAMEMODE_CAMPAIGN) {
            return [];
        }

        if ($attempt !== null) {
            return [
                'progresstext' => get_string('lobby_currentprogress', 'mod_playerpuzzle', (object) [
                    'level' => $attempt->currentlevel,
                    'phase' => $attempt->currentphase,
                ]),
            ];
        }

        [$level, $phase] = security::determine_start_level(
            (int) $instance->id,
            $userid,
            max(1, (int) $instance->maxlevels)
        );
        if ($level === 1 && $phase === 1) {
            return [];
        }

        return [
            'progresstext' => get_string('lobby_resumeafterloss', 'mod_playerpuzzle', (object) [
                'level' => $level,
                'phase' => $phase,
            ]),
        ];
    }

    /**
     * Builds the difficulty picker context. With an attempt in progress, resuming keeps that
     * attempt's current-phase difficulty (the next choice happens on the phase-complete
     * screen, not here), so only a read-only line is shown. With no attempt in progress, the
     * picker is offered inside the Play form, Normal pre-selected.
     *
     * @param stdClass|null $attempt The most recent in-progress attempt, or null.
     * @return array
     */
    private static function build_difficulty_context(?stdClass $attempt): array {
        global $OUTPUT;

        $options = playerpuzzle_get_difficulty_options();

        if ($attempt !== null) {
            $current = array_key_exists($attempt->difficulty, $options)
                ? $attempt->difficulty
                : PLAYERPUZZLE_DIFFICULTY_NORMAL;
            return [
                'difficultycurrent' => get_string(
                    'lobby_difficulty_current',
                    'mod_playerpuzzle',
                    $options[$current]
                ),
            ];
        }

        $choices = [];
        foreach ($options as $value => $label) {
            $choices[] = [
                'value'   => $value,
                'label'   => $label,
                'checked' => $value === PLAYERPUZZLE_DIFFICULTY_NORMAL,
            ];
        }

        return [
            'difficultylabel'    => get_string('lobby_difficulty', 'mod_playerpuzzle'),
            'difficultyhelpicon' => $OUTPUT->help_icon('lobby_difficulty', 'mod_playerpuzzle'),
            'difficultychoices'  => $choices,
        ];
    }

    /**
     * Builds the Minimum Questions notice context: the match can require a number of
     * answered questions before it can end in victory, reviving the boss if needed —
     * this must be visible to the student before they start, or the revive would look
     * like a bug.
     *
     * @param stdClass $instance Activity instance.
     * @return array
     */
    private static function build_minquestions_context(stdClass $instance): array {
        if ((int) $instance->minquestions <= 0) {
            return [];
        }

        return [
            'minquestionstext' => get_string(
                'lobby_minquestions_notice',
                'mod_playerpuzzle',
                (int) $instance->minquestions
            ),
        ];
    }
}
