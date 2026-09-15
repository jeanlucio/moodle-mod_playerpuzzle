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
 * future AI-generated one lands unapproved until a teacher confirms it, Fase 8 Lote C) in
 * one place rather than duplicated between the manual form and the AI pipeline.
 */
class questions_repository {
    /**
     * Valid question types. truefalse questions still store two rows in
     * playerpuzzle_question_answers (one per Verdadeiro/Falso), so question_fetcher.php and
     * validate_answer.php (Fase 8 Lote B) have a single read/validation path for both types.
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
     * @param array $answers List of ['text' => string, 'iscorrect' => bool], in display order.
     * @param int $userid The teacher/manager creating it.
     * @param string $source 'manual' or 'ai'.
     * @param bool $approved Whether the question is immediately usable in games.
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
        bool $approved = true
    ): int {
        global $DB;

        $now = time();
        $questionid = $DB->insert_record('playerpuzzle_questions', (object) [
            'playerpuzzleid' => $playerpuzzleid,
            'qtype' => $qtype,
            'questiontext' => $questiontext,
            'generalfeedback' => null,
            'hint' => $hint !== '' ? $hint : null,
            'source' => $source,
            'approved' => $approved ? 1 : 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'addedby' => $userid,
        ]);

        self::save_answers($questionid, $answers);

        return $questionid;
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
     * Returns a single question with its answers attached as ->answers (ordered by
     * sortorder), or null if it does not exist.
     *
     * @param int $questionid The question id.
     * @return stdClass|null
     */
    public static function get_question(int $questionid): ?stdClass {
        global $DB;

        $question = $DB->get_record('playerpuzzle_questions', ['id' => $questionid]);
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
     * @param array $answers List of ['text' => string, 'iscorrect' => bool], in display order.
     * @return void
     */
    private static function save_answers(int $questionid, array $answers): void {
        global $DB;

        $sortorder = 0;
        foreach ($answers as $answer) {
            $DB->insert_record('playerpuzzle_question_answers', (object) [
                'questionid' => $questionid,
                'answertext' => $answer['text'],
                'iscorrect' => !empty($answer['iscorrect']) ? 1 : 0,
                'sortorder' => $sortorder++,
            ]);
        }
    }
}
