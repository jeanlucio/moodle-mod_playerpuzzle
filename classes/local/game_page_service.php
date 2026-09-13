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
 * Service to build PlayerPuzzle game page (play.php) state.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context_module;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\question_fetcher;
use mod_playerpuzzle\local\engine\security;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Handles the attempt-limit check and game config assembly for play.php.
 */
class game_page_service {
    /**
     * Checks the attempt limit for the instance's own game mode, throwing when it has
     * already been reached. Campaign counts finished attempts against `maxattempts`
     * (failures/restarts across the whole campaign); Single Match counts finished
     * attempts against `max_single_matches` (each match played, win or lose, spends one).
     * Both are 0 = unlimited, and both only count attempts already in a final status —
     * an abandoned in-progress row is resumed by resume_or_create_attempt_token(), never
     * counted here.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the limit for this game mode has been reached.
     */
    public static function check_attempt_limit(stdClass $instance, int $userid, moodle_url $returnurl): void {
        global $DB;

        if ($instance->gamemode === PLAYERPUZZLE_GAMEMODE_SINGLE) {
            $limit = (int) $instance->max_single_matches;
            $errorkey = 'maxsinglematchesreached';
        } else {
            $limit = (int) $instance->maxattempts;
            $errorkey = 'maxattemptsreached';
        }

        if ($limit <= 0) {
            return;
        }

        $finishedattempts = $DB->count_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress',
            ['ppid' => $instance->id, 'uid' => $userid, 'inprogress' => 'inprogress']
        );
        if ($finishedattempts >= $limit) {
            throw new moodle_exception($errorkey, 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Charges the configured retry-cost item, from the 2nd attempt onwards, before a new
     * attempt is created — never for the very first attempt, and never for a play.php POST
     * that will only resume an already in-progress one (a plain reload, or the client's own
     * "Continue"/"Play again" form submit hitting the same phase again is not "a new try").
     *
     * Retries stay free whenever there is nothing configured to charge against: no item set,
     * or no block_playerhud instance in the course at all — this gate is meant as an optional
     * toll a teacher opts into, not a hard requirement of the activity. Only an actually-
     * configured item with insufficient balance blocks the retry.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the item is configured and available, but the student
     *  does not hold enough of it.
     */
    public static function check_retry_cost(stdClass $instance, int $userid, moodle_url $returnurl): void {
        global $DB;

        $itemid = (int) $instance->hud_retry_cost_item;
        if ($itemid <= 0) {
            return;
        }
        if (security::has_inprogress_attempt((int) $instance->id, $userid)) {
            return;
        }

        $finishedattempts = $DB->count_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress',
            ['ppid' => $instance->id, 'uid' => $userid, 'inprogress' => 'inprogress']
        );
        if ($finishedattempts === 0) {
            return;
        }

        $blockinstanceid = hud_service::get_block_instance_id((int) $instance->course);
        if ($blockinstanceid === null) {
            return;
        }

        $qty = max(1, (int) $instance->hud_retry_cost_qty);
        if (!hud_service::consume_item($blockinstanceid, $userid, $itemid, $qty)) {
            throw new moodle_exception('insufficientretrycost', 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Resumes or creates the attempt, and assembles the full JS game config: the scaled
     * boss/student HP and combat damage for the attempt's current level/phase (Single Match
     * always resolves to the base values unchanged, since its attempts stay at Level 1,
     * Phase 1), the anti-replay token, sprites, and the Blind JSON question set.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid Current user ID.
     * @param bool $ismobile Whether the request is from a mobile device.
     * @param string $difficulty Student-chosen difficulty for a fresh attempt; not applied
     *  when an in-progress attempt is resumed (that attempt keeps its current phase's own
     *  difficulty, which advance_phase changes between phases).
     * @return array JS game config for game_boot.js.
     */
    public static function build_game_config(
        stdClass $cm,
        stdClass $instance,
        context_module $context,
        int $userid,
        bool $ismobile,
        string $difficulty = 'normal'
    ): array {
        global $OUTPUT;

        $attemptinfo = security::resume_or_create_attempt_token((int) $instance->id, $userid, $difficulty);
        $difficulty = $attemptinfo->difficulty;

        // Boss HP and boss damage carry the level/phase scaling and then the difficulty
        // factor on top (Easy halves, Hard doubles). Student HP is never touched by
        // difficulty. save_progress/advance_phase apply the same factor to their own clamp,
        // so the grade a run produces is unaffected by the difficulty chosen.
        $bosshp = combat::apply_difficulty(
            combat::calculate_boss_hp(
                (int) $instance->basebosshp,
                $attemptinfo->currentlevel,
                $attemptinfo->currentphase
            ),
            $difficulty
        );
        $studenthp = combat::calculate_student_hp(
            (int) $instance->basestudenthp,
            $attemptinfo->currentlevel,
            $attemptinfo->currentphase
        );
        // Reuses the boss HP formula for combat damage: same shape of growth, and it keeps a
        // single source of truth for "how much combat should scale" at this level/phase.
        $bossdamage = combat::apply_difficulty(
            combat::calculate_boss_hp(
                (int) $instance->bossdamage,
                $attemptinfo->currentlevel,
                $attemptinfo->currentphase
            ),
            $difficulty
        );

        $questions = question_fetcher::get_questions_for_frontend((int) $instance->questioncategory, $context);

        $consumableuses = [];
        foreach (attempt_consumables::TYPES as $type) {
            $consumableuses[$type] = attempt_consumables::get_uses($attemptinfo->attemptid, $type);
        }

        // Whether a PlayerHUD item is configured for each type (never its stock quantity,
        // which the client cannot know without an extra round trip) — lets the client try
        // source=hud first only where it could possibly succeed, falling back to source=local
        // otherwise. Magia Rápida has no PlayerHUD item at all, so it is always false.
        $hudconfigured = [
            'potion' => (int) $instance->hud_potion_item > 0,
            'shield' => (int) $instance->hud_shield_item > 0,
            'magic'  => false,
            'sword'  => (int) $instance->hud_sword_item > 0,
        ];

        $spriteurls = [];
        for ($i = 0; $i < 7; $i++) {
            $spriteurls[] = $OUTPUT->image_url('sprites/item' . $i, 'mod_playerpuzzle')->out(false);
        }

        $bossbasename = str_replace('.png', '', $instance->bossavatar);
        $bossurl = $OUTPUT->image_url('bosses/' . $bossbasename, 'mod_playerpuzzle')->out(false);
        $playerurl = $OUTPUT->image_url('player', 'mod_playerpuzzle')->out(false);
        $stagebgurl = $OUTPUT->image_url('stage_bg', 'mod_playerpuzzle')->out(false);
        // Mobile's own layout (game_boot.js's 9:16 L object) still fills its full canvas with
        // this same background image; desktop moved to the stage band + panels instead.
        $bgurl = $OUTPUT->image_url('bg_landscape', 'mod_playerpuzzle')->out(false);

        return [
            'cmid'                 => $cm->id,
            'token'                => $attemptinfo->token,
            'gamemode'             => $instance->gamemode,
            'difficulty'           => $difficulty,
            // The client never counts its own answered questions for the "Perguntas: X/N" HUD
            // counter or the boss-revive rule — it only ever mirrors this server-reported total,
            // updated on every validate_answer call.
            'minquestions'         => (int) $instance->minquestions,
            'questionstotal'       => $attemptinfo->questionstotal,
            // Coin ledger and consumable-use counts already on the attempt (0 for a fresh one,
            // whatever the current phase/match carries for a resumed one) — lets the shop badges
            // render their correct state on load, without needing a failed purchase first.
            'coinsearnedsofar'     => $attemptinfo->coinsearned,
            'bosscoinsearnedsofar' => $attemptinfo->bosscoinsearned,
            'coinsspent'           => $attemptinfo->coinsspent,
            // Snapshot of the board/HP/meters/turn left by a checkpoint (null for a phase
            // that never got one, or that was just started/advanced) — lets board.js/
            // combat.js resume the fight in place instead of always starting the phase
            // fresh (Fase 5 Lote D).
            'combatstate'          => $attemptinfo->combatstate,
            'maxconsumables'       => (int) $instance->maxconsumables,
            'consumableuses'       => $consumableuses,
            'hudconfigured'        => $hudconfigured,
            // Coin multiplier the client applies to its own gold display so the end screen
            // matches what the server will actually bank (Easy 0.5x, Hard 3x). The client
            // still sends the raw, unmultiplied gold; the server re-applies this factor.
            'coinfactor'           => combat::difficulty_coin_factor($difficulty),
            'currentlevel'         => $attemptinfo->currentlevel,
            'currentphase'         => $attemptinfo->currentphase,
            'maxlevels'            => (int) $instance->maxlevels,
            'bosshp'               => $bosshp,
            'studenthp'            => $studenthp,
            'bossdamage'           => $bossdamage,
            // Deliberately not scaled by level/phase, unlike bossdamage above: a teacher's
            // fixed-price consumable shop would otherwise get proportionally cheaper as a
            // campaign progresses.
            'coingain'             => (int) $instance->coingain,
            'bossavatar'           => $instance->bossavatar,
            'bossurl'              => $bossurl,
            'playerurl'            => $playerurl,
            'stagebgurl'           => $stagebgurl,
            'bgurl'                => $bgurl,
            'spriteurls'           => $spriteurls,
            'questions'            => $questions,
            'mobile'               => $ismobile,
            'viewurl'              => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]))->out(false),
        ];
    }
}
