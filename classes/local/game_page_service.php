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
     * (failures/restarts across the whole campaign, §4.6); Single Match counts finished
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
     * Resumes or creates the attempt, and assembles the full JS game config: the scaled
     * boss/student HP and combat damage for the attempt's current level/phase (Single Match
     * always resolves to the base values unchanged, since its attempts stay at Level 1,
     * Phase 1 — §4.6), the anti-replay token, sprites, and the blind (JSON Cego) question set.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid Current user ID.
     * @param bool $ismobile Whether the request is from a mobile device.
     * @return array JS game config for game_boot.js.
     */
    public static function build_game_config(
        stdClass $cm,
        stdClass $instance,
        context_module $context,
        int $userid,
        bool $ismobile
    ): array {
        global $OUTPUT;

        $attemptinfo = security::resume_or_create_attempt_token((int) $instance->id, $userid);

        $bosshp = combat::calculate_boss_hp(
            (int) $instance->basebosshp,
            $attemptinfo->currentlevel,
            $attemptinfo->currentphase
        );
        $studenthp = combat::calculate_student_hp(
            (int) $instance->basestudenthp,
            $attemptinfo->currentlevel,
            $attemptinfo->currentphase
        );
        // Reuses the boss HP formula for combat damage: same shape of growth (§17), and it keeps a
        // single source of truth for "how much combat should scale" at this level/phase.
        $bossdamage = combat::calculate_boss_hp(
            (int) $instance->bossdamage,
            $attemptinfo->currentlevel,
            $attemptinfo->currentphase
        );

        $questions = question_fetcher::get_questions_for_frontend((int) $instance->questioncategory, $context);

        $spriteurls = [];
        for ($i = 0; $i < 7; $i++) {
            $spriteurls[] = $OUTPUT->image_url('sprites/item' . $i, 'mod_playerpuzzle')->out(false);
        }

        $bossbasename = str_replace('.png', '', $instance->bossavatar);
        $bossurl = $OUTPUT->image_url('bosses/' . $bossbasename, 'mod_playerpuzzle')->out(false);
        $bgurl = $OUTPUT->image_url('bg_landscape', 'mod_playerpuzzle')->out(false);

        return [
            'cmid'         => $cm->id,
            'token'        => $attemptinfo->token,
            'gamemode'     => $instance->gamemode,
            'currentlevel' => $attemptinfo->currentlevel,
            'currentphase' => $attemptinfo->currentphase,
            'maxlevels'    => (int) $instance->maxlevels,
            'bosshp'       => $bosshp,
            'studenthp'    => $studenthp,
            'bossdamage'   => $bossdamage,
            'bossavatar'   => $instance->bossavatar,
            'bossurl'      => $bossurl,
            'bgurl'        => $bgurl,
            'spriteurls'   => $spriteurls,
            'questions'    => $questions,
            'mobile'       => $ismobile,
            'viewurl'      => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]))->out(false),
        ];
    }
}
