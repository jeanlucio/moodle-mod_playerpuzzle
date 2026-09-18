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
 * Unit tests for the question fetcher engine.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

use mod_playerpuzzle\local\questions_repository;

/**
 * Tests for question_fetcher.
 *
 * @covers \mod_playerpuzzle\local\engine\question_fetcher
 */
final class question_fetcher_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Creates one approved multichoice question, with "A" as the correct answer.
     *
     * @param int $playerpuzzleid The instance id.
     * @return int The new question id.
     */
    private function make_question(int $playerpuzzleid): int {
        return questions_repository::add_question(
            $playerpuzzleid,
            'multichoice',
            'What is the capital of France?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
                ['text' => 'C', 'iscorrect' => false],
            ],
            2
        );
    }

    /**
     * Tests that draw_random_question_id() only ever returns an id belonging to the
     * requested instance, ignoring a question that belongs to another one.
     *
     * @return void
     */
    public function test_draw_random_question_id_scoped_to_instance(): void {
        $questionid = $this->make_question(7);
        questions_repository::add_question(
            9,
            'multichoice',
            'Belongs to a different instance?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        $drawn = question_fetcher::draw_random_question_id(7);

        $this->assertSame($questionid, $drawn);
    }

    /**
     * Tests that draw_random_question_id() returns null when the instance has no approved
     * questions at all — draw_question.php's own "available: false" fallback depends on this.
     *
     * @return void
     */
    public function test_draw_random_question_id_returns_null_without_approved_questions(): void {
        $this->assertNull(question_fetcher::draw_random_question_id(7));
    }

    /**
     * Tests that draw_random_question_id() never draws an unapproved question (e.g.
     * AI-generated, pending review) — a match must never be offered one a teacher has not
     * approved yet.
     *
     * @return void
     */
    public function test_draw_random_question_id_excludes_unapproved_question(): void {
        questions_repository::add_question(
            7,
            'multichoice',
            'Pending AI question?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2,
            'ai',
            false
        );

        $this->assertNull(question_fetcher::draw_random_question_id(7));
    }

    /**
     * Tests that get_single_question() never carries iscorrect or any other correctness
     * signal alongside an option — the Blind JSON contract: the correct answer must never
     * reach the client before the server validates it.
     *
     * @return void
     */
    public function test_get_single_question_never_leaks_correctness(): void {
        $questionid = $this->make_question(7);

        $question = question_fetcher::get_single_question($questionid, 7, \context_system::instance());

        $this->assertNotEmpty($question['options']);
        foreach ($question['options'] as $option) {
            $this->assertSame(['id', 'text'], array_keys($option));
        }
    }

    /**
     * Tests that get_single_question() returns null for a question belonging to a
     * different instance — never validated by isolated PK.
     *
     * @return void
     */
    public function test_get_single_question_scoped_to_instance(): void {
        $questionid = $this->make_question(7);

        $this->assertNull(question_fetcher::get_single_question($questionid, 9, \context_system::instance()));
    }

    /**
     * Tests that get_single_question() returns null for an unapproved question, even when
     * the instance id given is the real one it belongs to.
     *
     * @return void
     */
    public function test_get_single_question_excludes_unapproved_question(): void {
        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Pending AI question?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2,
            'ai',
            false
        );

        $this->assertNull(question_fetcher::get_single_question($questionid, 7, \context_system::instance()));
    }

    /**
     * Tests that is_answer_correct returns true for the answer with iscorrect = 1.
     *
     * @return void
     */
    public function test_is_answer_correct_true_for_correct_answer(): void {
        $questionid = $this->make_question(7);
        $question = questions_repository::get_question($questionid, 7);
        $correctid = array_values(array_filter($question->answers, fn($a) => (int) $a->iscorrect === 1))[0]->id;

        $this->assertTrue(question_fetcher::is_answer_correct($questionid, (int) $correctid));
    }

    /**
     * Tests that is_answer_correct returns false for a wrong answer.
     *
     * @return void
     */
    public function test_is_answer_correct_false_for_wrong_answer(): void {
        $questionid = $this->make_question(7);
        $question = questions_repository::get_question($questionid, 7);
        $wrongid = array_values(array_filter($question->answers, fn($a) => (int) $a->iscorrect === 0))[0]->id;

        $this->assertFalse(question_fetcher::is_answer_correct($questionid, (int) $wrongid));
    }

    /**
     * Tests that an answer id belonging to a different question is never accepted —
     * cross-question isolation of the iscorrect lookup.
     *
     * @return void
     */
    public function test_is_answer_correct_false_for_answer_of_different_question(): void {
        $questiona = $this->make_question(7);
        $questionb = $this->make_question(7);
        $qb = questions_repository::get_question($questionb, 7);
        $correctidb = array_values(array_filter($qb->answers, fn($a) => (int) $a->iscorrect === 1))[0]->id;

        $this->assertFalse(question_fetcher::is_answer_correct($questiona, (int) $correctidb));
    }

    /**
     * Tests that get_correct_answer_id resolves the answer with iscorrect = 1.
     *
     * @return void
     */
    public function test_get_correct_answer_id_returns_the_correct_one(): void {
        $questionid = $this->make_question(7);
        $question = questions_repository::get_question($questionid, 7);
        $expected = (int) array_values(array_filter($question->answers, fn($a) => (int) $a->iscorrect === 1))[0]->id;

        $this->assertSame($expected, question_fetcher::get_correct_answer_id($questionid));
    }

    /**
     * Tests that get_correct_answer_id returns null for a question with no answer marked
     * correct (a malformed row, defensively covered even though the manual/AI write paths
     * never produce one).
     *
     * @return void
     */
    public function test_get_correct_answer_id_returns_null_when_none_marked_correct(): void {
        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'No correct answer marked?',
            '',
            [
                ['text' => 'A', 'iscorrect' => false],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        $this->assertNull(question_fetcher::get_correct_answer_id($questionid));
    }

    /**
     * Tests get_question_type() reports the qtype, and null for an unknown id.
     *
     * @return void
     */
    public function test_get_question_type(): void {
        $mc = $this->make_question(7);
        $tf = questions_repository::add_question(
            7,
            'truefalse',
            'The sky is blue.',
            '',
            [
                ['text' => get_string('true', 'qtype_truefalse'), 'iscorrect' => true],
                ['text' => get_string('false', 'qtype_truefalse'), 'iscorrect' => false],
            ],
            2
        );

        $this->assertSame('multichoice', question_fetcher::get_question_type($mc));
        $this->assertSame('truefalse', question_fetcher::get_question_type($tf));
        $this->assertNull(question_fetcher::get_question_type(0));
    }

    /**
     * Tests get_question_text() and get_answer_text() return formatted text, and an empty
     * string when the id is unknown.
     *
     * @return void
     */
    public function test_get_question_and_answer_text(): void {
        $questionid = $this->make_question(7);
        $question = questions_repository::get_question($questionid, 7);
        $answerid = (int) $question->answers[0]->id;
        $context = \context_system::instance();

        $this->assertStringContainsString('A', question_fetcher::get_answer_text($answerid, $questionid, $context));
        $this->assertStringContainsString(
            'What is the capital of France?',
            question_fetcher::get_question_text($questionid, $context)
        );
        $this->assertSame('', question_fetcher::get_question_text(0, $context));
        $this->assertSame('', question_fetcher::get_answer_text(0, $questionid, $context));
    }

    /**
     * Tests that get_answer_text() returns an empty string for an answer id belonging to a
     * different question — never validated by isolated PK. Without this, an answerid from
     * any question on the site could be logged/echoed back as the student's own "chosen
     * answer" text (security audit finding, Fase 9), even though is_answer_correct() already
     * scoped its own correctness check by questionid.
     *
     * @return void
     */
    public function test_get_answer_text_returns_empty_for_answer_of_different_question(): void {
        $questiona = $this->make_question(7);
        $questionb = $this->make_question(7);
        $qb = questions_repository::get_question($questionb, 7);
        $answeridb = (int) $qb->answers[0]->id;
        $context = \context_system::instance();

        $this->assertSame('', question_fetcher::get_answer_text($answeridb, $questiona, $context));
        $this->assertNotSame('', question_fetcher::get_answer_text($answeridb, $questionb, $context));
    }

    /**
     * Tests get_answer_ids() returns every answer id for a question, in id order, and
     * includes the correct one.
     *
     * @return void
     */
    public function test_get_answer_ids(): void {
        $questionid = $this->make_question(7);
        $question = questions_repository::get_question($questionid, 7);
        $expected = array_map('intval', array_column($question->answers, 'id'));
        sort($expected);

        $ids = question_fetcher::get_answer_ids($questionid);

        $this->assertSame($expected, $ids);
        $this->assertContains(question_fetcher::get_correct_answer_id($questionid), $ids);
        $this->assertCount(3, $ids);
    }

    /**
     * Tests that get_single_question() flags whether a question has a hint, without ever
     * carrying the hint text itself — the text is only sent after a paid buy_consumable
     * call authorizes it (Blind JSON: nothing the client has not paid for).
     *
     * @return void
     */
    public function test_get_single_question_flags_hashint_without_leaking_text(): void {
        $withhint = questions_repository::add_question(
            7,
            'multichoice',
            'With a hint?',
            'Secret hint text.',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );
        $withouthint = $this->make_question(7);
        $context = \context_system::instance();

        $withhintquestion = question_fetcher::get_single_question($withhint, 7, $context);
        $withouthintquestion = question_fetcher::get_single_question($withouthint, 7, $context);

        $this->assertTrue($withhintquestion['hashint']);
        $this->assertFalse($withouthintquestion['hashint']);
        $this->assertArrayNotHasKey('hint', $withhintquestion);
        $this->assertStringNotContainsString('Secret hint text.', json_encode($withhintquestion));
    }

    /**
     * Tests that get_hint_text() returns the formatted hint for a question that belongs to
     * the instance and has one.
     *
     * @return void
     */
    public function test_get_hint_text_returns_formatted_hint(): void {
        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'With a hint?',
            'Secret hint text.',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        $hint = question_fetcher::get_hint_text($questionid, 7, \context_system::instance());

        $this->assertStringContainsString('Secret hint text.', $hint);
    }

    /**
     * Tests that get_hint_text() returns null for a question with no hint.
     *
     * @return void
     */
    public function test_get_hint_text_returns_null_when_no_hint(): void {
        $questionid = $this->make_question(7);

        $this->assertNull(question_fetcher::get_hint_text($questionid, 7, \context_system::instance()));
    }

    /**
     * Tests that get_hint_text() never returns a hint for a question belonging to a
     * different instance — isolation, not just an unfiltered lookup by id.
     *
     * @return void
     */
    public function test_get_hint_text_returns_null_for_a_different_instance(): void {
        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'With a hint?',
            'Secret hint text.',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        $this->assertNull(question_fetcher::get_hint_text($questionid, 9, \context_system::instance()));
    }

    /**
     * Tests that get_hint_text() never returns a hint for an unapproved question — the same
     * gate get_questions_for_frontend() applies, so a hint can never be bought for a question
     * a match could never actually serve.
     *
     * @return void
     */
    public function test_get_hint_text_returns_null_for_unapproved_question(): void {
        $questionid = questions_repository::add_question(
            7,
            'multichoice',
            'Pending AI question?',
            'Secret hint text.',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2,
            'ai',
            false
        );

        $this->assertNull(question_fetcher::get_hint_text($questionid, 7, \context_system::instance()));
    }
}
