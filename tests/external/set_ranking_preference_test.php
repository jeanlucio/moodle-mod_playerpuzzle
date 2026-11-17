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
 * External function tests for set_ranking_preference.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use core_external\external_api;
use mod_playerpuzzle\local\ranking_preferences;

/**
 * Tests for the mod_playerpuzzle_set_ranking_preference web service.
 *
 * @covers \mod_playerpuzzle\external\set_ranking_preference
 * @covers \mod_playerpuzzle\local\ranking_preferences
 */
final class set_ranking_preference_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_set_ranking_preference', $args);
    }

    /**
     * Tests that unchecking the box hides the name of the calling user only, and checking it again shows it.
     *
     * @return void
     */
    public function test_saves_the_choice_for_the_current_user_only(): void {
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $this->course->id]);
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $userid = (int) $this->student->id;
        $this->setUser($this->student);
        $this->assertFalse(ranking_preferences::is_anonymous($userid), 'the name is shown until the student chooses');

        $result = $this->call(['cmid' => $instance->cmid, 'visible' => false]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertTrue(ranking_preferences::is_anonymous($userid));
        $this->assertFalse(ranking_preferences::is_anonymous((int) $other->id));
        $this->assertSame([$userid => true], ranking_preferences::anonymous_among([$userid, (int) $other->id]));

        $this->call(['cmid' => $instance->cmid, 'visible' => true]);
        $this->assertFalse(ranking_preferences::is_anonymous($userid));
        $this->assertSame([], ranking_preferences::anonymous_among([$userid]));
        $this->assertSame([], ranking_preferences::anonymous_among([]));
    }

    /**
     * Tests that a user without access to the activity cannot save the choice through it.
     *
     * @return void
     */
    public function test_requires_the_view_capability(): void {
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $this->course->id]);
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $result = $this->call(['cmid' => $instance->cmid, 'visible' => false]);

        $this->assertTrue($result['error']);
        $this->assertFalse(ranking_preferences::is_anonymous((int) $outsider->id));
    }
}
