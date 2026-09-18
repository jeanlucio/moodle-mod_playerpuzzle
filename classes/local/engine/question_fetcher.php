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
     * Draws one random approved question id for the instance, or null if none exist. The
     * caller (draw_question.php) is the only thing that ever decides which question is "in
     * play" — the client never chooses, closing the oracle a client-supplied questionid used
     * to open on validate_answer's forwhom=boss path (security audit, Fase 9).
     *
     * @param int $playerpuzzleid The instance id.
     * @return int|null
     */
    public static function draw_random_question_id(int $playerpuzzleid): ?int {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'playerpuzzle_questions',
            'id',
            'playerpuzzleid = :ppid AND approved = 1',
            ['ppid' => $playerpuzzleid]
        );
        if (empty($ids)) {
            return null;
        }

        return (int) $ids[array_rand($ids)];
    }

    /**
     * Returns one question in the frontend (Blind JSON) shape, validated to belong to the
     * given instance and be approved before any of its content is read — never by isolated
     * PK. Returns null both when the question does not belong to the instance and when it no
     * longer exists/is approved, the same "caller cannot tell why" shape get_hint_text() uses.
     *
     * @param int $questionid The question id, already drawn server-side.
     * @param int $playerpuzzleid The instance the question must belong to.
     * @param \context $context The context for formatting the HTML text.
     * @return array|null Formatted question ready to send to the frontend, or null.
     */
    public static function get_single_question(int $questionid, int $playerpuzzleid, \context $context): ?array {
        global $DB;

        $valid = $DB->record_exists('playerpuzzle_questions', [
            'id'             => $questionid,
            'playerpuzzleid' => $playerpuzzleid,
            'approved'       => 1,
        ]);
        if (!$valid) {
            return null;
        }

        return self::format_batch([$questionid], $context)[$questionid] ?? null;
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
        return self::format_with_files($q->questiontext, $q->questiontextformat, $context, 'questiontext', $questionid);
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
        return self::format_with_files($answer->answertext, $answer->answerformat, $context, 'answertext', $answerid);
    }

    /**
     * Returns the formatted hint text for a question, validating it belongs to the given
     * instance before reading it — never by isolated PK. Returns null both when the question
     * does not belong to the instance and when it has no hint, so the caller cannot tell the
     * two apart from the return value alone (buy_consumable.php raises its own error either
     * way, without leaking which case it was).
     *
     * @param int $questionid The question ID.
     * @param int $playerpuzzleid The instance the question must belong to.
     * @param \context $context Context for formatting.
     * @return string|null The formatted hint text, or null if unavailable.
     */
    public static function get_hint_text(int $questionid, int $playerpuzzleid, \context $context): ?string {
        global $DB;

        $hint = $DB->get_field('playerpuzzle_questions', 'hint', [
            'id' => $questionid,
            'playerpuzzleid' => $playerpuzzleid,
            'approved' => 1,
        ]);
        if ($hint === false || $hint === null || trim($hint) === '') {
            return null;
        }

        return format_text($hint, FORMAT_PLAIN, ['context' => $context]);
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
            "SELECT id, qtype, questiontext, questiontextformat, hint FROM {playerpuzzle_questions} WHERE id $insql",
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
                    'text' => self::format_with_files(
                        $ans->answertext,
                        $ans->answerformat,
                        $context,
                        'answertext',
                        (int) $ans->id
                    ),
                ];
            }

            $formatted[(int) $q->id] = [
                'id' => (int) $q->id,
                'type' => $q->qtype,
                'text' => self::format_with_files(
                    $q->questiontext,
                    $q->questiontextformat,
                    $context,
                    'questiontext',
                    (int) $q->id
                ),
                'options' => $options,
                // Whether a hint exists, not its text — the text itself is only ever sent
                // after buy_consumable(type=hint) authorizes the purchase (Blind JSON: the
                // client learns nothing it has not paid for).
                'hashint' => $q->hint !== null && trim($q->hint) !== '',
            ];
        }

        return $formatted;
    }

    /**
     * Resolves @@PLUGINFILE@@ markers to real URLs before formatting — format_text() itself
     * has no notion of a component/filearea/itemid; that resolution is
     * file_rewrite_pluginfile_urls()'s own job, always a separate call before formatting.
     *
     * @param string $text Stored text, with @@PLUGINFILE@@ markers if it embeds any files.
     * @param int $format FORMAT_* constant for $text.
     * @param \context $context Context for filtering.
     * @param string $filearea 'questiontext' or 'answertext'.
     * @param int $itemid The question or answer id the files are attached to.
     * @return string
     */
    private static function format_with_files(
        string $text,
        int $format,
        \context $context,
        string $filearea,
        int $itemid
    ): string {
        $text = file_rewrite_pluginfile_urls($text, 'pluginfile.php', $context->id, 'mod_playerpuzzle', $filearea, $itemid);

        return format_text($text, $format, ['context' => $context]);
    }
}
