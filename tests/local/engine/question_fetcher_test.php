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

        $questions = question_fetcher::get_questions_for_frontend($cat->id, \context_system::instance());

        $this->assertCount(1, $questions);
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

        $questions = question_fetcher::get_questions_for_frontend($catb->id, \context_system::instance());

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

        $questions = question_fetcher::get_questions_for_frontend($cat->id, \context_system::instance(), 2);

        $this->assertCount(2, $questions);
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

        $this->assertTrue(question_fetcher::is_answer_correct((int) $question->id, $correctid));
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

        $this->assertFalse(question_fetcher::is_answer_correct((int) $question->id, $wrongid));
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

        $this->assertFalse(question_fetcher::is_answer_correct((int) $questiona->id, $correctidb));
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

        $this->assertSame($expected, question_fetcher::get_correct_answer_id((int) $question->id));
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

        $this->assertNull(question_fetcher::get_correct_answer_id((int) $question->id));
    }
}
