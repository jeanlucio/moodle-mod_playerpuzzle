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
 * External function to generate an AI question preview, without saving it.
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
use moodle_exception;

/**
 * Generates a batch of multichoice/truefalse questions via AI for the teacher to review.
 * Never writes to the database — {@see save_generated_questions} is the separate,
 * explicit save step once the teacher approves what to keep.
 */
class generate_questions extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'  => new external_value(PARAM_INT, 'Course module ID'),
            'topic' => new external_value(PARAM_TEXT, 'Subject/theme for the generated questions'),
            'count' => new external_value(PARAM_INT, 'How many questions to request'),
        ]);
    }

    /**
     * Generates the preview batch.
     *
     * @param int $cmid Course module ID.
     * @param string $topic Subject/theme for the generated questions.
     * @param int $count How many questions to request.
     * @return array {questions: array}
     */
    public static function execute(int $cmid, string $topic, int $count): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'  => $cmid,
            'topic' => $topic,
            'count' => $count,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:managequestions', $context);

        $topic = trim($params['topic']);
        if ($topic === '') {
            throw new moodle_exception('error_aitopicrequired', 'mod_playerpuzzle');
        }

        $questions = ai_question_generator::generate($topic, $params['count'], $context);

        return ['questions' => $questions];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'qtype'        => new external_value(PARAM_ALPHA, 'multichoice or truefalse'),
                    'questiontext' => new external_value(PARAM_RAW, 'Question prompt'),
                    'hint'         => new external_value(PARAM_RAW, 'Optional hint text'),
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
}
