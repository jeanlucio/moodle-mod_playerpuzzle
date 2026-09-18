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
        // longer picks a different page layout or opens a new window (27/08/2026).
        $ismobile = core_useragent::is_ios() || core_useragent::is_webkit_android();
        $playparams = ['id' => $cm->id];
        if ($ismobile) {
            $playparams['mobile'] = 1;
        }

        $data = [
            'playurl' => (new moodle_url('/mod/playerpuzzle/play.php', $playparams))->out(false),
            'playtext' => get_string('playgame', 'mod_playerpuzzle'),
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
        $inprogress = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'status' => 'inprogress'],
            'timecreated DESC',
            'id, currentlevel, currentphase, difficulty',
            0,
            1
        );
        $attempt = reset($inprogress) ?: null;

        $data += self::build_hud_stats_context((int) $course->id, $instance, $userid);
        $data += self::build_progress_context($instance, $attempt, $userid);
        $data += self::build_minquestions_context($instance);
        $data += self::build_difficulty_context($attempt);
        $data += self::build_tutorial_context($instance, $userid);

        return $data;
    }

    /**
     * Builds the "Pular Tutorial" checkbox context: shown only before a student's genuine
     * first-ever attempt at this instance, matching the same "ausência de registros"
     * condition security::generate_attempt_token() re-derives independently server-side
     * (§4.12) — this is only ever a convenience for the form, never trusted on its own.
     * Never shown once any attempt row exists (including an in-progress tutorial one being
     * resumed): the choice is offered exactly once, right before that first attempt starts.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @return array
     */
    private static function build_tutorial_context(stdClass $instance, int $userid): array {
        global $DB;

        $hasanyattempt = $DB->record_exists('playerpuzzle_attempts', [
            'playerpuzzleid' => $instance->id,
            'userid'         => $userid,
        ]);
        if ($hasanyattempt) {
            return [];
        }

        return [
            'istutorialeligible' => true,
            'skiptutoriallabel'  => get_string('skiptutorial', 'mod_playerpuzzle'),
        ];
    }

    /**
     * Builds the PlayerHUD balances context: the coin balance, and the stock the student is
     * carrying of each consumable that has a PlayerHUD item configured (Espada, Escudo,
     * Poção). Only the items the teacher actually configured are shown — an unconfigured
     * item (id 0) has nothing meaningful to display. Each stat carries both a raw numeric
     * value (the visible HUD chip, paired with an icon) and the full sentence (used as the
     * chip's aria-label, so a screen reader still hears "Coins: 42" instead of a bare "42").
     *
     * @param int $courseid Course ID.
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @return array
     */
    private static function build_hud_stats_context(int $courseid, stdClass $instance, int $userid): array {
        $data = [];

        if (hud_service::is_available_for_course($courseid)) {
            $blockinstanceid = hud_service::get_block_instance_id($courseid);

            if ((int) $instance->hud_coin_item > 0) {
                $balance = hud_service::get_upgrade_level($blockinstanceid, $userid, (int) $instance->hud_coin_item);
                $data['coinvalue'] = $balance;
                $data['coinstext'] = get_string('lobby_coinbalance', 'mod_playerpuzzle', $balance);
            }

            $stockitems = [
                'sword' => ['field' => 'hud_sword_item', 'string' => 'lobby_swordstock'],
                'shield' => ['field' => 'hud_shield_item', 'string' => 'lobby_shieldstock'],
                'potion' => ['field' => 'hud_potion_item', 'string' => 'lobby_potionstock'],
            ];
            foreach ($stockitems as $key => $meta) {
                $itemid = (int) $instance->{$meta['field']};
                if ($itemid > 0) {
                    $stock = hud_service::get_upgrade_level($blockinstanceid, $userid, $itemid);
                    $data[$key . 'value'] = $stock;
                    $data[$key . 'text'] = get_string($meta['string'], 'mod_playerpuzzle', $stock);
                }
            }
        }

        $data['hasstats'] = isset($data['coinstext']) || isset($data['swordtext'])
            || isset($data['shieldtext']) || isset($data['potiontext']);

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
