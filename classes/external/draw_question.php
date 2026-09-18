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
 * External function to draw (or re-serve) the question currently open for an attempt.
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
use mod_playerpuzzle\local\engine\question_fetcher;
use moodle_exception;

/**
 * Draws the question for a mana-full challenge (player or boss) server-side, replacing the
 * client's own random pick from a full Blind JSON list of every approved question.
 *
 * The client never chooses which question is in play: the server draws it and stores it as
 * the attempt's own currentquestionid, which validate_answer.php then exclusively trusts (a
 * client-supplied questionid is never accepted there any more). This closes the oracle a
 * client-supplied questionid used to open on validate_answer's forwhom=boss path — since the
 * client no longer picks the question, there is nothing left to probe on demand (security
 * audit finding, Fase 9).
 */
class draw_question extends external_api {
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
     * Re-serves the already-open question if one is pending (e.g. a page reload mid-question),
     * or draws a fresh random one otherwise. Only ever advances to a new draw once the current
     * one has actually been consumed by validate_answer — a "draw, peek via forwhom=boss, draw
     * again" loop cannot bulk-harvest the question bank for free, since each draw call while a
     * question is already open just re-serves that same one.
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

        $questionid = (int) $attempt->currentquestionid;
        $current = $questionid > 0
            ? question_fetcher::get_single_question($questionid, (int) $playerpuzzle->id, $context)
            : null;

        if ($current === null) {
            $questionid = question_fetcher::draw_random_question_id((int) $playerpuzzle->id);
            $attempt->currentquestionid = $questionid ?? 0;
            $attempt->timemodified = time();
            $DB->update_record('playerpuzzle_attempts', $attempt);

            $current = $questionid !== null
                ? question_fetcher::get_single_question($questionid, (int) $playerpuzzle->id, $context)
                : null;
        }

        if ($current === null) {
            return [
                'available' => false,
                'id'        => 0,
                'type'      => '',
                'text'      => '',
                'hashint'   => false,
                'options'   => [],
            ];
        }

        return [
            'available' => true,
            'id'        => $current['id'],
            'type'      => $current['type'],
            'text'      => $current['text'],
            'hashint'   => $current['hashint'],
            'options'   => $current['options'],
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'available' => new external_value(PARAM_BOOL, 'Whether a question is available at all'),
            'id'        => new external_value(PARAM_INT, 'Question id (0 when unavailable)'),
            'type'      => new external_value(PARAM_ALPHA, 'Question type: multichoice or truefalse'),
            'text'      => new external_value(PARAM_RAW, 'Formatted question text'),
            'hashint'   => new external_value(PARAM_BOOL, 'Whether a hint exists for this question'),
            'options'   => new external_multiple_structure(
                new external_single_structure([
                    'id'   => new external_value(PARAM_INT, 'Answer id'),
                    'text' => new external_value(PARAM_RAW, 'Formatted answer text'),
                ]),
                'Answer options, shuffled'
            ),
        ]);
    }
}
