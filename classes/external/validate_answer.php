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
 * External function to validate a player answer during combat.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_playerpuzzle\local\engine\question_fetcher;

/**
 * Validates whether the answer submitted by the player is correct.
 *
 * Also verifies that the question belongs to the category configured for this
 * activity instance, preventing cross-instance data access.
 */
class validate_answer extends external_api {

    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'       => new external_value(PARAM_INT, 'Course module ID'),
            'questionid' => new external_value(PARAM_INT, 'Question ID'),
            'answerid'   => new external_value(PARAM_INT, 'Answer ID submitted by the player'),
        ]);
    }

    /**
     * Validates the answer and returns correctness with an optional feedback answer ID.
     *
     * @param int $cmid Course module ID.
     * @param int $questionid Question ID.
     * @param int $answerid Answer ID submitted.
     * @return array Result with correct flag and optional correct answer ID.
     */
    public static function execute(int $cmid, int $questionid, int $answerid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'questionid' => $questionid,
            'answerid'   => $answerid,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        // Verify the question belongs to the category configured for this instance.
        $valid = $DB->record_exists_sql(
            "SELECT 1
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
              WHERE qv.questionid = :qid
                AND qbe.questioncategoryid = :catid",
            ['qid' => $params['questionid'], 'catid' => $playerpuzzle->questioncategory]
        );

        $correct = $valid && question_fetcher::is_answer_correct(
            $params['questionid'],
            $params['answerid']
        );

        $result = ['correct' => $correct];
        if (!$correct) {
            $correctanswerid = question_fetcher::get_correct_answer_id($params['questionid']);
            if ($correctanswerid !== null) {
                $result['correctanswerid'] = $correctanswerid;
            }
        }

        return $result;
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'correct'        => new external_value(PARAM_BOOL, 'Whether the answer is correct'),
            'correctanswerid' => new external_value(
                PARAM_INT,
                'Correct answer ID for post-answer feedback',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
