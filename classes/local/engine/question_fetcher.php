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
 * Question fetcher engine for PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

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

        // Fetch only IDs first to ensure cross-database compatibility.
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

        shuffle($matchingids);
        $selectedids = array_slice($matchingids, 0, $limit);

        [$insql, $inparams] = $DB->get_in_or_equal($selectedids);
        $fullsql = "SELECT id, qtype, questiontext, questiontextformat
                      FROM {question}
                     WHERE id $insql";

        $rawquestions = $DB->get_records_sql($fullsql, $inparams);

        $answersql = "SELECT id, question, answer, answerformat
                        FROM {question_answers}
                       WHERE question $insql";
        $allanswers = $DB->get_records_sql($answersql, $inparams);

        $groupedanswers = [];
        foreach ($allanswers as $ans) {
            $groupedanswers[$ans->question][] = $ans;
        }

        $formatted = [];

        foreach ($rawquestions as $q) {
            $options = [];

            if (($q->qtype === 'multichoice' || $q->qtype === 'truefalse') && isset($groupedanswers[$q->id])) {
                $qanswers = $groupedanswers[$q->id];
                shuffle($qanswers);

                foreach ($qanswers as $ans) {
                    $options[] = [
                        'id' => $ans->id,
                        'text' => format_text($ans->answer, $ans->answerformat, ['context' => $context]),
                    ];
                }
            }

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

        $fraction = $DB->get_field('question_answers', 'fraction', [
            'id' => $answerid,
            'question' => $questionid,
        ]);

        return $fraction !== false && (float)$fraction >= 1.0;
    }
}
