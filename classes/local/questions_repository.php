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
 * CRUD for PlayerPuzzle's own question bank (playerpuzzle_questions/_question_answers).
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use stdClass;

/**
 * Mirrors mod_playerwords\local\words_repository — a single-table CRUD for one game's own
 * content bank, keeping the source/approved bookkeeping (manual entries land approved; a
 * future AI-generated one lands unapproved until a teacher confirms it) in one place rather
 * than duplicated between the manual form and the AI pipeline.
 */
class questions_repository {
    /**
     * Valid question types. truefalse questions still store two rows in
     * playerpuzzle_question_answers (one per Verdadeiro/Falso), so question_fetcher.php and
     * validate_answer.php can have a single read/validation path for both types.
     */
    public const QTYPES = ['multichoice', 'truefalse'];

    /**
     * Creates a question with its answers in one transaction-free pair of inserts (answers
     * always follow their parent's id, never orphaned by a mid-write failure at this scale).
     *
     * @param int $playerpuzzleid The instance id.
     * @param string $qtype One of self::QTYPES.
     * @param string $questiontext The question prompt.
     * @param string $hint Optional hint text; empty string stored as null.
     * @param array $answers List of ['text' => string, 'iscorrect' => bool, 'format' => int],
     *  in display order. 'format' defaults to FORMAT_PLAIN when omitted.
     * @param int $userid The teacher/manager creating it, or who triggered the sync/AI run.
     * @param string $source 'manual', 'ai' or 'bank'.
     * @param bool $approved Whether the question is immediately usable in games.
     * @param int $questiontextformat FORMAT_* constant for questiontext.
     * @param int|null $sourceid When source='bank': the question_bank_entries.id it was
     *  imported from. Null otherwise.
     * @return int The new question id.
     */
    public static function add_question(
        int $playerpuzzleid,
        string $qtype,
        string $questiontext,
        string $hint,
        array $answers,
        int $userid,
        string $source = 'manual',
        bool $approved = true,
        int $questiontextformat = FORMAT_PLAIN,
        ?int $sourceid = null
    ): int {
        global $DB;

        $now = time();
        $questionid = $DB->insert_record('playerpuzzle_questions', (object) [
            'playerpuzzleid' => $playerpuzzleid,
            'qtype' => $qtype,
            'questiontext' => $questiontext,
            'questiontextformat' => $questiontextformat,
            'generalfeedback' => null,
            'hint' => $hint !== '' ? $hint : null,
            'source' => $source,
            'sourceid' => $sourceid,
            'approved' => $approved ? 1 : 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'addedby' => $userid,
        ]);

        self::save_answers($questionid, $answers);

        return $questionid;
    }

    /**
     * Replaces a bank-imported question's qtype/text/answers only, leaving hint, approved,
     * source, sourceid and addedby untouched — used only by the question bank sync to
     * refresh an already-imported question's content on re-sync. The manual edit form must
     * keep using {@see update_question()} instead, which also lets the teacher change the
     * hint.
     *
     * @param int $questionid The question id.
     * @param string $qtype One of self::QTYPES.
     * @param string $questiontext The question prompt.
     * @param int $questiontextformat FORMAT_* constant for questiontext.
     * @param array $answers List of ['text' => string, 'iscorrect' => bool, 'format' => int],
     *  in display order.
     * @return void
     */
    public static function update_question_content(
        int $questionid,
        string $qtype,
        string $questiontext,
        int $questiontextformat,
        array $answers
    ): void {
        global $DB;

        $DB->update_record('playerpuzzle_questions', (object) [
            'id' => $questionid,
            'qtype' => $qtype,
            'questiontext' => $questiontext,
            'questiontextformat' => $questiontextformat,
            'timemodified' => time(),
        ]);

        $DB->delete_records('playerpuzzle_question_answers', ['questionid' => $questionid]);
        self::save_answers($questionid, $answers);
    }

    /**
     * Flips a question's approved flag without touching anything else — used by the bank
     * sync to soft-disable a question whose bank entry has since disappeared (deleted,
     * moved to a different category, or no longer version 'ready'), and to re-enable one
     * that reappears on a later sync. Never a hard delete: an attempt's answered-question
     * log may still point at this id.
     *
     * @param int $questionid The question id.
     * @param bool $approved The new approved state.
     * @return void
     */
    public static function set_approved(int $questionid, bool $approved): void {
        global $DB;

        $DB->set_field('playerpuzzle_questions', 'approved', $approved ? 1 : 0, ['id' => $questionid]);
    }

