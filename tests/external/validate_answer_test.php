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
 * External function tests for validate_answer.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\engine\security;

/**
 * Tests for the mod_playerpuzzle_validate_answer web service.
 *
 * @covers \mod_playerpuzzle\external\validate_answer
 * @covers \mod_playerpuzzle\local\engine\question_fetcher
 */
final class validate_answer_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a playerpuzzle instance whose questioncategory is the given category.
     *
     * @param int $categoryid Question category ID.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(int $categoryid): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        return $generator->create_instance(['course' => $this->course->id, 'questioncategory' => $categoryid]);
    }

    /**
     * Creates a single-correct-answer multichoice question ("One" is correct) in the
     * given category.
     *
     * @param int $categoryid Question category ID.
     * @return \stdClass The created question record.
     */
    private function make_question(int $categoryid): \stdClass {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        return $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $categoryid]);
    }

    /**
     * Creates a true/false question whose correct answer is "True", in the given category.
     *
     * @param int $categoryid Question category ID.
     * @return \stdClass The created question record.
     */
    private function make_truefalse_question(int $categoryid): \stdClass {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        return $questiongenerator->create_question('truefalse', 'true', ['category' => $categoryid]);
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
     * Calls the mod_playerpuzzle_validate_answer web service through the real dispatch
     * path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_validate_answer(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_validate_answer', $args);
    }

    /**
     * Tests that the answer with fraction >= 1.0 is reported correct.
     *
     * @return void
     */
    public function test_correct_answer_returns_true(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $instance = $this->make_instance($cat->id);
        $question = $this->make_question($cat->id);
        $correctid = $this->find_answer_id((int) $question->id, 'One');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $result = $this->call_validate_answer([
            'cmid'       => $instance->cmid,
            'token'      => $token,
            'questionid' => $question->id,
            'answerid'   => $correctid,
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['correct']);
    }

    /**
     * Tests that a wrong answer is reported incorrect and carries the real correct
     * answer id for post-submission feedback.
     *
     * @return void
     */
    public function test_wrong_answer_returns_false_with_correct_id(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $instance = $this->make_instance($cat->id);
        $question = $this->make_question($cat->id);
        $wrongid = $this->find_answer_id((int) $question->id, 'Two');
        $correctid = $this->find_answer_id((int) $question->id, 'One');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $result = $this->call_validate_answer([
            'cmid'       => $instance->cmid,
            'token'      => $token,
            'questionid' => $question->id,
            'answerid'   => $wrongid,
        ]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['correct']);
        $this->assertSame((int) $correctid, (int) $result['data']['correctanswerid']);
    }

    /**
     * Tests that a question belonging to a category other than the instance's own
     * questioncategory is rejected — never validated, even if the answer id supplied
     * really is that question's correct one. This is the instance-isolation guard the
     * JOIN in validate_answer.php enforces.
     *
     * @return void
     */
    public function test_question_outside_instance_category_is_rejected(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $ownedcat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $foreigncat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $instance = $this->make_instance($ownedcat->id);
        $foreignquestion = $this->make_question($foreigncat->id);
        $correctid = $this->find_answer_id((int) $foreignquestion->id, 'One');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $result = $this->call_validate_answer([
            'cmid'       => $instance->cmid,
            'token'      => $token,
            'questionid' => $foreignquestion->id,
            'answerid'   => $correctid,
        ]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['correct']);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced, not just
     * declared — a role with it explicitly prohibited is denied. cm_info's own
     * visibility computation reads this exact capability (is_user_access_restricted_
     * by_capability(), core/classes/cm_info.php), so the module becomes uservisible =
     * false and validate_context()'s require_login() call rejects the request before
     * execute()'s own require_capability() line is ever reached.
     *
     * @return void
     */
    public function test_requires_view_capability(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $instance = $this->make_instance($cat->id);
        $question = $this->make_question($cat->id);
        $modcontext = context_module::instance($instance->cmid);

        $prohibitedrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/playerpuzzle:view', CAP_PROHIBIT, $prohibitedrole, $modcontext);
        role_assign($prohibitedrole, $this->student->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->student);
        $this->expectException(\core\exception\require_login_exception::class);
        validate_answer::execute($instance->cmid, 'anytoken', (int) $question->id, 1);
    }

    /**
     * Tests that a token not matching an in-progress attempt for this user/instance is
     * rejected with the dedicated exception, never silently accepted.
     *
     * @return void
     */
    public function test_unknown_token_is_rejected(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $instance = $this->make_instance($cat->id);
        $question = $this->make_question($cat->id);

        $this->setUser($this->student);
        $result = $this->call_validate_answer([
            'cmid'       => $instance->cmid,
            'token'      => 'deadbeef',
            'questionid' => $question->id,
            'answerid'   => 1,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that on Hard the boss's server-drawn guess always lands on the correct answer
     * (100% precision), for both question types, and that the submitted answerid is ignored.
     *
     * @return void
     */
    public function test_boss_guess_on_hard_is_always_correct(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $instance = $this->make_instance($cat->id);
        $mc = $this->make_question($cat->id);
        $tf = $this->make_truefalse_question($cat->id);
        $mccorrect = $this->find_answer_id((int) $mc->id, 'One');
        $tfcorrect = $this->find_answer_id((int) $tf->id, 'True');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'hard');

        foreach ([[$mc->id, $mccorrect], [$tf->id, $tfcorrect]] as [$qid, $correctid]) {
            for ($i = 0; $i < 25; $i++) {
                $result = $this->call_validate_answer([
                    'cmid'       => $instance->cmid,
                    'token'      => $token,
                    'questionid' => $qid,
                    'answerid'   => 999999,
                    'forwhom'    => 'boss',
                ]);
                $this->assertFalse($result['error']);
                $this->assertTrue($result['data']['correct']);
                $this->assertSame((int) $correctid, (int) $result['data']['pickedanswerid']);
            }
        }
    }

    /**
     * Tests that on Easy the boss's multichoice guess is correct roughly a third of the
     * time — a statistical check with generous bounds around the 0.33 target, never the
     * near-certainty a higher difficulty would give.
     *
     * @return void
     */
    public function test_boss_guess_on_easy_multichoice_is_roughly_one_third(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(['contextid' => \context_system::instance()->id]);
        $instance = $this->make_instance($cat->id);
        $question = $this->make_question($cat->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'easy');

        $runs = 400;
        $hits = 0;
        for ($i = 0; $i < $runs; $i++) {
            $result = $this->call_validate_answer([
                'cmid'       => $instance->cmid,
                'token'      => $token,
                'questionid' => $question->id,
                'answerid'   => 0,
                'forwhom'    => 'boss',
            ]);
            $hits += $result['data']['correct'] ? 1 : 0;
        }

        $rate = $hits / $runs;
        $this->assertGreaterThan(0.20, $rate);
        $this->assertLessThan(0.47, $rate);
    }
}
