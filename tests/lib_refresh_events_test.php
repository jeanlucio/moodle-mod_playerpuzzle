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
 * Tests for playerpuzzle_set_events() and playerpuzzle_refresh_events() in lib.php.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for the due-date calendar event lifecycle.
 *
 * @covers ::playerpuzzle_set_events
 * @covers ::playerpuzzle_refresh_events
 * @covers ::playerpuzzle_add_instance
 * @covers ::playerpuzzle_update_instance
 * @covers ::playerpuzzle_delete_instance
 */
final class lib_refresh_events_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Fetches the due-date calendar event for the given instance, or null if none exists.
     *
     * @param int $instanceid Activity instance id.
     * @return \stdClass|false
     */
    private function fetch_event(int $instanceid) {
        global $DB;
        return $DB->get_record('event', [
            'modulename' => 'playerpuzzle',
            'instance'   => $instanceid,
            'eventtype'  => PLAYERPUZZLE_EVENT_TYPE_DUE,
        ]);
    }

    /**
     * Tests that creating an instance with no due date leaves no calendar event behind.
     *
     * @return void
     */
    public function test_add_instance_without_duedate_creates_no_event(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => 0]);

        $this->assertFalse($this->fetch_event($instance->id));
    }

    /**
     * Tests that creating an instance with a due date creates a matching calendar event.
     *
     * @return void
     */
    public function test_add_instance_with_duedate_creates_the_event(): void {
        $course = $this->getDataGenerator()->create_course();
        $duedate = time() + DAYSECS;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => $duedate]);

        $event = $this->fetch_event($instance->id);
        $this->assertNotFalse($event);
        $this->assertSame($duedate, (int) $event->timestart);
        $this->assertSame((int) $course->id, (int) $event->courseid);
    }

    /**
     * Tests that updating an instance to add a due date creates the event.
     *
     * @return void
     */
    public function test_update_instance_adds_a_duedate_event(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => 0]);

        $duedate = time() + DAYSECS;
        $update = clone $instance;
        $update->instance = $instance->id;
        $update->duedate = $duedate;
        playerpuzzle_update_instance($update);

        $event = $this->fetch_event($instance->id);
        $this->assertNotFalse($event);
        $this->assertSame($duedate, (int) $event->timestart);
    }

    /**
     * Tests that updating an instance's due date updates the existing event in place,
     * rather than creating a second one.
     *
     * @return void
     */
    public function test_update_instance_changes_the_duedate(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $originaldue = time() + DAYSECS;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => $originaldue]);

        $newdue = $originaldue + DAYSECS;
        $update = clone $instance;
        $update->instance = $instance->id;
        $update->duedate = $newdue;
        playerpuzzle_update_instance($update);

        $this->assertSame(1, $DB->count_records('event', [
            'modulename' => 'playerpuzzle',
            'instance'   => $instance->id,
            'eventtype'  => PLAYERPUZZLE_EVENT_TYPE_DUE,
        ]));
        $event = $this->fetch_event($instance->id);
        $this->assertSame($newdue, (int) $event->timestart);
    }

    /**
     * Tests that clearing the due date on update removes the calendar event.
     *
     * @return void
     */
    public function test_update_instance_removes_the_event_when_duedate_cleared(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->assertNotFalse($this->fetch_event($instance->id));

        $update = clone $instance;
        $update->instance = $instance->id;
        $update->duedate = 0;
        playerpuzzle_update_instance($update);

        $this->assertFalse($this->fetch_event($instance->id));
    }

    /**
     * Tests that deleting the instance removes its calendar event.
     *
     * @return void
     */
    public function test_delete_instance_removes_the_event(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => time() + DAYSECS]);
        $this->assertNotFalse($this->fetch_event($instance->id));

        playerpuzzle_delete_instance($instance->id);

        $this->assertFalse($this->fetch_event($instance->id));
    }

    /**
     * Tests that playerpuzzle_refresh_events() refreshes a single named instance.
     *
     * @return void
     */
    public function test_refresh_events_for_specific_instance(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $duedate = time() + DAYSECS;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => $duedate]);

        // Simulate the event having drifted out of sync with the instance.
        $DB->set_field('event', 'name', 'stale', ['modulename' => 'playerpuzzle', 'instance' => $instance->id]);

        $result = playerpuzzle_refresh_events(0, $instance->id);

        $this->assertTrue($result);
        $event = $this->fetch_event($instance->id);
        $this->assertSame(get_string('calendardue', 'mod_playerpuzzle', $instance->name), $event->name);
    }

    /**
     * Tests that playerpuzzle_refresh_events() refreshes every instance in a course when
     * given a courseid and no specific instance.
     *
     * @return void
     */
    public function test_refresh_events_for_a_course(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $duedate = time() + DAYSECS;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => $duedate]);
        $otherinstance = $generator->create_instance(['course' => $othercourse->id, 'duedate' => $duedate]);

        $DB->set_field('event', 'name', 'stale', ['modulename' => 'playerpuzzle', 'instance' => $instance->id]);
        $DB->set_field('event', 'name', 'stale', ['modulename' => 'playerpuzzle', 'instance' => $otherinstance->id]);

        $result = playerpuzzle_refresh_events($course->id);

        $this->assertTrue($result);
        $this->assertSame(
            get_string('calendardue', 'mod_playerpuzzle', $instance->name),
            $this->fetch_event($instance->id)->name
        );
        $this->assertSame('stale', $this->fetch_event($otherinstance->id)->name);
    }

    /**
     * Tests that playerpuzzle_refresh_events() refreshes every instance site-wide when
     * given neither a courseid nor a specific instance.
     *
     * @return void
     */
    public function test_refresh_events_for_the_whole_site(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $duedate = time() + DAYSECS;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'duedate' => $duedate]);
        $otherinstance = $generator->create_instance(['course' => $othercourse->id, 'duedate' => $duedate]);

        $DB->set_field('event', 'name', 'stale', ['modulename' => 'playerpuzzle', 'instance' => $instance->id]);
        $DB->set_field('event', 'name', 'stale', ['modulename' => 'playerpuzzle', 'instance' => $otherinstance->id]);

        $result = playerpuzzle_refresh_events();

        $this->assertTrue($result);
        $this->assertSame(
            get_string('calendardue', 'mod_playerpuzzle', $instance->name),
            $this->fetch_event($instance->id)->name
        );
        $this->assertSame(
            get_string('calendardue', 'mod_playerpuzzle', $otherinstance->name),
            $this->fetch_event($otherinstance->id)->name
        );
    }
}
