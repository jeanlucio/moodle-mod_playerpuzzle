<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Question fetcher engine for PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');

/**
 * Class to safely fetch questions from the Moodle Question Bank (Blind JSON).
 */
class question_fetcher {
    /**
     * Retrieves random questions from a specific category without revealing the correct answers.
     *
     * @param int $categoryid The question category ID.
     * @param \context $context The context for formatting the HTML text.
     * @param int $limit How many questions to retrieve.
     * @return array Array of formatted questions ready to be sent to the frontend.
     */
    public static function get_questions_for_frontend(int $categoryid, \context $context, int $limit = 10): array {
        global $DB;

        // 1. Fetch only the IDs of matching questions to ensure cross-database compatibility.
        $sql = "SELECT q.id
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = :catid
                   AND q.qtype IN ('multichoice', 'truefalse')";

        $matchingids = $DB->get_fieldset_sql($sql, ['catid' => $categoryid]);

        if (empty($matchingids)) {
            return [];
        }

        // 2. Shuffle the IDs in PHP and pick the required amount.
        shuffle($matchingids);
        $selectedids = array_slice($matchingids, 0, $limit);

        // 3. Fetch the full details of the randomly selected questions.
        // Using the modern short array syntax for destructuring.
        [$insql, $inparams] = $DB->get_in_or_equal($selectedids);
        $fullsql = "SELECT id, qtype, questiontext, questiontextformat
                      FROM {question}
                     WHERE id $insql";

        $rawquestions = $DB->get_records_sql($fullsql, $inparams);

        $formatted = [];

        foreach ($rawquestions as $q) {
            // Load the full question object via core API to safely get the answers.
            $questionobj = \question_bank::load_question($q->id);
            $options = [];

            if ($q->qtype === 'multichoice' || $q->qtype === 'truefalse') {
                $answers = $questionobj->answers;
                $anskeys = array_keys($answers);

                // Shuffle the options so they don't always appear in the same order.
                shuffle($anskeys);

                foreach ($anskeys as $key) {
                    $ans = $answers[$key];
                    $options[] = [
                        'id' => $ans->id,
                        'text' => format_text($ans->answer, $ans->answerformat, ['context' => $context]),
                    ];
                }
            }

            // Pack the question without the "correct_answer" field.
            $formatted[] = [
                'id' => $q->id,
                'type' => $q->qtype,
                'text' => format_text($q->questiontext, $q->questiontextformat, ['context' => $context]),
                'options' => $options,
            ];
        }

        return $formatted;
    }

    /**
     * Validates if the given answer is correct for the question on the server side.
     *
     * @param int $questionid The question ID.
     * @param int $answerid The answer ID provided by the student via AJAX.
     * @return bool True if the answer is completely correct.
     */
    public static function is_answer_correct(int $questionid, int $answerid): bool {
        global $DB;

        // Get the fraction (score) of the chosen answer. 1.0 means 100% correct.
        $fraction = $DB->get_field('question_answers', 'fraction', [
            'id' => $answerid,
            'question' => $questionid,
        ]);

        if ($fraction !== false && (float)$fraction >= 1.0) {
            return true;
        }

        return false;
    }
}
