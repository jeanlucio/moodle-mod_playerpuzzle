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
 * Imports approved multichoice/truefalse questions from the real Moodle question bank into
 * PlayerPuzzle's own question bank.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context_course;
use context_module;
use core_question\local\bank\question_version_status;
use file_storage;
use moodle_exception;
use stdClass;

/**
 * Mirrors mod_playerwords\local\words_repository::sync_glossary_words() — re-executable,
 * idempotent, matches existing bank-sourced rows by a stable id and never touches a
 * manually authored or AI-generated row. Unlike the glossary sync, this one keeps text
 * rich (no content_to_text() flattening) and, unlike it, never hard-deletes an orphan —
 * an attempt's answered-question log may still point at that id.
 *
 * Embedded files (images in questiontext/answertext) are copied verbatim from the source
 * category's context into PlayerPuzzle's own filearea, keyed by the destination question
 * or answer id. The @@PLUGINFILE@@ markers already in the copied text need no rewriting —
 * they resolve against the same filename, now served by playerpuzzle_pluginfile() instead
 * of core's own question file handler.
 */
class question_bank_sync {
    /**
     * Returns the question bank categories the current user may import from for this course
     * module: its course context and parents, plus every sibling activity's module context
     * in the same course — same reusable-question-context rule
     * mod_playervideo\local\question_service::get_reusable_question_contexts() already
     * applies to its own "pull from bank" picker.
     *
     * @param stdClass $cm Course module record for the PlayerPuzzle instance.
     * @return stdClass[] Category records (id, name, contextid), ordered by name.
     */
    public static function get_importable_categories(stdClass $cm): array {
        global $DB;

        $contextids = self::get_reusable_context_ids($cm);
        if (empty($contextids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

        return array_values($DB->get_records_sql(
            "SELECT qc.id, qc.name, qc.contextid
               FROM {question_categories} qc
              WHERE qc.contextid $insql
           ORDER BY qc.name ASC",
            $params
        ));
    }

    /**
     * Imports every approved, single-answer multichoice/truefalse question from the given
     * category into the instance's own question bank.
     *
     * Re-executable: a bank-sourced row already imported from the same question_bank_entries
     * id is updated in place (text/qtype/answers refreshed, hint/approved/addedby left
     * alone); a new entry is inserted approved; an entry that disappeared from the category
     * since the last sync (deleted, moved, or no longer version 'ready') is soft-disabled
     * (approved = 0), never deleted. A manual or AI-sourced question is never touched.
     *
     * @param stdClass $cm Course module record for the PlayerPuzzle instance.
     * @param int $playerpuzzleid The instance id.
     * @param int $categoryid The question bank category id to import from.
     * @return stdClass Stats: {imported: int, updated: int, skipped: int, disabled: int}.
     * @throws moodle_exception When the category is not reachable from this course module.
     */
    public static function sync_from_category(stdClass $cm, int $playerpuzzleid, int $categoryid): stdClass {
        global $DB;

        if (!self::category_is_importable($categoryid, $cm)) {
            throw new moodle_exception('error_categorynotreusable', 'mod_playerpuzzle');
        }

        $sourcecontextid = (int) $DB->get_field('question_categories', 'contextid', ['id' => $categoryid], MUST_EXIST);
        $destcontext = context_module::instance($cm->id);
        $fs = get_file_storage();

        $rows = $DB->get_records_sql(
            "SELECT q.id AS questionid, q.qtype, q.questiontext, q.questiontextformat, qbe.id AS entryid
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE q.parent = 0
                AND qbe.questioncategoryid = :categoryid
                AND q.qtype IN ('multichoice', 'truefalse')
                AND qv.version = (SELECT MAX(v2.version)
                                     FROM {question_versions} v2
                                    WHERE v2.questionbankentryid = qbe.id AND v2.status = :ready)",
            ['categoryid' => $categoryid, 'ready' => question_version_status::QUESTION_STATUS_READY]
        );

        $existingrows = $DB->get_records_select(
            'playerpuzzle_questions',
            'playerpuzzleid = :ppid AND source = :src',
            ['ppid' => $playerpuzzleid, 'src' => 'bank'],
            '',
            'id, sourceid, approved'
        );
        $existingmap = [];
        foreach ($existingrows as $rec) {
            $existingmap[(int) $rec->sourceid] = $rec;
        }

        [$multiplesingle, $answersbysourceid] = self::preload_source_answers($rows);

        $stats = (object) ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'disabled' => 0];
        $seenentryids = [];

        foreach ($rows as $row) {
            $entryid = (int) $row->entryid;
            $answers = self::load_answers(
                (int) $row->questionid,
                $row->qtype,
                $multiplesingle,
                $answersbysourceid
            );
            if ($answers === null) {
                $stats->skipped++;
                continue;
            }

            $seenentryids[$entryid] = true;

            if (isset($existingmap[$entryid])) {
                $existing = $existingmap[$entryid];
                $questionid = (int) $existing->id;
                questions_repository::update_question_content(
                    $questionid,
                    $row->qtype,
                    $row->questiontext,
                    (int) $row->questiontextformat,
                    $answers,
                    $destcontext
                );
                if ((int) $existing->approved === 0) {
                    questions_repository::set_approved($questionid, true);
                }
                $stats->updated++;
            } else {
                $questionid = questions_repository::add_question(
                    $playerpuzzleid,
                    $row->qtype,
                    $row->questiontext,
                    '',
                    $answers,
                    0,
                    'bank',
                    true,
                    (int) $row->questiontextformat,
                    $entryid
                );
                $stats->imported++;
            }

            self::copy_area_files(
                $fs,
                $sourcecontextid,
                'questiontext',
                (int) $row->questionid,
                $destcontext->id,
                'questiontext',
                $questionid
            );

            // A direct answers-only read, never questions_repository::get_question(): that
            // helper also re-fetches the parent question row, which sync_from_category()
            // already has in $row/$questionid — a wasted query repeated once per imported
            // question.
            $destanswers = array_values(
                $DB->get_records('playerpuzzle_question_answers', ['questionid' => $questionid], 'sortorder ASC')
            );
            foreach ($destanswers as $index => $destanswer) {
                $sourceanswerid = $answers[$index]['sourceanswerid'] ?? null;
                if ($sourceanswerid === null) {
                    continue;
                }
                self::copy_area_files(
                    $fs,
                    $sourcecontextid,
                    'answer',
                    $sourceanswerid,
                    $destcontext->id,
                    'answertext',
                    (int) $destanswer->id
                );
            }
        }

        foreach ($existingmap as $entryid => $existing) {
            if (!isset($seenentryids[$entryid]) && (int) $existing->approved === 1) {
                questions_repository::set_approved((int) $existing->id, false);
                $stats->disabled++;
            }
        }

        return $stats;
    }

    /**
     * Checks whether the given category sits in a context the current user may import
     * questions from, for this course module — the same coarse gate
     * mod_playervideo\local\question_service::question_belongs_to_reusable_category()
     * applies to a single question id, applied here to a whole category instead.
     *
     * @param int $categoryid The question bank category id.
     * @param stdClass $cm Course module record for the PlayerPuzzle instance.
     * @return bool
     */
    public static function category_is_importable(int $categoryid, stdClass $cm): bool {
        global $DB;

        $contextids = self::get_reusable_context_ids($cm);
        if (empty($contextids)) {
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $params['categoryid'] = $categoryid;

        return $DB->record_exists_sql(
            "SELECT 1 FROM {question_categories} qc WHERE qc.id = :categoryid AND qc.contextid $insql",
            $params
        );
    }

    /**
     * Returns every context id the current user may import a question bank category from:
     * the course context and its parents, plus every sibling activity's module context in
     * the same course, restricted to contexts where the user holds moodle/question:useall
     * or moodle/question:usemine.
     *
     * @param stdClass $cm Course module record for the PlayerPuzzle instance.
     * @return int[] Valid context ids, empty if the user may not import from anywhere here.
     */
    private static function get_reusable_context_ids(stdClass $cm): array {
        $coursecontext = context_course::instance($cm->course);
        $contextstocheck = [];
        foreach ($coursecontext->get_parent_contexts(true) as $ctx) {
            $contextstocheck[$ctx->id] = $ctx;
        }

        $modinfo = get_fast_modinfo($cm->course);
        foreach ($modinfo->cms as $othercm) {
            $othercontext = context_module::instance($othercm->id);
            $contextstocheck[$othercontext->id] = $othercontext;
        }

        $contextids = [];
        foreach ($contextstocheck as $ctx) {
            if (has_capability('moodle/question:useall', $ctx) || has_capability('moodle/question:usemine', $ctx)) {
                $contextids[] = $ctx->id;
            }
        }

        return $contextids;
    }

    /**
     * Bulk-loads, for every source question being synced in this run, the data
     * load_answers() would otherwise fetch one question at a time: each multichoice
     * question's qtype_multichoice_options.single flag, and every question's answer rows.
     * Importing a large category one query per question per table risked exceeding
     * max_execution_time; this turns the whole category into two queries regardless of size.
     *
     * @param stdClass[] $rows Source question rows from sync_from_category()'s own query,
     *  each with ->questionid and ->qtype.
     * @return array{0: array<int, int>, 1: array<int, stdClass[]>} [multichoice questionid =>
     *  single flag; questionid => answer rows in id order].
     */
    private static function preload_source_answers(array $rows): array {
        global $DB;

        if (empty($rows)) {
            return [[], []];
        }

        $allids = array_map(fn(stdClass $row): int => (int) $row->questionid, $rows);
        $multichoiceids = array_map(
            fn(stdClass $row): int => (int) $row->questionid,
            array_filter($rows, fn(stdClass $row): bool => $row->qtype === 'multichoice')
        );

        $multiplesingle = [];
        if (!empty($multichoiceids)) {
            $options = $DB->get_records_list(
                'qtype_multichoice_options',
                'questionid',
                $multichoiceids,
                '',
                'questionid, single'
            );
            foreach ($options as $option) {
                $multiplesingle[(int) $option->questionid] = (int) $option->single;
            }
        }

        $answersbyquestionid = array_fill_keys($allids, []);
        $answerrows = $DB->get_records_list('question_answers', 'question', $allids, 'question ASC, id ASC');
        foreach ($answerrows as $answerrow) {
            $answersbyquestionid[(int) $answerrow->question][] = $answerrow;
        }

        return [$multiplesingle, $answersbyquestionid];
    }

    /**
     * Builds the answer list for one bank question, in a shape ready for
     * questions_repository, from data preload_source_answers() already fetched for the whole
     * category. Returns null when the question must be skipped: a multichoice question
     * configured to accept more than one correct answer
     * (qtype_multichoice_options.single = 0) — the game only knows how to grade a single
     * correct choice — or a question with no answer rows at all.
     *
     * Each entry also carries 'sourceanswerid' (the bank's own question_answers.id) —
     * questions_repository ignores the unknown key when writing the row, and
     * sync_from_category() reads it back afterwards to know which bank answer id to copy
     * embedded files from, matched positionally against the freshly written destination
     * answers (both sides are produced in the same, stable id order).
     *
     * @param int $questionid The bank question id.
     * @param string $qtype The question's qtype.
     * @param array $multiplesingle Multichoice questionid => single flag, from
     *  preload_source_answers().
     * @param array $answersbyquestionid Questionid => answer rows, from
     *  preload_source_answers().
     * @return array|null List of ['text' => string, 'format' => int, 'iscorrect' => bool,
     *  'sourceanswerid' => int].
     */
    private static function load_answers(
        int $questionid,
        string $qtype,
        array $multiplesingle,
        array $answersbyquestionid
    ): ?array {
        if ($qtype === 'multichoice' && ($multiplesingle[$questionid] ?? 1) === 0) {
            return null;
        }

        $rows = $answersbyquestionid[$questionid] ?? [];
        if (empty($rows)) {
            return null;
        }

        $answers = [];
        foreach ($rows as $row) {
            $answers[] = [
                'text'      => $row->answer,
                'format'    => (int) $row->answerformat,
                // A tolerance below the exact 1.0 comparison guards against float rounding
                // on a fraction column that stores values like 0.3333333 for partial-credit
                // options never fully at 1.0 — same convention question_state's own graded
                // state resolution uses.
                'iscorrect' => (float) $row->fraction >= 0.999999,
                'sourceanswerid' => (int) $row->id,
            ];
        }

        return $answers;
    }

    /**
     * Copies every file in one question/answer's source file area into PlayerPuzzle's own
     * filearea for the destination question/answer, purging whatever was there first — a
     * plain create would fatal on re-sync, since the destination item id is stable across
     * runs (a freshly recreated answer row aside) and would otherwise collide with the
     * files copied by an earlier sync of the same entry.
     *
     * @param file_storage $fs File storage instance.
     * @param int $sourcecontextid Context id the source question/category lives in.
     * @param string $sourcefilearea 'questiontext' or 'answer' (core's own question fileareas).
     * @param int $sourceitemid The bank question or answer id.
     * @param int $destcontextid This instance's module context id.
     * @param string $destfilearea 'questiontext' or 'answertext' (this plugin's own fileareas).
     * @param int $destitemid The destination playerpuzzle question or answer id.
     * @return void
     */
    private static function copy_area_files(
        file_storage $fs,
        int $sourcecontextid,
        string $sourcefilearea,
        int $sourceitemid,
        int $destcontextid,
        string $destfilearea,
        int $destitemid
    ): void {
        $fs->delete_area_files($destcontextid, 'mod_playerpuzzle', $destfilearea, $destitemid);

        $sourcefiles = $fs->get_area_files($sourcecontextid, 'question', $sourcefilearea, $sourceitemid, 'sortorder', false);
        foreach ($sourcefiles as $file) {
            $fs->create_file_from_storedfile([
                'contextid' => $destcontextid,
                'component' => 'mod_playerpuzzle',
                'filearea'  => $destfilearea,
                'itemid'    => $destitemid,
            ], $file);
        }
    }
}
