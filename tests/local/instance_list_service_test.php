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
 * Tests for instance_list_service.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for instance_list_service and the event index.php fires.
 *
 * @covers \mod_playerpuzzle\local\instance_list_service
 * @covers \mod_playerpuzzle\event\course_module_instance_list_viewed
 */
final class instance_list_service_test extends \advanced_testcase {
    /**
     * Tests an empty course gets the "there are none" text and no rows.
     *
     * @return void
     */
    public function test_a_course_without_activities_has_no_rows(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $data = instance_list_service::build_page_data($course);

        $this->assertFalse($data['hasinstances']);
        $this->assertSame([], $data['instances']);
        $this->assertNotSame('', $data['emptytext']);
    }

    /**
     * Tests the rows link to each activity, and that a hidden one is left out for a student
     * but shown, dimmed, for a teacher.
     *
     * @return void
     */
    public function test_rows_follow_what_the_user_may_see(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $visible = $generator->create_instance(['course' => $course->id, 'name' => 'Visible puzzle']);
        $hidden = $generator->create_instance(['course' => $course->id, 'name' => 'Hidden puzzle', 'visible' => 0]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($student);
        $studentrows = instance_list_service::build_page_data($course)['instances'];
        $this->assertSame(['Visible puzzle'], array_column($studentrows, 'name'));
        $this->assertStringContainsString(
            '/mod/playerpuzzle/view.php?id=' . $visible->cmid,
            $studentrows[0]['url']
        );
        $this->assertFalse($studentrows[0]['dimmed']);

        $this->setUser($teacher);
        $teacherrows = instance_list_service::build_page_data($course)['instances'];
        $byname = array_column($teacherrows, 'dimmed', 'name');
        $this->assertSame(['Visible puzzle' => false, 'Hidden puzzle' => true], $byname);
        $this->assertNotEmpty($hidden->cmid);
    }

    /**
     * Tests the event index.php fires carries the course context and points at the list.
     *
     * @return void
     */
    public function test_event_data_and_url(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $sink = $this->redirectEvents();
        $event = \mod_playerpuzzle\event\course_module_instance_list_viewed::create([
            'context' => \context_course::instance($course->id),
        ]);
        $event->add_record_snapshot('course', $course);
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertEquals(\context_course::instance($course->id)->id, $events[0]->contextid);
        $this->assertSame('mod_playerpuzzle', $events[0]->component);
        $this->assertEquals(
            new \moodle_url('/mod/playerpuzzle/index.php', ['id' => $course->id]),
            $events[0]->get_url()
        );
    }


    /**
     * Tests that a name with "&" or "<" is escaped once in the rendered list, not twice: the service hands
     * plain text to the template, which does the escaping.
     *
     * @return void
     */
    public function test_a_name_with_special_characters_is_escaped_only_once(): void {
        global $OUTPUT;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $generator->create_instance(['course' => $course->id, 'name' => 'Questions & Answers > 3']);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $data = instance_list_service::build_page_data($course);
        $html = $OUTPUT->render_from_template('mod_playerpuzzle/instance_list', $data);

        $this->assertSame('Questions & Answers > 3', $data['instances'][0]['name']);
        $this->assertStringContainsString('Questions &amp; Answers &gt; 3', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }
}
