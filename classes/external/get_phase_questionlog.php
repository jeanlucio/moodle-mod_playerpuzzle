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
 * External function to read back the current phase's answered-question log.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playerpuzzle\local\attempt_questions;
use moodle_exception;

/**
 * Read-only lookup of the questions answered so far in the attempt's current level/phase,
 * for the "Review questions" affordance on the Phase Complete overlay (a Campaign phase win
 * that is not the end of the whole attempt, so save_progress's own questionlog is never
 * reached). Deliberately separate from advance_phase: that call mutates the attempt (rotates
 * the token, moves to the next phase) the instant it succeeds, leaving no safe window for the
 * student to review before the page reloads. This lookup can be called as soon as the Phase
 * Complete overlay opens, with no mutation and no time pressure.
 */
class get_phase_questionlog extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'  => new external_value(PARAM_INT, 'Course module ID'),
            'token' => new external_value(PARAM_ALPHANUM, 'Anti-replay token of the in-progress attempt'),
        ]);
    }

    /**
     * Looks up the log for whichever level/phase the attempt is still on (the phase just
     * won, since advance_phase has not been called yet).
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token of the in-progress attempt.
     * @return array Result matrix.
     */
    public static function execute(int $cmid, string $token): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'  => $cmid,
            'token' => $token,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        $attempt = $DB->get_record('playerpuzzle_attempts', [
            'token'          => $params['token'],
            'playerpuzzleid' => (int) $playerpuzzle->id,
            'userid'         => (int) $USER->id,
            'status'         => 'inprogress',
        ]);
        if (!$attempt) {
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        return [
            'questionlog' => attempt_questions::get_phase_log(
                (int) $attempt->id,
                (int) $attempt->currentlevel,
                (int) $attempt->currentphase
            ),
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionlog' => new external_multiple_structure(
                new external_single_structure([
                    'questiontext'  => new external_value(PARAM_RAW, 'Question text'),
                    'chosenanswer'  => new external_value(PARAM_RAW, 'Answer the student chose'),
                    'correctanswer' => new external_value(PARAM_RAW, 'The correct answer'),
                    'iscorrect'     => new external_value(PARAM_BOOL, 'Whether the chosen answer was correct'),
                ]),
                'The questions answered so far this phase, for the post-game review'
            ),
        ]);
    }
}
