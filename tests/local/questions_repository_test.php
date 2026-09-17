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
 * Unit tests for PlayerPuzzle's own question bank CRUD.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for questions_repository.
 *
 * @covers \mod_playerpuzzle\local\questions_repository
 */
final class questions_repository_test extends \advanced_testcase {
    /**
     * Tests that a multichoice question is created with its answers in sortorder,
     * and that source/approved default correctly for a manual entry.
     *
     * @return void
     */
    public function test_add_question_creates_multichoice_with_answers(): void {
        $this->resetAfterTest();

        $answers = [
            ['text' => 'Paris', 'iscorrect' => true],
            ['text' => 'Lyon', 'iscorrect' => false],
            ['text' => 'Marseille', 'iscorrect' => false],
        ];

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Capital of France?',
            'Think Eiffel Tower.',
            $answers,
            42
        );

        $question = questions_repository::get_question($questionid, 7);

        $this->assertNotNull($question);
        $this->assertSame(7, (int) $question->playerpuzzleid);
        $this->assertSame('multichoice', $question->qtype);
        $this->assertSame('Capital of France?', $question->questiontext);
        $this->assertSame('Think Eiffel Tower.', $question->hint);
        $this->assertSame('manual', $question->source);
        $this->assertSame(1, (int) $question->approved);
        $this->assertSame(42, (int) $question->addedby);

