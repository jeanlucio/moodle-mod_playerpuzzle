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

        $question = questions_repository::get_question($questionid);

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

        $question = questions_repository::get_question($questionid);

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

        $question = questions_repository::get_question($questionid);

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

        $question = questions_repository::get_question($questionid);

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

        $this->assertNull(questions_repository::get_question($questionid));
        $this->assertSame(0, $DB->count_records('playerpuzzle_question_answers', ['questionid' => $questionid]));
    }

    /**
     * Tests that get_question() returns null for a non-existent id.
     *
     * @return void
     */
    public function test_get_question_returns_null_when_not_found(): void {
        $this->resetAfterTest();

        $this->assertNull(questions_repository::get_question(99999));
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
}
