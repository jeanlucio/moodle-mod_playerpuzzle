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
 * Class to safely fetch questions from PlayerPuzzle's own question bank (Blind JSON) and to
 * validate answers against it. Questions are only ever read from playerpuzzle_questions —
 * reusing a Moodle question bank category is an import into that table
 * (classes/local/questions_repository.php), not a second read-time source.
 */
class question_fetcher {
    /**
     * Retrieves random approved questions for an instance, without revealing the correct
     * answers.
     *
     * @param int $playerpuzzleid The instance id.
     * @param \context $context The context for formatting the HTML text.
     * @param int $limit How many questions to retrieve.
     * @return array Array of formatted questions ready to be sent to the frontend.
     */
    public static function get_questions_for_frontend(int $playerpuzzleid, \context $context, int $limit = 10): array {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'playerpuzzle_questions',
            'id',
            'playerpuzzleid = :ppid AND approved = 1',
            ['ppid' => $playerpuzzleid]
        );
        if (empty($ids)) {
            return [];
        }

        shuffle($ids);
        $selectedids = array_slice($ids, 0, $limit);

        return array_values(self::format_batch($selectedids, $context));
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

        $iscorrect = $DB->get_field('playerpuzzle_question_answers', 'iscorrect', [
            'id' => $answerid,
            'questionid' => $questionid,
        ]);

        return $iscorrect !== false && (int) $iscorrect === 1;
    }

    /**
     * Returns the formatted question text, or an empty string if the question is gone.
     *
     * @param int $questionid The question ID.
     * @param \context $context Context for formatting.
     * @return string
     */
    public static function get_question_text(int $questionid, \context $context): string {
        global $DB;

        $q = $DB->get_record('playerpuzzle_questions', ['id' => $questionid], 'questiontext, questiontextformat');
        if (!$q) {
            return '';
        }
        return format_text($q->questiontext, $q->questiontextformat, ['context' => $context]);
    }

    /**
     * Returns the formatted text of one answer, or an empty string if it is gone.
     *
     * @param int $answerid The answer ID.
     * @param \context $context Context for formatting.
     * @return string
     */
    public static function get_answer_text(int $answerid, \context $context): string {
        global $DB;

        $answer = $DB->get_record('playerpuzzle_question_answers', ['id' => $answerid], 'answertext, answerformat');
        if (!$answer) {
            return '';
        }
        return format_text($answer->answertext, $answer->answerformat, ['context' => $context]);
    }

    /**
     * Returns the qtype of a question ('multichoice' or 'truefalse'), or null if unknown.
     *
     * @param int $questionid The question ID.
     * @return string|null
     */
    public static function get_question_type(int $questionid): ?string {
        global $DB;

        $qtype = $DB->get_field('playerpuzzle_questions', 'qtype', ['id' => $questionid]);

        return $qtype !== false ? (string) $qtype : null;
    }

    /**
     * Returns every answer ID for a question, in id order. Used server-side to draw the
     * boss's guess without the client ever choosing it (Blind JSON).
     *
     * @param int $questionid The question ID.
     * @return int[]
     */
    public static function get_answer_ids(int $questionid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select(
            'playerpuzzle_question_answers',
            'id',
            'questionid = :qid ORDER BY id ASC',
            ['qid' => $questionid]
        ));
    }

    /**
     * Returns the ID of the correct answer for the question (for post-submission feedback only).
     *
     * @param int $questionid The question ID.
     * @return int|null The answer ID with iscorrect = 1, or null if none found.
     */
    public static function get_correct_answer_id(int $questionid): ?int {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT id FROM {playerpuzzle_question_answers} WHERE questionid = :qid AND iscorrect = 1 ORDER BY id ASC",
            ['qid' => $questionid],
            0,
            1
        );

        return empty($records) ? null : (int) reset($records)->id;
    }

    /**
     * Batch-fetches and formats a set of question ids into the frontend shape.
     *
     * @param int[] $ids Question ids.
     * @param \context $context The context for formatting the HTML text.
     * @return array<int, array> Question id => formatted question.
     */
    private static function format_batch(array $ids, \context $context): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($ids);
        $rawquestions = $DB->get_records_sql(
            "SELECT id, qtype, questiontext, questiontextformat FROM {playerpuzzle_questions} WHERE id $insql",
            $inparams
        );

        $allanswers = $DB->get_records_sql(
            "SELECT id, questionid, answertext, answerformat FROM {playerpuzzle_question_answers} WHERE questionid $insql",
            $inparams
        );
        $groupedanswers = [];
        foreach ($allanswers as $ans) {
            $groupedanswers[$ans->questionid][] = $ans;
        }

        $formatted = [];
        foreach ($rawquestions as $q) {
            $qanswers = $groupedanswers[$q->id] ?? [];
            shuffle($qanswers);

            $options = [];
            foreach ($qanswers as $ans) {
                $options[] = [
                    'id' => (int) $ans->id,
                    'text' => format_text($ans->answertext, $ans->answerformat, ['context' => $context]),
                ];
            }

            $formatted[(int) $q->id] = [
                'id' => (int) $q->id,
                'type' => $q->qtype,
                'text' => format_text($q->questiontext, $q->questiontextformat, ['context' => $context]),
                'options' => $options,
            ];
        }

        return $formatted;
    }
}