        $this->assertCount(3, $question->answers);
        $this->assertSame('Paris', $question->answers[0]->answertext);
        $this->assertSame(1, (int) $question->answers[0]->iscorrect);
        $this->assertSame('Lyon', $question->answers[1]->answertext);
        $this->assertSame(0, (int) $question->answers[1]->iscorrect);
    }

    /**
     * Tests that an empty hint is stored as null rather than an empty string.
     *
     * @return void
     */
    public function test_add_question_stores_empty_hint_as_null(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'truefalse',
            'The sky is blue.',
            '',
            [
                ['text' => get_string('true', 'qtype_truefalse'), 'iscorrect' => true],
                ['text' => get_string('false', 'qtype_truefalse'), 'iscorrect' => false],
            ],
            42
        );

        $question = questions_repository::get_question($questionid, 7);

        $this->assertNull($question->hint);
    }

    /**
     * Tests that an unapproved AI-sourced question is created with the right flags.
     *
     * @return void
     */
    public function test_add_question_supports_unapproved_ai_source(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Generated question?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42,
            'ai',
            false
        );

        $question = questions_repository::get_question($questionid, 7);

        $this->assertSame('ai', $question->source);
        $this->assertSame(0, (int) $question->approved);
    }

    /**
     * Tests that update_question() replaces the question text and answer set, while
     * leaving source/approved/addedby untouched — editing content is not re-authoring it.
     *
     * @return void
     */
    public function test_update_question_replaces_text_and_answers_without_touching_provenance(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Original text',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42,
            'ai',
            false
        );

        questions_repository::update_question(
            $questionid,
            'multichoice',
            'Updated text',
            'New hint',
            [
                ['text' => 'X', 'iscorrect' => false],
                ['text' => 'Y', 'iscorrect' => true],
            ]
        );

        $question = questions_repository::get_question($questionid, 7);

        $this->assertSame('Updated text', $question->questiontext);
        $this->assertSame('New hint', $question->hint);
        $this->assertCount(2, $question->answers);
        $this->assertSame('X', $question->answers[0]->answertext);
        $this->assertSame('Y', $question->answers[1]->answertext);
        $this->assertSame(1, (int) $question->answers[1]->iscorrect);

        // Provenance fields are untouched by an edit.
        $this->assertSame('ai', $question->source);
        $this->assertSame(0, (int) $question->approved);
        $this->assertSame(42, (int) $question->addedby);
    }

    /**
     * Tests that update_question_content() replaces qtype/text/format/answers while leaving
     * hint/source/sourceid/approved/addedby untouched — the bank sync's own update path,
     * distinct from update_question() which the manual edit form uses instead.
     *
     * @return void
     */
    public function test_update_question_content_replaces_content_without_touching_provenance(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Original from bank',
            'A hint the teacher added after import',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            0,
            'bank',
            true,
            FORMAT_PLAIN,
            123
        );

        questions_repository::update_question_content(
            $questionid,
            'multichoice',
            '<p>Refreshed from bank</p>',
            FORMAT_HTML,
            [
                ['text' => 'X', 'iscorrect' => false, 'format' => FORMAT_HTML],
                ['text' => 'Y', 'iscorrect' => true, 'format' => FORMAT_HTML],
            ]
        );

        $question = questions_repository::get_question($questionid, 7);

        $this->assertSame('<p>Refreshed from bank</p>', $question->questiontext);
        $this->assertSame((int) FORMAT_HTML, (int) $question->questiontextformat);
        $this->assertCount(2, $question->answers);
        $this->assertSame('X', $question->answers[0]->answertext);
        $this->assertSame((int) FORMAT_HTML, (int) $question->answers[0]->answerformat);
        $this->assertSame(1, (int) $question->answers[1]->iscorrect);

        // Provenance and the teacher's own hint survive a content refresh.
        $this->assertSame('A hint the teacher added after import', $question->hint);
        $this->assertSame('bank', $question->source);
        $this->assertSame(123, (int) $question->sourceid);
        $this->assertSame(1, (int) $question->approved);
    }

    /**
     * Tests that set_approved() flips only the approved flag.
     *
     * @return void
     */
    public function test_set_approved_flips_only_that_flag(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Q',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        questions_repository::set_approved($questionid, false);
        $this->assertSame(0, (int) questions_repository::get_question($questionid, 7)->approved);

        questions_repository::set_approved($questionid, true);
        $this->assertSame(1, (int) questions_repository::get_question($questionid, 7)->approved);
    }

    /**
     * Tests that approve_question() — the management screen's own action, distinct from
     * set_approved() which the bank sync calls internally — approves a disabled bank
     * question and also clears its sourceid, detaching it from future sync runs.
     *
     * @return void
     */
    public function test_approve_question_approves_and_detaches_a_bank_question(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Orphaned bank question',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            0,
            'bank',
            false,
            FORMAT_PLAIN,
            123
        );

        questions_repository::approve_question($questionid);

        $question = questions_repository::get_question($questionid, 7);
        $this->assertSame(1, (int) $question->approved);
        $this->assertNull($question->sourceid);
    }

    /**
     * Tests that approve_question() is a harmless no-op on the sourceid front for an
     * AI-generated question, which never had one to begin with.
     *
     * @return void
     */
    public function test_approve_question_is_safe_on_an_ai_question_without_sourceid(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Generated question',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42,
            'ai',
            false
        );

        questions_repository::approve_question($questionid);

        $question = questions_repository::get_question($questionid, 7);
        $this->assertSame(1, (int) $question->approved);
        $this->assertNull($question->sourceid);
    }

    /**
     * Tests that delete_question() removes both the question and its answer rows.
     *
     * @return void
     */
    public function test_delete_question_removes_question_and_answers(): void {
        global $DB;

        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'To be deleted',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );

        questions_repository::delete_question($questionid);

        $this->assertNull(questions_repository::get_question($questionid, 7));
        $this->assertSame(0, $DB->count_records('playerpuzzle_question_answers', ['questionid' => $questionid]));
    }

    /**
     * Creates a real course module context for the file-purge tests below, which need one
     * to call the file storage API with — the rest of this file's tests use a fabricated
     * playerpuzzleid and never touch files.
     *
     * @return \context_module
     */
    private function make_real_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        return \context_module::instance($cm->id);
    }

    /**
     * Tests that delete_question(), given a context, purges both the question's own
     * questiontext file area and each answer's answertext file area.
     *
     * @return void
     */
    public function test_delete_question_purges_file_areas_when_context_given(): void {
        $this->resetAfterTest();
        $context = $this->make_real_context();
        $fs = get_file_storage();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Q',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );
        $answerid = (int) questions_repository::get_question($questionid, 7)->answers[0]->id;

        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_playerpuzzle', 'filearea' => 'questiontext',
            'itemid' => $questionid, 'filepath' => '/', 'filename' => 'q.png',
        ], 'q bytes');
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_playerpuzzle', 'filearea' => 'answertext',
            'itemid' => $answerid, 'filepath' => '/', 'filename' => 'a.png',
        ], 'a bytes');

        questions_repository::delete_question($questionid, $context);

        $qfiles = $fs->get_area_files($context->id, 'mod_playerpuzzle', 'questiontext', $questionid, 'sortorder', false);
        $afiles = $fs->get_area_files($context->id, 'mod_playerpuzzle', 'answertext', $answerid, 'sortorder', false);
        $this->assertCount(0, $qfiles);
        $this->assertCount(0, $afiles);
    }

    /**
     * Tests that update_question(), given a context, purges the old answer rows' file
     * areas before they are deleted and replaced — otherwise a re-uploaded image on a
     * later edit would leave the previous one behind as an orphaned file forever.
     *
     * @return void
     */
    public function test_update_question_purges_old_answer_file_areas_when_context_given(): void {
        $this->resetAfterTest();
        $context = $this->make_real_context();
        $fs = get_file_storage();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Q',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );
        $oldanswerid = (int) questions_repository::get_question($questionid, 7)->answers[0]->id;
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_playerpuzzle', 'filearea' => 'answertext',
            'itemid' => $oldanswerid, 'filepath' => '/', 'filename' => 'old.png',
        ], 'old bytes');

        questions_repository::update_question(
            $questionid,
            'multichoice',
            'Q',
            '',
            [
                ['text' => 'X', 'iscorrect' => true],
                ['text' => 'Y', 'iscorrect' => false],
            ],
            $context
        );

        $oldfiles = $fs->get_area_files($context->id, 'mod_playerpuzzle', 'answertext', $oldanswerid, 'sortorder', false);
        $this->assertCount(0, $oldfiles);
    }

    /**
     * Tests that get_question() returns null for a non-existent id.
     *
     * @return void
     */
    public function test_get_question_returns_null_when_not_found(): void {
        $this->resetAfterTest();

        $this->assertNull(questions_repository::get_question(99999, 7));
    }

    /**
     * Tests that get_question() returns null when the id is real but belongs to a
     * different instance — the ownership check is baked into the same lookup, never a
     * separate "load, then check" step, so a foreign id never even reaches the answers
     * query.
     *
     * @return void
     */
    public function test_get_question_returns_null_for_a_different_instance(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Belongs to instance 7',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );

        $this->assertNull(questions_repository::get_question($questionid, 9));
    }

    /**
     * Tests that get_questions_for_instance() only returns questions belonging to that
     * instance, each with its own answers attached, ordered most recent first.
     *
     * @return void
     */
    public function test_get_questions_for_instance_scopes_and_orders_results(): void {
        $this->resetAfterTest();

        $first = questions_repository::add_question(
            7,
            'multichoice',
            'First question',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );
        $second = questions_repository::add_question(
            7,
            'truefalse',
            'Second question',
            '',
            [
                ['text' => get_string('true', 'qtype_truefalse'), 'iscorrect' => true],
                ['text' => get_string('false', 'qtype_truefalse'), 'iscorrect' => false],
            ],
            42
        );
        // Belongs to a different instance — must never leak into instance 7's listing.
        questions_repository::add_question(
            9,
            'multichoice',
            'Other instance question',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );

        $questions = questions_repository::get_questions_for_instance(7);

        $this->assertCount(2, $questions);
        $ids = array_map(fn ($question) => (int) $question->id, $questions);
        $this->assertContains($first, $ids);
        $this->assertContains($second, $ids);
        foreach ($questions as $question) {
            $this->assertNotEmpty($question->answers);
        }
    }

    /**
     * Tests that an instance with no questions returns an empty array.
     *
     * @return void
     */
    public function test_get_questions_for_instance_returns_empty_array_when_none_exist(): void {
        $this->resetAfterTest();

        $this->assertSame([], questions_repository::get_questions_for_instance(7));
    }

    /**
     * Tests that get_questions_for_management() scopes by instance, paginates, sorts by an
     * allowed column, falls back to 'id' for an unknown one, and attaches each row's answers.
     *
     * @return void
     */
    public function test_get_questions_for_management_scopes_paginates_and_sorts(): void {
        $this->resetAfterTest();

        $first = questions_repository::add_question(
            7,
            'multichoice',
            'A question',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42,
            'ai'
        );
        $second = questions_repository::add_question(
            7,
            'truefalse',
            'B question',
            '',
            [
                ['text' => get_string('true', 'qtype_truefalse'), 'iscorrect' => true],
                ['text' => get_string('false', 'qtype_truefalse'), 'iscorrect' => false],
            ],
            42,
            'manual'
        );
        // Belongs to a different instance — must never leak into instance 7's listing.
        questions_repository::add_question(
            9,
            'multichoice',
            'Other instance question',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );

        $pool = questions_repository::get_questions_for_management(7, 0, 1, 'source', 'ASC');

        $this->assertSame(2, $pool['total']);
        $this->assertCount(1, $pool['rows']);
        $this->assertSame($first, (int) $pool['rows'][0]->id);
        $this->assertNotEmpty($pool['rows'][0]->answers);

        $secondpage = questions_repository::get_questions_for_management(7, 1, 1, 'source', 'ASC');
        $this->assertCount(1, $secondpage['rows']);
        $this->assertSame($second, (int) $secondpage['rows'][0]->id);

        $fallback = questions_repository::get_questions_for_management(7, 0, 30, 'notacolumn', 'ASC');
        $this->assertCount(2, $fallback['rows']);
    }

    /**
     * Tests that get_draw_counts() counts each question's rows in
     * playerpuzzle_attempt_questions across every attempt of the instance, and never counts
     * a question drawn only in a different instance's attempt.
     *
     * @return void
     */
    public function test_get_draw_counts_counts_per_question_within_instance(): void {
        global $DB;

        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Drawn question',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );
        $neverdrawn = questions_repository::add_question(
            7,
            'multichoice',
            'Never drawn',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );

        $now = time();
        $attemptid = $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => 7,
            'userid' => 2,
            'token' => 'tok-instance-7',
            'timecreated' => $now,
        ]);
        $otherattemptid = $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => 9,
            'userid' => 2,
            'token' => 'tok-instance-9',
            'timecreated' => $now,
        ]);

        for ($i = 0; $i < 2; $i++) {
            $DB->insert_record('playerpuzzle_attempt_questions', (object) [
                'attemptid' => $attemptid,
                'questionid' => $questionid,
                'iscorrect' => 1,
                'timecreated' => $now,
            ]);
        }
        // Same question id drawn in a different instance's attempt — must not be counted here.
        $DB->insert_record('playerpuzzle_attempt_questions', (object) [
            'attemptid' => $otherattemptid,
            'questionid' => $questionid,
            'iscorrect' => 1,
            'timecreated' => $now,
        ]);

        $counts = questions_repository::get_draw_counts(7);

        $this->assertSame(2, $counts[$questionid]);
        $this->assertArrayNotHasKey($neverdrawn, $counts);
    }

    /**
     * Tests that approve_questions_bulk() approves every given id belonging to the instance,
     * and never touches an id belonging to a different one.
     *
     * @return void
     */
    public function test_approve_questions_bulk_approves_owned_ids_only(): void {
        $this->resetAfterTest();

        $pending = questions_repository::add_question(
            7,
            'multichoice',
            'Pending',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42,
            'ai',
            false
        );
        $foreign = questions_repository::add_question(
            9,
            'multichoice',
            'Belongs to another instance',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42,
            'ai',
            false
        );

        questions_repository::approve_questions_bulk([$pending, $foreign], 7);

        $this->assertSame(1, (int) questions_repository::get_question($pending, 7)->approved);
        $this->assertSame(0, (int) questions_repository::get_question($foreign, 9)->approved);
    }

    /**
     * Tests that approve_questions_bulk() also clears sourceid on a disabled bank question —
     * same detach-from-sync behaviour as the single-row approve_question(), so a "select all,
     * approve" pass reactivates disabled bank rows exactly like clicking "Reactivate" on each.
     *
     * @return void
     */
    public function test_approve_questions_bulk_also_detaches_a_bank_question(): void {
        $this->resetAfterTest();

        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Orphaned bank question',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            0,
            'bank',
            false,
            FORMAT_PLAIN,
            123
        );

        questions_repository::approve_questions_bulk([$questionid], 7);

        $question = questions_repository::get_question($questionid, 7);
        $this->assertSame(1, (int) $question->approved);
        $this->assertNull($question->sourceid);
    }

    /**
     * Tests that delete_questions_bulk() deletes every given id belonging to the instance
     * (including its answers), and leaves an id belonging to a different instance untouched.
     *
     * @return void
     */
    public function test_delete_questions_bulk_deletes_owned_ids_only(): void {
        global $DB;

        $this->resetAfterTest();

        $owned = questions_repository::add_question(
            7,
            'multichoice',
            'To be deleted',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );
        $foreign = questions_repository::add_question(
            9,
            'multichoice',
            'Belongs to another instance',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            42
        );

        questions_repository::delete_questions_bulk([$owned, $foreign], 7);

        $this->assertNull(questions_repository::get_question($owned, 7));
        $this->assertSame(0, $DB->count_records('playerpuzzle_question_answers', ['questionid' => $owned]));
        $this->assertNotNull(questions_repository::get_question($foreign, 9));
    }
}
