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
 * Tests for playerpuzzle_update_grades() in lib.php.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for playerpuzzle_update_grades().
 *
 * @covers ::playerpuzzle_update_grades
 */
final class lib_update_grades_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
    }

    /**
     * Fetches the grade_item for the given instance, requiring it to exist.
     *
     * @param \stdClass $instance Activity instance.
     * @return \grade_item
     */
    private function fetch_grade_item(\stdClass $instance): \grade_item {
        return \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'playerpuzzle',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
    }

    /**
     * Inserts an attempt row, with sensible defaults merged with per-test overrides.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @param array $overrides Field overrides.
     * @return int Inserted attempt ID.
     */
    private function make_attempt(int $instanceid, int $userid, array $overrides = []): int {
        global $DB;
        return $DB->insert_record('playerpuzzle_attempts', (object) array_merge([
            'playerpuzzleid'    => $instanceid,
            'userid'            => $userid,
            'token'             => bin2hex(random_bytes(32)),
            'currentlevel'      => 1,
            'currentphase'      => 1,
            'status'            => 'inprogress',
            'questions_correct' => 0,
            'questions_total'   => 0,
            'timecreated'       => time(),
            'timefinished'      => 0,
        ], $overrides));
    }

    /**
     * When a specific student's last attempt is removed (so the filtered query for that
     * userid finds nothing), their stale grade must actually be cleared from the
     * gradebook, not left stuck at whatever it was.
     *
     * @return void
     */
    public function test_update_grades_clears_grade_for_user_with_no_attempts_left(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course' => $course->id, 'grade' => 100, 'gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN, 'maxlevels' => 1,
        ]);
        $user = $this->getDataGenerator()->create_user();

        $attemptid = $this->make_attempt(
            $instance->id,
            $user->id,
            ['currentphase' => 10, 'status' => 'won', 'timefinished' => time()]
        );
        playerpuzzle_update_grades($instance, $user->id);

        $gradeitem = $this->fetch_grade_item($instance);
        $this->assertTrue($gradeitem->has_grades(), 'Precondition: the student must have a real grade first.');

        // Simulates the effect of deleting that student's only attempt.
        $DB->delete_records('playerpuzzle_attempts', ['id' => $attemptid]);
        playerpuzzle_update_grades($instance, $user->id);

        $gradeitem = $this->fetch_grade_item($instance);
        $this->assertFalse(
            $gradeitem->has_grades(),
            'The student\'s stale grade must be cleared once they have no attempts left.'
        );
    }

    /**
     * A student who still has other attempts after one is deleted gets their grade
     * recomputed from what remains, taking the furthest progress among them.
     *
     * @return void
     */
    public function test_update_grades_recomputes_when_attempts_remain(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course' => $course->id, 'grade' => 100, 'gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN, 'maxlevels' => 1,
        ]);
        $user = $this->getDataGenerator()->create_user();

        $furthest = $this->make_attempt(
            $instance->id,
            $user->id,
            ['currentphase' => 9, 'status' => 'lost', 'timefinished' => time()]
        );
        $this->make_attempt(
            $instance->id,
            $user->id,
            ['currentphase' => 3, 'status' => 'lost', 'timefinished' => time()]
        );
        playerpuzzle_update_grades($instance, $user->id);

        $gradeitem = $this->fetch_grade_item($instance);
        $grade = $gradeitem->get_grade($user->id, false);
        $this->assertEqualsWithDelta(80.0, (float) $grade->finalgrade, 0.001);

        // Delete the furthest-progress attempt — only the lower one remains.
        $DB->delete_records('playerpuzzle_attempts', ['id' => $furthest]);
        playerpuzzle_update_grades($instance, $user->id);

        $gradeitem = $this->fetch_grade_item($instance);
        $grade = $gradeitem->get_grade($user->id, false);
        $this->assertEqualsWithDelta(20.0, (float) $grade->finalgrade, 0.001);
    }

    /**
     * Tests that $userid = 0 recomputes grades for every user who has at least one
     * attempt on the instance.
     *
     * @return void
     */
    public function test_update_grades_updates_every_user_when_userid_is_zero(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course' => $course->id, 'grade' => 100, 'gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN, 'maxlevels' => 1,
        ]);
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $this->make_attempt($instance->id, $usera->id, ['currentphase' => 10, 'status' => 'won', 'timefinished' => 1]);
        $this->make_attempt($instance->id, $userb->id, ['currentphase' => 5, 'status' => 'lost', 'timefinished' => 1]);

        playerpuzzle_update_grades($instance);

        $gradeitem = $this->fetch_grade_item($instance);
        $this->assertEqualsWithDelta(100.0, (float) $gradeitem->get_grade($usera->id, false)->finalgrade, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $gradeitem->get_grade($userb->id, false)->finalgrade, 0.001);
    }
}
