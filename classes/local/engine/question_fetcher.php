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
 * Class to safely fetch questions from either the Moodle Question Bank or PlayerPuzzle's own
 * question bank (Blind JSON), and to validate answers against whichever one a question
 * belongs to.
 *
 * A question id from one bank can collide with an id from the other (both are independent
 * auto-increment sequences), so every method here takes an explicit $bank tag alongside the
 * id — never a bare id lookup that would guess the table. The tag is not the same thing as
 * playerpuzzle_questions.source ('manual'/'ai', who authored the question); it identifies
 * which table the row lives in.
 */
class question_fetcher {
    /** @var string Question lives in the real Moodle question bank (core `question` table). */
    public const BANK_QUESTIONBANK = 'questionbank';

    /** @var string Question lives in PlayerPuzzle's own bank (`playerpuzzle_questions`). */
    public const BANK_OWNBANK = 'ownbank';

    /**
     * Retrieves random questions from whichever source(s) the instance has enabled, without
     * revealing the correct answers. When both sources are on, candidates from each are
     * pooled and shuffled together before the limit is applied, so neither source is favoured.
     *
     * @param \stdClass $playerpuzzle The instance record (reads ->sources, ->questioncategory, ->id).
     * @param \context $context The context for formatting the HTML text.
     * @param int $limit How many questions to retrieve.
     * @return array Array of formatted questions ready to be sent to the frontend, each
     *      carrying its own 'bank' tag to be echoed back on validate_answer.
     */
    public static function get_questions_for_frontend(\stdClass $playerpuzzle, \context $context, int $limit = 10): array {
        global $DB;

        $sources = (int) $playerpuzzle->sources;
        $candidates = [];

        if ($sources & PLAYERPUZZLE_SOURCE_QUESTIONBANK) {
            $sql = "SELECT q.id
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                     WHERE qbe.questioncategoryid = :catid
                       AND q.qtype IN ('multichoice', 'truefalse')";
            foreach ($DB->get_fieldset_sql($sql, ['catid' => (int) $playerpuzzle->questioncategory]) as $id) {
                $candidates[] = ['id' => (int) $id, 'bank' => self::BANK_QUESTIONBANK];
            }
        }

        if ($sources & PLAYERPUZZLE_SOURCE_OWNBANK) {
            $ids = $DB->get_fieldset_select(
                'playerpuzzle_questions',
                'id',
                'playerpuzzleid = :ppid AND approved = 1',
                ['ppid' => (int) $playerpuzzle->id]
            );
            foreach ($ids as $id) {
                $candidates[] = ['id' => (int) $id, 'bank' => self::BANK_OWNBANK];
            }
        }

        if (empty($candidates)) {
            return [];
        }

        shuffle($candidates);
        $selected = array_slice($candidates, 0, $limit);

        $questionbankids = array_values(array_unique(array_column(
            array_filter($selected, static fn(array $c): bool => $c['bank'] === self::BANK_QUESTIONBANK),
            'id'
        )));
        $ownbankids = array_values(array_unique(array_column(
            array_filter($selected, static fn(array $c): bool => $c['bank'] === self::BANK_OWNBANK),
            'id'
        )));

        $formattedbybank = [
            self::BANK_QUESTIONBANK => $questionbankids ? self::format_questionbank_batch($questionbankids, $context) : [],
            self::BANK_OWNBANK => $ownbankids ? self::format_ownbank_batch($ownbankids, $context) : [],
        ];

        $result = [];
        foreach ($selected as $candidate) {
            if (isset($formattedbybank[$candidate['bank']][$candidate['id']])) {
                $result[] = $formattedbybank[$candidate['bank']][$candidate['id']];
            }
        }

        return $result;
    }

    /**
     * Validates if the given answer is correct for the question on the server side.
     *
     * @param string $bank Which bank the question belongs to, one of the BANK_* constants.
     * @param int $questionid The question ID.
     * @param int $answerid The answer ID provided by the student via AJAX.
     * @return bool True if the answer is completely correct.
     */
    public static function is_answer_correct(string $bank, int $questionid, int $answerid): bool {
        global $DB;

        if ($bank === self::BANK_OWNBANK) {
            $iscorrect = $DB->get_field('playerpuzzle_question_answers', 'iscorrect', [
                'id' => $answerid,
                'questionid' => $questionid,
            ]);

            return $iscorrect !== false && (int) $iscorrect === 1;
        }

        $fraction = $DB->get_field('question_answers', 'fraction', [
            'id' => $answerid,
            'question' => $questionid,
        ]);

        return $fraction !== false && (float) $fraction >= 1.0;
    }

    /**
     * Returns the formatted question text, or an empty string if the question is gone.
     *
     * @param string $bank Which bank the question belongs to, one of the BANK_* constants.
     * @param int $questionid The question ID.
     * @param \context $context Context for formatting.
     * @return string
     */
    public static function get_question_text(string $bank, int $questionid, \context $context): string {
        global $DB;

        if ($bank === self::BANK_OWNBANK) {
            $q = $DB->get_record('playerpuzzle_questions', ['id' => $questionid], 'questiontext');
            if (!$q) {
                return '';
            }
            return format_text($q->questiontext, FORMAT_PLAIN, ['context' => $context]);
        }

        $q = $DB->get_record('question', ['id' => $questionid], 'questiontext, questiontextformat');
        if (!$q) {
            return '';
        }
        return format_text($q->questiontext, $q->questiontextformat, ['context' => $context]);
    }

