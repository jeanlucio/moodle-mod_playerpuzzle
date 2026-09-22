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
 * External function tests for set_sound_preference.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\sound_preferences;

/**
 * Tests for the mod_playerpuzzle_set_sound_preference web service.
 *
 * @covers \mod_playerpuzzle\external\set_sound_preference
 */
final class set_sound_preference_test extends \advanced_testcase {
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
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');

        return $generator->create_instance(['course' => $this->course->id]);
    }

    /**
     * Calls the mod_playerpuzzle_set_sound_preference web service through the real
     * dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_set_sound_preference(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_set_sound_preference', $args);
    }

    /**
     * Tests that a valid call persists the preference for the calling user.
     *
     * @return void
     */
    public function test_saves_the_preference_for_the_current_user(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_set_sound_preference([
            'cmid'    => $instance->cmid,
            'type'    => 'music',
            'enabled' => false,
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertFalse(sound_preferences::is_enabled('music', (int) $this->student->id));
    }

    /**
     * Tests that the two channels are saved independently — setting one never touches
     * the other.
     *
     * @return void
     */
    public function test_channels_are_saved_independently(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $this->call_set_sound_preference(['cmid' => $instance->cmid, 'type' => 'sfx', 'enabled' => false]);

        $this->assertTrue(sound_preferences::is_enabled('music', (int) $this->student->id));
        $this->assertFalse(sound_preferences::is_enabled('sfx', (int) $this->student->id));
    }

    /**
     * Tests that the narration channel can be saved through the same endpoint as
     * Music/Sound Effects, defaulting to disabled until explicitly turned on.
     *
     * @return void
     */
    public function test_speech_channel_can_be_saved(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $this->assertFalse(sound_preferences::is_enabled('speech', (int) $this->student->id));

        $result = $this->call_set_sound_preference([
            'cmid'    => $instance->cmid,
            'type'    => 'speech',
            'enabled' => true,
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue(sound_preferences::is_enabled('speech', (int) $this->student->id));
    }

    /**
     * Tests that an invalid sound channel is rejected.
     *
     * @return void
     */
    public function test_invalid_type_is_rejected(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_set_sound_preference([
            'cmid'    => $instance->cmid,
            'type'    => 'bogus',
            'enabled' => true,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidsoundtype', $result['exception']->errorcode);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced — same
     * mechanism already verified for the other combat web services.
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
        set_sound_preference::execute($instance->cmid, 'music', false);
    }
}
