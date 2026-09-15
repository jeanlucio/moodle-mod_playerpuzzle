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
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Creates a playerpuzzle instance with the given question source overrides.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        return $generator->create_instance(array_merge(['course' => $course->id], $overrides));
    }

    /**
     * Creates a single-correct-answer multichoice question ("One" is correct, fraction
     * 1.0; "Two"/"Three"/"Four" are wrong, fraction 0.0) in the given category.
     *
     * @param int $categoryid Question category ID.
     * @return \stdClass The created question record.
     */
    private function make_single_answer_question(int $categoryid): \stdClass {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        return $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $categoryid]);
    }

    /**
     * Finds the id of the answer with the given text for a question.
     *
     * Filters in PHP rather than in SQL: question_answers.answer is a text column, and
     * Postgres rejects an equality comparison against one without sql_compare_text().
     *
     * @param int $questionid Question ID.
     * @param string $text Exact answer text to look for.
     * @return int The matching answer ID.
     */
    private function find_answer_id(int $questionid, string $text): int {
        global $DB;

        foreach ($DB->get_records('question_answers', ['question' => $questionid]) as $answer) {
            if ($answer->answer === $text) {
                return (int) $answer->id;
            }
        }

        $this->fail("No answer with text '$text' found for question $questionid.");
    }

    /**
     * Creates one approved multichoice question in PlayerPuzzle's own bank, with "A" as
     * the correct answer.
     *
     * @param int $playerpuzzleid The instance id.
     * @return int The new question id.
     */
    private function make_ownbank_question(int $playerpuzzleid): int {
        return questions_repository::add_question(
            $playerpuzzleid,
            'multichoice',
            'Own bank question?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );
    }

    /**
     * Tests that the frontend payload never carries the fraction or any other
     * correctness signal alongside an option — the Blind JSON contract: the correct
     * answer must never reach the client before the server validates it.
     *
     * @return void
     */
    public function test_get_questions_for_frontend_never_leaks_correctness(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $this->make_single_answer_question($cat->id);
        $instance = $this->make_instance(['questioncategory' => $cat->id]);

        $questions = question_fetcher::get_questions_for_frontend($instance, \context_system::instance());

        $this->assertCount(1, $questions);
        $this->assertSame(question_fetcher::BANK_QUESTIONBANK, $questions[0]['bank']);
        $this->assertNotEmpty($questions[0]['options']);
        foreach ($questions[0]['options'] as $option) {
            $this->assertSame(['id', 'text'], array_keys($option));
        }
    }

    /**
     * Tests that only questions from the requested category are returned.
     *
     * @return void
     */
    public function test_get_questions_for_frontend_filters_by_category(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cata = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $catb = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $this->make_single_answer_question($cata->id);
        $instance = $this->make_instance(['questioncategory' => $catb->id]);

        $questions = question_fetcher::get_questions_for_frontend($instance, \context_system::instance());

        $this->assertSame([], $questions);
    }

    /**
     * Tests that the returned set never exceeds the requested limit.
     *
     * @return void
     */
    public function test_get_questions_for_frontend_respects_limit(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        for ($i = 0; $i < 3; $i++) {
            $this->make_single_answer_question($cat->id);
        }
        $instance = $this->make_instance(['questioncategory' => $cat->id]);

        $questions = question_fetcher::get_questions_for_frontend($instance, \context_system::instance(), 2);

        $this->assertCount(2, $questions);
    }

    /**
     * Tests that a question with source_questionbank disabled never returns anything from
     * the real question bank, even if a matching category has questions in it.
     *
     * @return void
     */
    public function test_get_questions_for_frontend_ignores_questionbank_when_source_disabled(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $this->make_single_answer_question($cat->id);
        $instance = $this->make_instance([
            'questioncategory' => $cat->id,
            'source_questionbank' => 0,
            'source_ownbank' => 1,
        ]);

        $questions = question_fetcher::get_questions_for_frontend($instance, \context_system::instance());

        $this->assertSame([], $questions);
    }

    /**
     * Tests that an approved question from PlayerPuzzle's own bank is returned, tagged
     * with the ownbank source, when that source is enabled.
     *
     * @return void
     */
    public function test_get_questions_for_frontend_includes_ownbank_question(): void {
        $instance = $this->make_instance(['source_questionbank' => 0, 'source_ownbank' => 1]);
        $this->make_ownbank_question((int) $instance->id);

        $questions = question_fetcher::get_questions_for_frontend($instance, \context_system::instance());

        $this->assertCount(1, $questions);
        $this->assertSame(question_fetcher::BANK_OWNBANK, $questions[0]['bank']);
        $this->assertSame('multichoice', $questions[0]['type']);
        $this->assertCount(2, $questions[0]['options']);
        foreach ($questions[0]['options'] as $option) {
            $this->assertSame(['id', 'text'], array_keys($option));
        }
    }

    /**
     * Tests that an unapproved own-bank question (e.g. AI-generated, pending review) is
     * never offered to a match, regardless of the source being enabled.
     *
     * @return void
     */
    public function test_get_questions_for_frontend_excludes_unapproved_ownbank_question(): void {
        $instance = $this->make_instance(['source_questionbank' => 0, 'source_ownbank' => 1]);
        questions_repository::add_question(
            (int) $instance->id,
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

        $questions = question_fetcher::get_questions_for_frontend($instance, \context_system::instance());

        $this->assertSame([], $questions);
    }

    /**
     * Tests that an own-bank question belonging to a different instance never leaks into
     * this one's pool, even with the own bank source enabled on both.
     *
     * @return void
     */
    public function test_get_questions_for_frontend_ownbank_scoped_to_instance(): void {
        $instance = $this->make_instance(['source_questionbank' => 0, 'source_ownbank' => 1]);
        $otherinstance = $this->make_instance(['source_questionbank' => 0, 'source_ownbank' => 1]);
        $this->make_ownbank_question((int) $otherinstance->id);

        $questions = question_fetcher::get_questions_for_frontend($instance, \context_system::instance());

        $this->assertSame([], $questions);
    }

    /**
     * Tests that with both sources enabled, questions from the real question bank and
     * PlayerPuzzle's own bank are pooled together in a single result set, each correctly
     * tagged with its own bank.
     *
     * @return void
     */
    public function test_get_questions_for_frontend_unions_both_sources(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $this->make_single_answer_question($cat->id);
        $instance = $this->make_instance([
            'questioncategory' => $cat->id,
            'source_questionbank' => 1,
            'source_ownbank' => 1,
        ]);
        $this->make_ownbank_question((int) $instance->id);

        $questions = question_fetcher::get_questions_for_frontend($instance, \context_system::instance(), 10);

        $this->assertCount(2, $questions);
        $banks = array_column($questions, 'bank');
        sort($banks);
        $this->assertSame([question_fetcher::BANK_OWNBANK, question_fetcher::BANK_QUESTIONBANK], $banks);
    }

    /**
     * Tests that is_answer_correct returns true for the answer with fraction >= 1.0.
     *
     * @return void
     */
    public function test_is_answer_correct_true_for_correct_answer(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $this->make_single_answer_question($cat->id);

        $correctid = $this->find_answer_id((int) $question->id, 'One');

        $this->assertTrue(question_fetcher::is_answer_correct(
            question_fetcher::BANK_QUESTIONBANK,
            (int) $question->id,
            $correctid
        ));
    }

    /**
     * Tests that is_answer_correct returns false for a wrong answer.
     *
     * @return void
     */
    public function test_is_answer_correct_false_for_wrong_answer(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $this->make_single_answer_question($cat->id);

        $wrongid = $this->find_answer_id((int) $question->id, 'Two');

        $this->assertFalse(question_fetcher::is_answer_correct(question_fetcher::BANK_QUESTIONBANK, (int) $question->id, $wrongid));
    }

    /**
     * Tests that an answer id belonging to a different question is never accepted —
     * cross-question isolation of the fraction lookup.
     *
     * @return void
     */
    public function test_is_answer_correct_false_for_answer_of_different_question(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $questiona = $this->make_single_answer_question($cat->id);
        $questionb = $this->make_single_answer_question($cat->id);

        $correctidb = $this->find_answer_id((int) $questionb->id, 'One');

        $this->assertFalse(question_fetcher::is_answer_correct(
            question_fetcher::BANK_QUESTIONBANK,
            (int) $questiona->id,
            $correctidb
        ));
    }

    /**
     * Tests that is_answer_correct correctly reads playerpuzzle_question_answers when
     * the bank is ownbank.
     *
     * @return void
     */
    public function test_is_answer_correct_ownbank(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_ownbank_question((int) $instance->id);
        $question = questions_repository::get_question($questionid);
        $correctanswer = array_values(array_filter($question->answers, fn($a) => (int) $a->iscorrect === 1))[0];
        $wronganswer = array_values(array_filter($question->answers, fn($a) => (int) $a->iscorrect === 0))[0];

        $this->assertTrue(question_fetcher::is_answer_correct(
            question_fetcher::BANK_OWNBANK,
            $questionid,
            (int) $correctanswer->id
        ));
        $this->assertFalse(question_fetcher::is_answer_correct(
            question_fetcher::BANK_OWNBANK,
            $questionid,
            (int) $wronganswer->id
        ));
    }

    /**
     * Tests that get_correct_answer_id resolves the answer with fraction >= 1.0.
     *
     * @return void
     */
    public function test_get_correct_answer_id_returns_the_correct_one(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $this->make_single_answer_question($cat->id);

        $expected = $this->find_answer_id((int) $question->id, 'One');

        $this->assertSame(
            $expected,
            question_fetcher::get_correct_answer_id(question_fetcher::BANK_QUESTIONBANK, (int) $question->id)
        );
    }

    /**
     * Tests that get_correct_answer_id returns null for a question with no answer
     * reaching fraction 1.0 (a partial-credit "pick two" question, where each correct
     * option only carries 0.5).
     *
     * @return void
     */
    public function test_get_correct_answer_id_returns_null_when_none_reaches_full_credit(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $questiongenerator->create_question('multichoice', 'two_of_four', ['category' => $cat->id]);

        $this->assertNull(question_fetcher::get_correct_answer_id(question_fetcher::BANK_QUESTIONBANK, (int) $question->id));
    }

    /**
     * Tests get_question_type() reports the qtype, and null for an unknown id, for both
     * banks.
     *
     * @return void
     */
    public function test_get_question_type(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $mc = $this->make_single_answer_question($cat->id);
        $tf = $questiongenerator->create_question('truefalse', 'true', ['category' => $cat->id]);

        $this->assertSame(
            'multichoice',
            question_fetcher::get_question_type(question_fetcher::BANK_QUESTIONBANK, (int) $mc->id)
        );
        $this->assertSame(
            'truefalse',
            question_fetcher::get_question_type(question_fetcher::BANK_QUESTIONBANK, (int) $tf->id)
        );
        $this->assertNull(question_fetcher::get_question_type(question_fetcher::BANK_QUESTIONBANK, 0));

        $instance = $this->make_instance();
        $ownid = $this->make_ownbank_question((int) $instance->id);
        $this->assertSame('multichoice', question_fetcher::get_question_type(question_fetcher::BANK_OWNBANK, $ownid));
        $this->assertNull(question_fetcher::get_question_type(question_fetcher::BANK_OWNBANK, 0));
    }

    /**
     * Tests get_question_text() and get_answer_text() return formatted text, and an empty
     * string when the id is unknown, for both banks.
     *
     * @return void
     */
    public function test_get_question_and_answer_text(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $this->make_single_answer_question($cat->id);
        $answerid = question_fetcher::get_correct_answer_id(question_fetcher::BANK_QUESTIONBANK, (int) $question->id);
        $context = \context_system::instance();

        $this->assertStringContainsString(
            'One',
            question_fetcher::get_answer_text(question_fetcher::BANK_QUESTIONBANK, $answerid, $context)
        );
        $this->assertNotSame(
            '',
            question_fetcher::get_question_text(question_fetcher::BANK_QUESTIONBANK, (int) $question->id, $context)
        );
        $this->assertSame('', question_fetcher::get_question_text(question_fetcher::BANK_QUESTIONBANK, 0, $context));
        $this->assertSame('', question_fetcher::get_answer_text(question_fetcher::BANK_QUESTIONBANK, 0, $context));

        $instance = $this->make_instance();
        $ownid = $this->make_ownbank_question((int) $instance->id);
        $ownanswerid = question_fetcher::get_correct_answer_id(question_fetcher::BANK_OWNBANK, $ownid);
        $this->assertStringContainsString(
            'A',
            question_fetcher::get_answer_text(question_fetcher::BANK_OWNBANK, $ownanswerid, $context)
        );
        $this->assertStringContainsString(
            'Own bank question?',
            question_fetcher::get_question_text(question_fetcher::BANK_OWNBANK, $ownid, $context)
        );
        $this->assertSame('', question_fetcher::get_question_text(question_fetcher::BANK_OWNBANK, 0, $context));
        $this->assertSame('', question_fetcher::get_answer_text(question_fetcher::BANK_OWNBANK, 0, $context));
    }

    /**
     * Tests get_answer_ids() returns every answer id for a question, in id order, and
     * includes the correct one, for both banks.
     *
     * @return void
     */
    public function test_get_answer_ids(): void {
        global $DB;

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $question = $this->make_single_answer_question($cat->id);

        $expected = array_map('intval', $DB->get_fieldset_select(
            'question_answers',
            'id',
            'question = :qid ORDER BY id ASC',
            ['qid' => $question->id]
        ));

        $ids = question_fetcher::get_answer_ids(question_fetcher::BANK_QUESTIONBANK, (int) $question->id);

        $this->assertSame($expected, $ids);
        $this->assertContains(
            question_fetcher::get_correct_answer_id(question_fetcher::BANK_QUESTIONBANK, (int) $question->id),
            $ids
        );
        $this->assertCount(4, $ids);

        $instance = $this->make_instance();
        $ownid = $this->make_ownbank_question((int) $instance->id);
        $ownids = question_fetcher::get_answer_ids(question_fetcher::BANK_OWNBANK, $ownid);
        $this->assertCount(2, $ownids);
        $this->assertContains(
            question_fetcher::get_correct_answer_id(question_fetcher::BANK_OWNBANK, $ownid),
            $ownids
        );
    }
}
