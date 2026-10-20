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
 * Tests for the course_module_viewed event.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\event;

/**
 * Tests for course_module_viewed.
 *
 * @covers \mod_playerpuzzle\event\course_module_viewed
 */
final class course_module_viewed_test extends \advanced_testcase {
    /**
     * Tests the event carries the data the course reports read and points at the Lobby.
     *
     * @return void
     */
    public function test_event_data_and_url(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $context = \context_module::instance($cm->id);

        $sink = $this->redirectEvents();
        $event = course_module_viewed::create(['objectid' => $instance->id, 'context' => $context]);
        $event->add_record_snapshot('playerpuzzle', $instance);
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(course_module_viewed::class, $events[0]);
        $this->assertSame('playerpuzzle', $events[0]->objecttable);
        $this->assertSame('r', $events[0]->crud);
        $this->assertEquals($instance->id, $events[0]->objectid);
        $this->assertEquals($context->id, $events[0]->contextid);
        $this->assertEquals(
            new \moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]),
            $events[0]->get_url()
        );
    }

    /**
     * Tests the objectid is remapped on restore to the mapping the restore step registers
     * for the activity itself.
     *
     * @return void
     */
    public function test_objectid_mapping_names_the_activity(): void {
        $this->assertSame(
            ['db' => 'playerpuzzle', 'restore' => 'playerpuzzle'],
            course_module_viewed::get_objectid_mapping()
        );
    }
}