    /**
     * Updates a question's own fields and replaces its answer rows. Never touches
     * source/approved/addedby — editing content is not the same action as re-authoring it.
     *
     * @param int $questionid The question id.
     * @param string $qtype One of self::QTYPES.
     * @param string $questiontext The question prompt.
     * @param string $hint Optional hint text; empty string stored as null.
     * @param array $answers List of ['text' => string, 'iscorrect' => bool], in display order.
     * @return void
     */
    public static function update_question(
        int $questionid,
        string $qtype,
        string $questiontext,
        string $hint,
        array $answers
    ): void {
        global $DB;

        $DB->update_record('playerpuzzle_questions', (object) [
            'id' => $questionid,
            'qtype' => $qtype,
            'questiontext' => $questiontext,
            'hint' => $hint !== '' ? $hint : null,
            'timemodified' => time(),
        ]);

        $DB->delete_records('playerpuzzle_question_answers', ['questionid' => $questionid]);
        self::save_answers($questionid, $answers);
    }

    /**
     * Deletes a question and its answers.
     *
     * @param int $questionid The question id.
     * @return void
     */
    public static function delete_question(int $questionid): void {
        global $DB;

        $DB->delete_records('playerpuzzle_question_answers', ['questionid' => $questionid]);
        $DB->delete_records('playerpuzzle_questions', ['id' => $questionid]);
    }

    /**
     * Returns a single question owned by the given instance, with its answers attached as
     * ->answers (ordered by sortorder), or null if it does not exist or belongs to a
     * different instance.
     *
     * The ownership check is baked into this same lookup — never a separate "load, then
     * check the caller got the right owner" step — so a foreign id never reaches the
     * answers query at all.
     *
     * @param int $questionid The question id.
     * @param int $playerpuzzleid The instance the question must belong to.
     * @return stdClass|null
     */
    public static function get_question(int $questionid, int $playerpuzzleid): ?stdClass {
        global $DB;

        $question = $DB->get_record('playerpuzzle_questions', [
            'id' => $questionid,
            'playerpuzzleid' => $playerpuzzleid,
        ]);
        if (!$question) {
            return null;
        }

        $question->answers = array_values(
            $DB->get_records('playerpuzzle_question_answers', ['questionid' => $questionid], 'sortorder ASC')
        );

        return $question;
    }

    /**
     * Returns every question belonging to an instance, regardless of source/approval status
     * — the management listing shows all of them, with their own status badges. Ordered by
     * most recently created first, so a freshly added or AI-generated batch surfaces at the
     * top rather than requiring a scroll.
     *
     * @param int $playerpuzzleid The instance id.
     * @return stdClass[] Each with ->answers attached, same shape as get_question().
     */
    public static function get_questions_for_instance(int $playerpuzzleid): array {
        global $DB;

        $questions = $DB->get_records(
            'playerpuzzle_questions',
            ['playerpuzzleid' => $playerpuzzleid],
            'timecreated DESC, id DESC'
        );
        if (!$questions) {
            return [];
        }

        $answersbyquestion = [];
        $questionids = array_keys($questions);
        [$insql, $params] = $DB->get_in_or_equal($questionids);
        $allanswers = $DB->get_records_select(
            'playerpuzzle_question_answers',
            "questionid $insql",
            $params,
            'questionid ASC, sortorder ASC'
        );
        foreach ($allanswers as $answer) {
            $answersbyquestion[$answer->questionid][] = $answer;
        }

        foreach ($questions as $question) {
            $question->answers = $answersbyquestion[$question->id] ?? [];
        }

        return array_values($questions);
    }

    /**
     * Inserts the answer rows for a question, in the given order.
     *
     * @param int $questionid The question id.
     * @param array $answers List of ['text' => string, 'iscorrect' => bool, 'format' => int],
     *  in display order. 'format' defaults to FORMAT_PLAIN when omitted.
     * @return void
     */
    private static function save_answers(int $questionid, array $answers): void {
        global $DB;

        $sortorder = 0;
        foreach ($answers as $answer) {
            $DB->insert_record('playerpuzzle_question_answers', (object) [
                'questionid' => $questionid,
                'answertext' => $answer['text'],
                'answerformat' => $answer['format'] ?? FORMAT_PLAIN,
                'iscorrect' => !empty($answer['iscorrect']) ? 1 : 0,
                'sortorder' => $sortorder++,
            ]);
        }
    }
}
