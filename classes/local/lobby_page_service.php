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
        global $DB;
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
            // A Single Match, a Demo and a new Campaign choose their hero first, on hero.php, which sends
            // the choice on to play.php or saves it, see hero_page_service.
            'herourl' => (new moodle_url('/mod/playerpuzzle/hero.php', $playparams))->out(false),
            'playtext' => get_string('playgame', 'mod_playerpuzzle'),
            // The Lobby's on-demand practice fight: reuses play.php's own
            // isdemo flag, posted as a hidden field rather than a query param — same pattern
            // as the real Play form's difficulty choice.
            'demourl' => (new moodle_url('/mod/playerpuzzle/hero.php', $playparams))->out(false),
            'playdemotext' => get_string('playdemo', 'mod_playerpuzzle'),
            'sesskey' => sesskey(),
            'readytext' => get_string('lobby_ready', 'mod_playerpuzzle'),
            // The hero that stands in the Lobby until a Campaign gives the student its own: the last one they
            // chose for a match, or the first of the catalog. The .webp images are linked directly, since
            // theme_config::image_url() never resolves that extension (confirmed in its own source, see
            // game_boot.js's loader for the in-game copy of these same assets).
            'heroimageurl' => heroes::sprite_url(
                heroes::sprite(last_hero::get($userid) ?: array_key_first(heroes::CATALOG), 1)
            )->out(false),
            'frameurl' => (new moodle_url('/mod/playerpuzzle/pix/lobby_frame.webp'))->out(false),
            'textureurl' => (new moodle_url('/mod/playerpuzzle/pix/lobby_texture.webp'))->out(false),
            'scrollbannerurl' => (new moodle_url('/mod/playerpuzzle/pix/scroll_banner.webp'))->out(false),
            'shopmenulabel' => get_string('lobby_menu_shop', 'mod_playerpuzzle'),
            'settingsmenulabel' => get_string('lobby_menu_settings', 'mod_playerpuzzle'),
            'backlabel' => get_string('lobby_back', 'mod_playerpuzzle'),
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
        // difficulty panels below. Resuming this attempt keeps its own difficulty once its phase has started, so
        // the Lobby offers no difficulty choice then; a phase nothing has happened in yet still takes one.
        // Isolated from Demo attempts: a lingering in-progress Demo (abandoned mid-practice)
        // must never be mistaken here for a real attempt to resume, difficulty to report, or
        // progress to display — see security::resume_or_create_attempt_token()'s own isdemo
        // resume namespace for the matching server-side guarantee.
        $inprogress = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'status' => 'inprogress', 'isdemo' => 0],
            'timecreated DESC',
            'id, currentlevel, currentphase, campaign, difficulty, status, isdemo, moveseq, movelog, questionresults, '
                . 'phaserestarts',
            0,
            1
        );
        $attempt = reset($inprogress) ?: null;

        $data['cmid'] = $cm->id;
        // A visitor (the guest account, or a logged-in user entering through guest access)
        // only gets the Demo (see game_page_service::check_guest_demo_only()), so the Lobby
        // hides what only real play uses: the balance, shop, transfer and Play.
        if (is_guest($context, $userid)) {
            $data['isguest'] = true;
            $data['guestdemoonlytext'] = get_string('guestdemoonly', 'mod_playerpuzzle');
        }
        $data += self::build_shop_context((int) $course->id, $instance, $userid);
        $data += hero_shop::context($instance, $userid);
        // A visitor has no Campaigns, and a Single Match has none to list.
        if (!is_guest($context, $userid) && $instance->gamemode === constants::GAMEMODE_CAMPAIGN) {
            $data += campaign_history::context($instance, $userid);
            $data['canviewhistory'] = true;
            $data['historytitle'] = get_string('history_title', 'mod_playerpuzzle');
            $data['historyempty'] = get_string('history_empty', 'mod_playerpuzzle');
            $data['historyopenlabel'] = get_string('history_open', 'mod_playerpuzzle');
        }
        // Merged rather than added: a Campaign that has ended renames the Play button.
        $data = array_merge($data, self::build_progress_context($instance, $attempt, $userid));
        // Merged too: the student's hero replaces the default picture.
        $data = array_merge($data, self::build_play_context($instance, $attempt, $userid, !empty($data['newcampaign'])));
        $data += self::build_minquestions_context($instance);
        $data += self::build_noquestions_context($instance, !empty($data['canmanagequestions']));
        // Two notes talk about playing: the visitor's, which promises the Demo, and the minimum of questions the match
        // requires. Both need questions to draw, so with none they would contradict the no-questions notice, which
        // already says why nothing can be played.
        if (!empty($data['noquestions'])) {
            unset($data['guestdemoonlytext'], $data['minquestionstext']);
        }
        $data += self::build_difficulty_context($attempt);
        $data['howto'] = howtoplay::context($instance, is_guest($context, $userid));
        $data += self::build_sound_context($userid);
        $data += self::build_ranking_context($instance, $cm, $userid, $context);

        return $data;
    }

    /**
     * Builds the ranking panel's context, when the teacher has it on: the top rows, the
     * viewer's own row when outside them, and a note on what the points mean for this game
     * mode. Built with the page, so opening the panel needs no extra request.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass $cm Course module.
     * @param int $userid Current user ID.
     * @param context_module $context Module context.
     * @return array Empty when the ranking is off.
     */
    private static function build_ranking_context(
        stdClass $instance,
        stdClass $cm,
        int $userid,
        context_module $context
    ): array {
        // Nor for a visitor: it names the class's students to anyone the course lets in as
        // a visitor, the same people core keeps the participants list from. isguestuser()
        // would only catch the guest account, not a logged-in user entering through guest
        // access.
        if (empty($instance->show_ranking) || is_guest($context, $userid)) {
            return [];
        }

        $ranking = ranking_service::get_ranking($instance, $cm, $userid);
        $single = $instance->gamemode === constants::GAMEMODE_SINGLE;

        return [
            'showranking' => true,
            'rankingbuttontext' => get_string('ranking_open', 'mod_playerpuzzle'),
            'rankingtitle' => get_string('ranking_title', 'mod_playerpuzzle'),
            'rankingnote' => get_string($single ? 'ranking_note_single' : 'ranking_note_campaign', 'mod_playerpuzzle'),
            'rankingposition' => get_string('ranking_position', 'mod_playerpuzzle'),
            'rankingstudent' => get_string('ranking_student', 'mod_playerpuzzle'),
            'rankingpoints' => get_string('ranking_points', 'mod_playerpuzzle'),
            'rankingyou' => get_string('ranking_you', 'mod_playerpuzzle'),
            'rankingempty' => get_string('ranking_empty', 'mod_playerpuzzle'),
            'rankingrows' => $ranking['rows'],
            'rankinghasoutsider' => $ranking['hasoutsider'],
            'rankingoutsider' => $ranking['outsiderrow'],
            'rankingisempty' => $ranking['isempty'],
        ];
    }

    /**
     * Builds the three audio toggles' context: spoken narration, Music and Sound Effects —
     * all per-student preferences (see sound_preferences), never a site setting. Music/Sound
     * Effects mirror the same badges already offered inside the in-game HUD (hud.js); the
     * Lobby copy lets the student set them before starting a match, not only mid-game.
     *
     * @param int $userid Current user ID.
     * @return array
     */
    private static function build_sound_context(int $userid): array {
        return [
            'speechenabled' => sound_preferences::is_enabled('speech', $userid),
            'speechlabel'   => get_string('lobby_speech_label', 'mod_playerpuzzle'),
            'musicenabled'  => sound_preferences::is_enabled('music', $userid),
            'musiclabel'    => get_string('lobby_music_label', 'mod_playerpuzzle'),
            'sfxenabled'    => sound_preferences::is_enabled('sfx', $userid),
            'sfxlabel'      => get_string('lobby_sfx_label', 'mod_playerpuzzle'),
        ];
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
     * The Font Awesome icon of each consumable type, on its tile in the shop.
     */
    private const SHOP_TYPE_ICONS = [
        'potion' => 'fa-flask',
        'shield' => 'fa-shield-halved',
        'magic'  => 'fa-wand-magic-sparkles',
        'sword'  => 'fa-khanda',
        'hint'   => 'fa-lightbulb',
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
    public static function build_shop_context(int $courseid, stdClass $instance, int $userid): array {
        $data = [];
        $playerpuzzleid = (int) $instance->id;

        $puzzlecoinbalance = user_stock::get_quantity($userid, $playerpuzzleid, user_stock::CURRENCY_TYPE);
        $data['coinvalue'] = $puzzlecoinbalance;
        $data['coinstext'] = get_string('lobby_puzzlecoinbalance', 'mod_playerpuzzle', $puzzlecoinbalance);

        $stock = user_stock::get_all($userid, $playerpuzzleid);
        $shopitems = [];
        foreach (attempt_consumables::TYPES as $type) {
            if ($type === 'hint' && empty($instance->hints_enabled)) {
                continue;
            }
            $label = get_string(self::SHOP_TYPE_LABELS[$type], 'mod_playerpuzzle');
            $price = combat::consumable_price($type);
            $limit = attempt_consumables::phase_limit($type);
            $limitstring = $instance->gamemode === constants::GAMEMODE_SINGLE ? 'shop_limit_permatch' : 'shop_limit_perphase';
            $shopitems[] = [
                'type'      => $type,
                'icon'      => self::SHOP_TYPE_ICONS[$type],
                'label'     => $label,
                'quantity'  => $stock[$type],
                'ownedtext' => get_string('lobby_stockowned', 'mod_playerpuzzle', $stock[$type]),
                'limittext' => $limit === null
                    ? get_string('shop_limit_none', 'mod_playerpuzzle')
                    : get_string($limitstring, 'mod_playerpuzzle', $limit),
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
        $data += opponent_card::for_player($instance, $userid);

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
     * Where the Play button goes and which hero shows. A Single Match without an attempt to resume and a new
     * Campaign pick their hero first; everything else plays on, the Campaign with the hero the student
     * already has, which also stands in the Lobby in the place of the default picture.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass|null $attempt The most recent in-progress real attempt, or null.
     * @param int $userid Current user ID.
     * @param bool $newcampaign Whether Play starts a new Campaign.
     * @return array
     */
    private static function build_play_context(stdClass $instance, ?stdClass $attempt, int $userid, bool $newcampaign): array {
        $data = [];
        if ($newcampaign) {
            $data['playmode'] = hero_page_service::MODE_CAMPAIGN;
            $data['playviahero'] = true;
        } else if ($instance->gamemode === constants::GAMEMODE_SINGLE && $attempt === null) {
            $data['playmode'] = hero_page_service::MODE_SINGLE;
            $data['playviahero'] = true;
        }

        // Between Campaigns the Lobby keeps showing the hero of the one that ended, the one the next choice
        // comes marked with, instead of the picture the game had before heroes.
        $hero = game_page_service::current_hero($instance, $userid);
        if ($hero === null && $instance->gamemode === constants::GAMEMODE_CAMPAIGN) {
            $hero = user_hero::latest((int) $instance->id, $userid);
        }
        if ($hero !== null) {
            $data['heroimageurl'] = heroes::sprite_url(heroes::sprite($hero->hero, (int) $hero->stage))->out(false);
        }

        return $data;
    }

    /**
     * Builds the current Campaign progress context. With an in-progress attempt, shows its
     * live level/phase. With none, a new attempt about to be created might still not start
     * at Level 1/Phase 1 — see campaigns::next_start() — so this shows where that next
     * attempt will actually resume, rather than implying a fresh start when it is not one.
     * When the last Campaign has ended, it says how, and either offers the next Campaign (the
     * Play button becomes "Start a new Campaign", with its warning, and goes on to the choice of
     * hero) or says the activity allows no more. Once the hero of the next Campaign is chosen,
     * that Campaign is under way and Play is Play again. A Campaign after the first is named, so the student knows
     * which one they are in. Single Match mode has no levels/phases to show, so this is
     * skipped entirely for it.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass|null $attempt The most recent in-progress attempt, or null.
     * @param int $userid Current user ID.
     * @return array
     */
    private static function build_progress_context(stdClass $instance, ?stdClass $attempt, int $userid): array {
        if ($instance->gamemode !== constants::GAMEMODE_CAMPAIGN) {
            return [];
        }

        if ($attempt !== null) {
            return self::campaign_name_context((int) $attempt->campaign) + [
                'progresstext' => get_string('lobby_currentprogress', 'mod_playerpuzzle', (object) [
                    'level' => $attempt->currentlevel,
                    'phase' => $attempt->currentphase,
                ]),
            ];
        }

        $start = game_page_service::next_campaign_start($instance, $userid);
        // A Campaign whose hero was already chosen is started: the choice is what starts it.
        $unconfirmed = $start->startsnew && user_hero::get((int) $instance->id, $userid, $start->campaign) === null;
        if ($unconfirmed) {
            $ended = $start->campaign - 1;
            // The balance and the shop stay out of sight: starting the next Campaign empties them, so there is
            // nothing left to spend them on.
            $data = [
                'campaignended' => true,
                'progresstext' => get_string(
                    $start->ended === campaigns::ENDED_WON ? 'lobby_campaign_won' : 'lobby_campaign_outofattempts',
                    'mod_playerpuzzle',
                    $ended
                ),
            ];
            $refusal = game_page_service::campaign_limit_refusal($instance, $userid);
            if ($refusal !== null) {
                $data['campaignlimittext'] = get_string($refusal, 'mod_playerpuzzle');
                return $data;
            }

            return $data + [
                'newcampaign' => true,
                'newcampaigntext' => get_string('lobby_newcampaign_notice', 'mod_playerpuzzle'),
                'newcampaignconfirmtext' => get_string('lobby_newcampaign_confirm', 'mod_playerpuzzle'),
                'playtext' => get_string('lobby_newcampaign_button', 'mod_playerpuzzle'),
            ];
        }

        $data = self::campaign_name_context($start->campaign);
        if ($start->level === 1 && $start->phase === 1) {
            return $data;
        }

        return $data + [
            'progresstext' => get_string('lobby_resumeafterloss', 'mod_playerpuzzle', (object) [
                'level' => $start->level,
                'phase' => $start->phase,
            ]),
        ];
    }

    /**
     * Names the Campaign the student is playing, from the second one on: the first needs no name
     * while it is the only one.
     *
     * @param int $campaign Campaign number.
     * @return array
     */
    private static function campaign_name_context(int $campaign): array {
        if ($campaign < 2) {
            return [];
        }

        return ['campaignnametext' => get_string('lobby_campaignname', 'mod_playerpuzzle', $campaign)];
    }

    /**
     * Builds the difficulty button's context. With an attempt whose phase has started, resuming keeps that
     * phase's difficulty (the next choice happens on the phase-complete screen, not here), so the button is locked
     * and says so. With no attempt in progress, or one whose phase nothing has happened in yet (it sits between
     * phases, or at the start of its first), the button steps through the difficulties and its value goes with the
     * Play form: Normal first for a new attempt, the attempt's own difficulty for one being resumed.
     *
     * @param stdClass|null $attempt The most recent in-progress attempt, or null.
     * @return array
     */
    private static function build_difficulty_context(?stdClass $attempt): array {
        $options = playerpuzzle_get_difficulty_options();
        $current = $attempt !== null && array_key_exists($attempt->difficulty, $options)
            ? $attempt->difficulty
            : constants::DIFFICULTY_NORMAL;

        if ($attempt !== null && !security::phase_not_started($attempt)) {
            return [
                // The same button as the open choice, only locked, so the row looks alike either way.
                'difficultylockedtext' => get_string('lobby_difficulty_selected', 'mod_playerpuzzle', $options[$current]),
                'difficultylockednote' => get_string('lobby_difficulty_locked_note', 'mod_playerpuzzle'),
            ];
        }

        // What lobby_settings.js steps through when the button is pressed: each difficulty with the text the
        // button then shows and the line of what it changes, so no request is needed to change it.
        $choices = [];
        foreach ($options as $value => $label) {
            $choices[] = [
                'value' => $value,
                'text' => get_string('lobby_difficulty_selected', 'mod_playerpuzzle', $label),
                'description' => get_string('lobby_difficulty_desc_' . $value, 'mod_playerpuzzle'),
            ];
        }
        $chosen = array_values(array_filter($choices, static fn(array $c): bool => $c['value'] === $current));

        $context = [
            'difficultylabel' => get_string('lobby_difficulty', 'mod_playerpuzzle'),
            'difficultyhelp' => get_string('lobby_difficulty_help', 'mod_playerpuzzle'),
            'difficultyvalue' => $current,
            'difficultychoicesjson' => json_encode($choices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            // What the button and the line under it say before the first press; lobby_settings.js keeps them in
            // step with the choice from then on.
            'difficultyselectedtext' => $chosen[0]['text'],
            'difficultydescription' => $chosen[0]['description'],
        ];
        if ($attempt !== null) {
            // Said only for an attempt being resumed: the student left a phase untouched and may still choose it.
            $context['difficultyopennote'] = get_string('lobby_difficulty_open', 'mod_playerpuzzle');
        }
        return $context;
    }

    /**
     * Builds the context for an activity that requires answering questions but has none approved yet: the
     * Lobby then offers no match (see game_page_service::lacks_questions()) and says why.
     *
     * A student is told to ask the teacher. Whoever manages the questions is told what to do instead:
     * review the AI-generated questions already waiting, or add some.
     *
     * @param stdClass $instance Activity instance.
     * @param bool $canmanage Whether the viewer may manage the activity's questions.
     * @return array
     */
    private static function build_noquestions_context(stdClass $instance, bool $canmanage): array {
        if (!game_page_service::lacks_questions($instance)) {
            return [];
        }

        $text = get_string('lobby_noquestions_notice', 'mod_playerpuzzle');
        if ($canmanage) {
            $pending = questions_repository::count_pending_ai_questions((int) $instance->id);
            $text = $pending > 0
                ? get_string('lobby_noquestions_teacherpending', 'mod_playerpuzzle', $pending)
                : get_string('lobby_noquestions_teacher', 'mod_playerpuzzle');
        }

        return [
            'noquestions' => true,
            'noquestionstext' => $text,
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