    /**
     * Returns the formatted text of one answer, or an empty string if it is gone.
     *
     * @param string $bank Which bank the answer belongs to, one of the BANK_* constants.
     * @param int $answerid The answer ID.
     * @param \context $context Context for formatting.
     * @return string
     */
    public static function get_answer_text(string $bank, int $answerid, \context $context): string {
        global $DB;

        if ($bank === self::BANK_OWNBANK) {
            $answer = $DB->get_record('playerpuzzle_question_answers', ['id' => $answerid], 'answertext');
            if (!$answer) {
                return '';
            }
            return format_text($answer->answertext, FORMAT_PLAIN, ['context' => $context]);
        }

        $ans = $DB->get_record('question_answers', ['id' => $answerid], 'answer, answerformat');
        if (!$ans) {
            return '';
        }
        return format_text($ans->answer, $ans->answerformat, ['context' => $context]);
    }

    /**
     * Returns the qtype of a question ('multichoice' or 'truefalse'), or null if unknown.
     *
     * @param string $bank Which bank the question belongs to, one of the BANK_* constants.
     * @param int $questionid The question ID.
     * @return string|null
     */
    public static function get_question_type(string $bank, int $questionid): ?string {
        global $DB;

        $table = $bank === self::BANK_OWNBANK ? 'playerpuzzle_questions' : 'question';
        $qtype = $DB->get_field($table, 'qtype', ['id' => $questionid]);

        return $qtype !== false ? (string) $qtype : null;
    }

    /**
     * Returns every answer ID for a question, in id order. Used server-side to draw the
     * boss's guess without the client ever choosing it (Blind JSON).
     *
     * @param string $bank Which bank the question belongs to, one of the BANK_* constants.
     * @param int $questionid The question ID.
     * @return int[]
     */
    public static function get_answer_ids(string $bank, int $questionid): array {
        global $DB;

        if ($bank === self::BANK_OWNBANK) {
            return array_map('intval', $DB->get_fieldset_select(
                'playerpuzzle_question_answers',
                'id',
                'questionid = :qid ORDER BY id ASC',
                ['qid' => $questionid]
            ));
        }

        return array_map('intval', $DB->get_fieldset_select(
            'question_answers',
            'id',
            'question = :qid ORDER BY id ASC',
            ['qid' => $questionid]
        ));
    }

    /**
     * Returns the ID of one correct answer for the question (for post-submission feedback only).
     *
     * @param string $bank Which bank the question belongs to, one of the BANK_* constants.
     * @param int $questionid The question ID.
     * @return int|null The answer ID with fraction >= 1 (or iscorrect = 1 in the own bank),
     *      or null if none found.
     */
    public static function get_correct_answer_id(string $bank, int $questionid): ?int {
        global $DB;

        if ($bank === self::BANK_OWNBANK) {
            $records = $DB->get_records_sql(
                "SELECT id FROM {playerpuzzle_question_answers} WHERE questionid = :qid AND iscorrect = 1 ORDER BY id ASC",
                ['qid' => $questionid],
                0,
                1
            );
            return empty($records) ? null : (int) reset($records)->id;
        }

        $records = $DB->get_records_sql(
            "SELECT id FROM {question_answers} WHERE question = :qid AND fraction >= :minf ORDER BY id ASC",
            ['qid' => $questionid, 'minf' => 1.0],
            0,
            1
        );

        return empty($records) ? null : (int) reset($records)->id;
    }

    /**
     * Batch-fetches and formats a set of question bank ids into the frontend shape, keyed by
     * id for get_questions_for_frontend() to reassemble in its own shuffled order.
     *
     * @param int[] $ids Question ids, all from the core question bank.
     * @param \context $context The context for formatting the HTML text.
     * @return array<int, array> Question id => formatted question.
     */
    private static function format_questionbank_batch(array $ids, \context $context): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($ids);
        $rawquestions = $DB->get_records_sql(
            "SELECT id, qtype, questiontext, questiontextformat FROM {question} WHERE id $insql",
            $inparams
        );

        $allanswers = $DB->get_records_sql(
            "SELECT id, question, answer, answerformat FROM {question_answers} WHERE question $insql",
            $inparams
        );
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
                        'id' => (int) $ans->id,
                        'text' => format_text($ans->answer, $ans->answerformat, ['context' => $context]),
                    ];
                }
            }

            $formatted[(int) $q->id] = [
                'id' => (int) $q->id,
                'bank' => self::BANK_QUESTIONBANK,
                'type' => $q->qtype,
                'text' => format_text($q->questiontext, $q->questiontextformat, ['context' => $context]),
                'options' => $options,
            ];
        }

        return $formatted;
    }

    /**
     * Batch-fetches and formats a set of PlayerPuzzle's own bank ids into the frontend shape,
     * keyed by id for get_questions_for_frontend() to reassemble in its own shuffled order.
     *
     * @param int[] $ids Question ids, all from playerpuzzle_questions.
     * @param \context $context The context for formatting the HTML text.
     * @return array<int, array> Question id => formatted question.
     */
    private static function format_ownbank_batch(array $ids, \context $context): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($ids);
        $rawquestions = $DB->get_records_sql(
            "SELECT id, qtype, questiontext FROM {playerpuzzle_questions} WHERE id $insql",
            $inparams
        );

        $allanswers = $DB->get_records_sql(
            "SELECT id, questionid, answertext FROM {playerpuzzle_question_answers} WHERE questionid $insql",
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
                    'text' => format_text($ans->answertext, FORMAT_PLAIN, ['context' => $context]),
                ];
            }

            $formatted[(int) $q->id] = [
                'id' => (int) $q->id,
                'bank' => self::BANK_OWNBANK,
                'type' => $q->qtype,
                'text' => format_text($q->questiontext, FORMAT_PLAIN, ['context' => $context]),
                'options' => $options,
            ];
        }

        return $formatted;
    }
}
