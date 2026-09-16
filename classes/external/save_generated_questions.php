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
 * External function to save the AI-generated questions a teacher confirmed.
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
use mod_playerpuzzle\local\ai_question_generator;
use mod_playerpuzzle\local\questions_repository;

/**
 * Persists the subset of an AI-generated preview batch the teacher chose to keep.
 *
 * Every question is re-validated here exactly as strictly as at generation time — the
 * client only ever holds a preview it may have edited, never a trusted source of truth.
 * Saved rows land with source='ai', approved=0: confirming the preview is not the same
 * action as approving the content for actual gameplay, a separate step on the question
 * list (R7 of the unified question-bank plan).
 */
class save_generated_questions extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'      => new external_value(PARAM_INT, 'Course module ID'),
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'qtype'        => new external_value(PARAM_ALPHA, 'multichoice or truefalse'),
                    'questiontext' => new external_value(PARAM_RAW, 'Question prompt'),
                    'hint'         => new external_value(PARAM_RAW, 'Optional hint text', VALUE_DEFAULT, ''),
                    'answers'      => new external_multiple_structure(
                        new external_single_structure([
                            'text'      => new external_value(PARAM_RAW, 'Answer text'),
                            'iscorrect' => new external_value(PARAM_BOOL, 'Whether this answer is correct'),
                        ])
                    ),
                ])
            ),
        ]);
    }

    /**
     * Saves the confirmed questions as pending approval.
     *
     * @param int $cmid Course module ID.
     * @param array $questions Teacher-confirmed question batch.
     * @return array {saved: int, skipped: int}
     */
    public static function execute(int $cmid, array $questions): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'questions' => $questions,
        ]);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:managequestions', $context);

        $saved = 0;
        $skipped = 0;
        foreach ($params['questions'] as $question) {
            $shaped = ai_question_generator::validate_shaped_question($question);
            if ($shaped === null) {
                $skipped++;
                continue;
            }

            questions_repository::add_question(
                (int) $cm->instance,
                $shaped['qtype'],
                $shaped['questiontext'],
                $shaped['hint'],
                $shaped['answers'],
                (int) $USER->id,
                'ai',
                false
            );
            $saved++;
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'saved'   => new external_value(PARAM_INT, 'Number of questions saved as pending approval'),
            'skipped' => new external_value(PARAM_INT, 'Number of malformed questions skipped'),
        ]);
    }
}
