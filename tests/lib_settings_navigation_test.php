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
 * Unit tests for playerpuzzle_extend_settings_navigation().
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests that the activity's administration links reach the teacher and nobody else.
 *
 * @covers ::playerpuzzle_extend_settings_navigation
 */
final class lib_settings_navigation_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Builds what the callback adds for a user, on a page of the activity.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $cm The course module.
     * @param \stdClass $user The user looking at the page.
     * @return string[] The text of each link added, in order.
     */
    private function links_for(\stdClass $course, \stdClass $cm, \stdClass $user): array {
        global $PAGE;

        $this->setUser($user);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]);
        $PAGE->set_context(\context_module::instance($cm->id));
        $PAGE->set_cm($cm, $course);

        $node = \navigation_node::create('PlayerPuzzle');
        playerpuzzle_extend_settings_navigation($PAGE->settingsnav, $node);

        $texts = [];
        foreach ($node->children as $child) {
            $texts[] = $child->text;
        }

        return $texts;
    }

    /**
     * Tests that a teacher finds the question bank and the report in the activity's navigation, and a student
     * neither, since the Lobby no longer carries a link to the question bank.
     *
     * @return void
     */
    public function test_the_teacher_links_are_in_the_navigation_for_teachers_only(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('playerpuzzle', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id, $course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertSame(
            [get_string('managequestions', 'mod_playerpuzzle'), get_string('report_title', 'mod_playerpuzzle')],
            $this->links_for($course, $cm, $teacher)
        );
        $this->assertSame([], $this->links_for($course, $cm, $student));
    }
}
