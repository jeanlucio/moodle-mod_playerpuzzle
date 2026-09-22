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
 * External function tests for get_phase_questionlog.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\attempt_questions;
use mod_playerpuzzle\local\engine\security;

/**
 * Tests for the mod_playerpuzzle_get_phase_questionlog web service.
 *
 * @covers \mod_playerpuzzle\external\get_phase_questionlog
 */
final class get_phase_questionlog_test extends \advanced_testcase {
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
     * Creates a plain playerpuzzle instance.
     *
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        return $generator->create_instance(['course' => $this->course->id]);
    }

    /**
     * Calls the mod_playerpuzzle_get_phase_questionlog web service through the real dispatch
     * path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_get_phase_questionlog(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_get_phase_questionlog', $args);
    }

    /**
     * Tests that the questions already answered on the attempt's current level/phase come
     * back, in the same shape save_progress's own questionlog uses.
     *
     * @return void
     */
    public function test_returns_the_current_phase_log(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        attempt_questions::record($attemptid, 1, 1, 1, 'Q1?', 'A', 'A', true);
        attempt_questions::record($attemptid, 2, 1, 1, 'Q2?', 'B', 'C', false);

        $result = $this->call_get_phase_questionlog(['cmid' => $instance->cmid, 'token' => $token]);

        $this->assertFalse($result['error']);
        $this->assertCount(2, $result['data']['questionlog']);
        $this->assertSame('Q1?', $result['data']['questionlog'][0]['questiontext']);
        $this->assertTrue($result['data']['questionlog'][0]['iscorrect']);
        $this->assertFalse($result['data']['questionlog'][1]['iscorrect']);
    }

    /**
     * Tests that a question answered on a different level/phase than the attempt is
     * currently on is never mixed into the result — the same scoping save_progress's own
     * questionlog relies on.
     *
     * @return void
     */
    public function test_does_not_leak_a_different_phase_log(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            2
        );
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        attempt_questions::record($attemptid, 1, 1, 1, 'Phase 1 question', 'A', 'A', true);

        $result = $this->call_get_phase_questionlog(['cmid' => $instance->cmid, 'token' => $token]);

        $this->assertFalse($result['error']);
        $this->assertCount(0, $result['data']['questionlog']);
    }

    /**
     * Tests that no questions answered yet returns an empty list, not an error.
     *
     * @return void
     */
    public function test_empty_when_nothing_answered_yet(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_get_phase_questionlog(['cmid' => $instance->cmid, 'token' => $token]);

        $this->assertFalse($result['error']);
        $this->assertSame([], $result['data']['questionlog']);
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
        $result = $this->call_get_phase_questionlog(['cmid' => $instance->cmid, 'token' => 'deadbeef']);

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
        get_phase_questionlog::execute($instance->cmid, 'anytoken');
    }
}
