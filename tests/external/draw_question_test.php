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
 * External function tests for draw_question.
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
use mod_playerpuzzle\local\move_log;
use mod_playerpuzzle\local\questions_repository;

/**
 * Tests for the mod_playerpuzzle_draw_question web service.
 *
 * @covers \mod_playerpuzzle\external\draw_question
 */
final class draw_question_test extends \advanced_testcase {
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
     * Creates a playerpuzzle instance.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        return $generator->create_instance(array_merge(['course' => $this->course->id], $overrides));
    }

    /**
     * Creates one approved multichoice question.
     *
     * @param int $playerpuzzleid The instance id.
     * @param string $hint Optional hint text.
     * @return int The new question id.
     */
    private function make_question(int $playerpuzzleid, string $hint = ''): int {
        return questions_repository::add_question(
            $playerpuzzleid,
            'multichoice',
            'One of four?',
            $hint,
            [
                ['text' => 'One', 'iscorrect' => true],
                ['text' => 'Two', 'iscorrect' => false],
                ['text' => 'Three', 'iscorrect' => false],
                ['text' => 'Four', 'iscorrect' => false],
            ],
            2
        );
    }

    /**
     * Calls the mod_playerpuzzle_draw_question web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_draw_question(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_draw_question', $args);
    }

    /**
     * Tests that a fresh attempt with no question open yet draws one, persisting it as the
     * attempt's own currentquestionid.
     *
     * @return void
     */
    public function test_draws_a_fresh_question_and_persists_it(): void {
        global $DB;

        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['available']);
        $this->assertSame($questionid, $result['data']['id']);
        $this->assertNotEmpty($result['data']['options']);
        $this->assertSame(
            $questionid,
            (int) $DB->get_field('playerpuzzle_attempts', 'currentquestionid', ['token' => $token])
        );
    }

    /**
     * Tests that a returned option never carries any correctness signal — the Blind JSON
     * contract.
     *
     * @return void
     */
    public function test_never_leaks_correctness(): void {
        $instance = $this->make_instance();
        $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);

        foreach ($result['data']['options'] as $option) {
            $this->assertSame(['id', 'text'], array_keys($option));
        }
    }

    /**
     * Tests that calling draw_question again while a question is still open re-serves the
     * exact same one, rather than drawing a fresh random question — the guarantee that keeps
     * a "draw, peek via forwhom=boss, draw again" loop from bulk-harvesting the bank: a new
     * draw is only ever granted once the open one is actually consumed by validate_answer.
     *
     * @return void
     */
    public function test_reserves_the_same_open_question_until_consumed(): void {
        $instance = $this->make_instance();
        for ($i = 0; $i < 5; $i++) {
            $this->make_question((int) $instance->id);
        }

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $first = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);
        for ($i = 0; $i < 10; $i++) {
            $again = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);
            $this->assertSame($first['data']['id'], $again['data']['id']);
        }
    }

    /**
     * Tests that a fresh question is drawn once the previous one is consumed (a real answer
     * via validate_answer), proving the "re-serve while open" behaviour above is not a
     * permanent lock — the flow is meant to advance once a question is genuinely resolved.
     *
     * @return void
     */
    public function test_draws_a_new_question_once_the_open_one_is_consumed(): void {
        global $DB;

        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);
        $this->assertSame(
            $questionid,
            (int) $DB->get_field('playerpuzzle_attempts', 'currentquestionid', ['token' => $token])
        );

        $_POST['sesskey'] = sesskey();
        external_api::call_external_function('mod_playerpuzzle_validate_answer', [
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => 0,
            'forwhom'  => 'boss',
        ]);
        $this->assertSame(
            0,
            (int) $DB->get_field('playerpuzzle_attempts', 'currentquestionid', ['token' => $token])
        );

        $second = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);
        $this->assertSame($questionid, $second['data']['id']);
    }

    /**
     * Tests that a question's own hint is never offered when hints_enabled is off, even
     * though the question genuinely has one — the client-side "Use Hint" button renders
     * purely off this flag, so forcing it false here is what hides it, with no changes
     * needed on the JS side.
     *
     * @return void
     */
    public function test_hashint_is_false_when_hints_disabled(): void {
        $instance = $this->make_instance(['hints_enabled' => 0]);
        $this->make_question((int) $instance->id, 'A real hint.');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['available']);
        $this->assertFalse($result['data']['hashint']);
    }

    /**
     * Tests that an instance with no approved questions at all reports unavailable, rather
     * than a coding error.
     *
     * @return void
     */
    public function test_unavailable_without_any_approved_question(): void {
        $instance = $this->make_instance();

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['available']);
        $this->assertSame(0, $result['data']['id']);
    }

    /**
     * Tests that a token not matching an in-progress attempt for this user/instance is
     * rejected with the dedicated exception, never silently accepted.
     *
     * @return void
     */
    public function test_unknown_token_is_rejected(): void {
        $instance = $this->make_instance();

        $this->setUser($this->student);
        $result = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => 'deadbeef']);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced.
     *
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance();
        $modcontext = context_module::instance($instance->cmid);

        $prohibitedrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/playerpuzzle:view', CAP_PROHIBIT, $prohibitedrole, $modcontext);
        role_assign($prohibitedrole, $this->student->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->student);
        $this->expectException(\core\exception\require_login_exception::class);
        draw_question::execute($instance->cmid, 'anytoken');
    }

    /**
     * Tests that the events still pending on the client (the move that triggered the
     * question) are stored before the question is drawn, and the reply says where the client
     * continues from.
     *
     * @return void
     */
    public function test_stores_the_pending_events_before_drawing(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->make_question((int) $instance->id);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $move = ['type' => 'move', 'r1' => 2, 'c1' => 3, 'r2' => 2, 'c2' => 4];
        $result = $this->call_draw_question([
            'cmid' => $instance->cmid,
            'token' => $token,
            'eventoffset' => 0,
            'movelog' => [$move],
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(1, $result['data']['eventcount']);
        $stored = move_log::decode($DB->get_field('playerpuzzle_attempts', 'movelog', ['token' => $token]));
        $this->assertSame([$move], $stored);
    }
}
