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
 * Tests for the course reset hooks in lib.php.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

use mod_playerpuzzle\completion\custom_completion;
use mod_playerpuzzle\local\user_stock;

/**
 * Tests for the PlayerPuzzle part of "Reset course", run through the real reset_course_userdata().
 *
 * @covers ::playerpuzzle_reset_userdata
 * @covers ::playerpuzzle_reset_course_form_definition
 * @covers ::playerpuzzle_reset_course_form_defaults
 */
final class lib_reset_userdata_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $CFG->enablecompletion = true;
        require_once($CFG->libdir . '/moodlelib.php');
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Creates an activity requiring one finished attempt, with a student's attempt and everything
     * hanging from it, plus a PuzzleCoin balance.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $student The student.
     * @return \stdClass The activity, with ->attemptid.
     */
    private function create_activity_with_progress(\stdClass $course, \stdClass $student): \stdClass {
        global $DB;

        $instance = $this->getDataGenerator()->create_module('playerpuzzle', [
            'course' => $course->id,
            'grade' => 100,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionattemptsenabled' => 1,
            'completionattempts' => 1,
        ]);
        $attemptid = $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid'         => $student->id,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'won',
            'timecreated'    => time(),
            'timefinished'   => time(),
        ]);
        $DB->insert_record('playerpuzzle_attempt_questions', (object) [
            'attemptid' => $attemptid, 'questionid' => 0, 'attemptlevel' => 1, 'attemptphase' => 1,
            'questiontext' => 'Q?', 'chosenanswer' => 'A', 'correctanswer' => 'A', 'iscorrect' => 1,
            'timecreated' => time(),
        ]);
        $DB->insert_record('playerpuzzle_attempt_consumables', (object) [
            'attemptid' => $attemptid, 'consumabletype' => 'sword', 'timesused' => 1,
        ]);
        user_stock::credit((int) $student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 25);
        grade_update('mod/playerpuzzle', $course->id, 'mod', 'playerpuzzle', $instance->id, 0, [
            $student->id => ['userid' => $student->id, 'rawgrade' => 80],
        ], ['gradetype' => GRADE_TYPE_VALUE, 'grademax' => 100, 'grademin' => 0]);
        $instance->attemptid = $attemptid;

        return $instance;
    }

    /**
     * Runs the core course reset with the given options.
     *
     * @param \stdClass $course The course to reset.
     * @param array $options The reset options.
     * @return array The status rows the core reports.
     */
    private function run_reset(\stdClass $course, array $options): array {
        $this->setAdminUser();
        return reset_course_userdata((object) array_merge(['id' => $course->id], $options));
    }

    /**
     * Reads the student's grade for an activity from the gradebook.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $instance The activity.
     * @param \stdClass $student The student.
     * @return mixed The grade, null when there is none.
     */
    private function gradebook_grade(\stdClass $course, \stdClass $instance, \stdClass $student): mixed {
        $grades = grade_get_grades($course->id, 'mod', 'playerpuzzle', $instance->id, $student->id);
        return $grades->items[0]->grades[$student->id]->grade ?? null;
    }

    /**
     * Ticking the option clears the attempts and what hangs from them, the stock and the grade, and the
     * student no longer satisfies the completion rule from the old attempts.
     *
     * @return void
     */
    public function test_reset_clears_the_students_data_and_the_completion_it_gave(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->create_activity_with_progress($course, $student);
        $cminfo = get_fast_modinfo($course)->get_cm($instance->cmid);
        $completion = new custom_completion($cminfo, (int) $student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_state('completionattempts'));
        $this->assertEquals(80.0, $this->gradebook_grade($course, $instance, $student));

        $status = $this->run_reset($course, ['reset_playerpuzzle_userdata' => 1]);

        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('playerpuzzle_attempt_questions', ['attemptid' => $instance->attemptid]));
        $this->assertSame(0, $DB->count_records('playerpuzzle_attempt_consumables', ['attemptid' => $instance->attemptid]));
        $this->assertSame(0, user_stock::get_quantity((int) $student->id, (int) $instance->id, user_stock::CURRENCY_TYPE));
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionattempts'));
        $this->assertNull($this->gradebook_grade($course, $instance, $student));
        $this->assertContains(
            get_string('resetplayerpuzzleuserdata', 'mod_playerpuzzle'),
            array_column($status, 'item')
        );
    }

    /**
     * Without the option nothing is touched, and the questions the teacher wrote are never removed.
     *
     * @return void
     */
    public function test_reset_keeps_everything_when_the_option_is_off_and_always_keeps_the_questions(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->create_activity_with_progress($course, $student);
        local\questions_repository::add_question((int) $instance->id, 'multichoice', 'Q?', '', [
            ['text' => 'A', 'iscorrect' => true],
            ['text' => 'B', 'iscorrect' => false],
        ], 2);

        $this->run_reset($course, ['reset_playerpuzzle_userdata' => 0]);
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id]));
        $this->assertSame(25, user_stock::get_quantity((int) $student->id, (int) $instance->id, user_stock::CURRENCY_TYPE));

        $this->run_reset($course, ['reset_playerpuzzle_userdata' => 1]);
        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id]));
        $this->assertSame(1, $DB->count_records('playerpuzzle_questions', ['playerpuzzleid' => $instance->id]));
    }

    /**
     * Resetting one course never touches the same activity in another.
     *
     * @return void
     */
    public function test_reset_does_not_touch_another_course(): void {
        global $DB;

        $coursea = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $courseb = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $coursea->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $courseb->id, 'student');
        $this->create_activity_with_progress($coursea, $student);
        $instanceb = $this->create_activity_with_progress($courseb, $student);

        $this->run_reset($coursea, ['reset_playerpuzzle_userdata' => 1]);

        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instanceb->id]));
        $this->assertSame(25, user_stock::get_quantity((int) $student->id, (int) $instanceb->id, user_stock::CURRENCY_TYPE));
        $this->assertEquals(80.0, $this->gradebook_grade($courseb, $instanceb, $student));
    }

    /**
     * The reset form offers the option, ticked by default.
     *
     * @return void
     */
    public function test_the_form_offers_the_option_ticked_by_default(): void {
        $mform = new \MoodleQuickForm('reset', 'post', '');

        playerpuzzle_reset_course_form_definition($mform);

        $this->assertTrue($mform->elementExists('reset_playerpuzzle_userdata'));
        $this->assertSame(
            ['reset_playerpuzzle_userdata' => 1],
            playerpuzzle_reset_course_form_defaults($this->getDataGenerator()->create_course())
        );
    }
}
