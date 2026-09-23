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
 * Unit tests for the student-facing ranking.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for ranking_service — requires database.
 *
 * @covers \mod_playerpuzzle\local\ranking_service
 */
final class ranking_service_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');

        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Creates an instance and returns it with its course module record.
     *
     * @param array $overrides Instance field overrides.
     * @return array [\stdClass instance, \stdClass cm]
     */
    private function make_instance(array $overrides = []): array {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(array_merge([
            'course' => $this->course->id,
            'gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'maxlevels' => 1,
            'minquestions' => 0,
            'considererrors' => 0,
        ], $overrides));
        $instance = $DB->get_record('playerpuzzle', ['id' => $instance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id, $this->course->id, false, MUST_EXIST);

        return [$instance, $cm];
    }

    /**
     * Creates and enrols a student.
     *
     * @param string $firstname First name, also used to find the row.
     * @return \stdClass
     */
    private function make_student(string $firstname): \stdClass {
        $user = $this->getDataGenerator()->create_user(['firstname' => $firstname, 'lastname' => 'Test']);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');

        return $user;
    }

    /**
     * Inserts one attempt.
     *
     * @param \stdClass $instance Activity instance.
     * @param \stdClass $user Attempt owner.
     * @param array $overrides Fields to override on top of the defaults.
     * @return void
     */
    private function make_attempt(\stdClass $instance, \stdClass $user, array $overrides = []): void {
        global $DB;

        $DB->insert_record('playerpuzzle_attempts', (object) array_merge([
            'playerpuzzleid' => $instance->id,
            'userid' => $user->id,
            'token' => bin2hex(random_bytes(32)),
            'currentlevel' => 1,
            'currentphase' => 1,
            'difficulty' => 'normal',
            'status' => 'inprogress',
            'timecreated' => 1000,
            'timefinished' => 0,
        ], $overrides));
    }

    /**
     * Returns the ranking's rows as [fullname => points] in order.
     *
     * @param array $ranking The get_ranking() result.
     * @return array
     */
    private function standings(array $ranking): array {
        return array_column($ranking['rows'], 'points', 'fullname');
    }

    /**
     * Tests that Campaign ranks by how far each winning streak reached, on a 100-point base
     * however the activity is graded — here "No grade" (0) — and that merely opening the game
     * without winning or finishing anything does not rank a student.
     *
     * @return void
     */
    public function test_campaign_ranks_by_progress_on_its_own_base(): void {
        [$instance, $cm] = $this->make_instance(['grade' => 0]);
        $ana = $this->make_student('Ana');
        $bruno = $this->make_student('Bruno');
        $idle = $this->make_student('Idle');
        // Ana is on phase 4, so she won 3 of 10; Bruno finished the whole campaign.
        $this->make_attempt($instance, $ana, ['currentphase' => 4]);
        $this->make_attempt($instance, $bruno, ['currentphase' => 10, 'status' => 'won', 'timefinished' => 2000]);
        $this->make_attempt($instance, $idle);

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $ana->id);

        $this->assertSame([fullname($bruno) => '100', fullname($ana) => '30'], $this->standings($ranking));
        $this->assertTrue($ranking['rows'][1]['iscurrentuser']);
    }

    /**
     * Tests that Campaign blends question accuracy in when errors count, exactly like the
     * grade: 30% progress and 50% accuracy rank at 40.
     *
     * @return void
     */
    public function test_campaign_blends_accuracy_when_errors_count(): void {
        [$instance, $cm] = $this->make_instance(['minquestions' => 1, 'considererrors' => 1]);
        $ana = $this->make_student('Ana');
        $this->make_attempt($instance, $ana, ['currentphase' => 4, 'questions_correct' => 2, 'questions_total' => 4]);

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $ana->id);

        $this->assertSame([fullname($ana) => '40'], $this->standings($ranking));
    }

    /**
     * Tests that a Campaign tie goes to whoever needed fewer attempts.
     *
     * @return void
     */
    public function test_campaign_tie_goes_to_fewer_attempts(): void {
        [$instance, $cm] = $this->make_instance();
        $retrier = $this->make_student('Aaron');
        $steady = $this->make_student('Zoe');
        $this->make_attempt($instance, $retrier, ['currentphase' => 3, 'status' => 'lost', 'timefinished' => 1500]);
        $this->make_attempt($instance, $retrier, ['currentphase' => 3, 'status' => 'lost', 'timefinished' => 1600]);
        $this->make_attempt($instance, $steady, ['currentphase' => 3, 'status' => 'lost', 'timefinished' => 1500]);

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $steady->id);

        $this->assertSame([fullname($steady), fullname($retrier)], array_column($ranking['rows'], 'fullname'));
    }

    /**
     * Tests that Single Match adds up every finished match — each win worth 100, weighted by
     * that match's accuracy when errors count, each loss worth 0 — and ignores a match still
     * in progress.
     *
     * @return void
     */
    public function test_single_match_sums_finished_matches(): void {
        [$instance, $cm] = $this->make_instance([
            'gamemode' => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'minquestions' => 1,
            'considererrors' => 1,
        ]);
        $ana = $this->make_student('Ana');
        $won = ['status' => 'won', 'timefinished' => 1100];
        $this->make_attempt($instance, $ana, $won + ['questions_correct' => 3, 'questions_total' => 4]);
        $this->make_attempt($instance, $ana, $won + ['questions_correct' => 1, 'questions_total' => 2]);
        $this->make_attempt($instance, $ana, ['status' => 'lost', 'timefinished' => 1100]);
        $this->make_attempt($instance, $ana, ['status' => 'inprogress']);

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $ana->id);

        $this->assertSame([fullname($ana) => '125'], $this->standings($ranking));
    }

    /**
     * Tests Single Match tie-breaks: fewer matches first, then less time played.
     *
     * @return void
     */
    public function test_single_match_ties_go_to_fewer_matches_then_less_time(): void {
        [$instance, $cm] = $this->make_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_SINGLE]);
        $slow = $this->make_student('Aaron');
        $fast = $this->make_student('Bia');
        $busy = $this->make_student('Caio');
        $this->make_attempt($instance, $slow, ['status' => 'won', 'timecreated' => 1000, 'timefinished' => 1900]);
        $this->make_attempt($instance, $fast, ['status' => 'won', 'timecreated' => 1000, 'timefinished' => 1300]);
        $this->make_attempt($instance, $busy, ['status' => 'won', 'timecreated' => 1000, 'timefinished' => 1100]);
        $this->make_attempt($instance, $busy, ['status' => 'lost', 'timecreated' => 1000, 'timefinished' => 1100]);

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $fast->id);

        $this->assertSame([fullname($fast), fullname($slow), fullname($busy)], array_column($ranking['rows'], 'fullname'));
    }

    /**
     * Tests that Demo matches and staff never appear, whatever they played.
     *
     * @return void
     */
    public function test_demo_matches_and_staff_are_left_out(): void {
        [$instance, $cm] = $this->make_instance();
        $ana = $this->make_student('Ana');
        $demoonly = $this->make_student('Demo');
        $teacher = $this->getDataGenerator()->create_user(['firstname' => 'Teacher']);
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->make_attempt($instance, $ana, ['currentphase' => 2]);
        $this->make_attempt($instance, $demoonly, ['currentphase' => 10, 'status' => 'won', 'timefinished' => 2000, 'isdemo' => 1]);
        $this->make_attempt($instance, $teacher, ['currentphase' => 10, 'status' => 'won', 'timefinished' => 2000]);

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $ana->id);

        $this->assertSame([fullname($ana) => '10'], $this->standings($ranking));
    }

    /**
     * Tests that with separate groups a student only sees their own group.
     *
     * @return void
     */
    public function test_separate_groups_only_show_the_viewers_group(): void {
        global $DB;

        [$instance, $cm] = $this->make_instance();
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id, $this->course->id, false, MUST_EXIST);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $viewer = $this->make_student('Viewer');
        $mate = $this->make_student('Mate');
        $other = $this->make_student('Other');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $viewer->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $mate->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $other->id]);
        foreach ([$viewer, $mate, $other] as $user) {
            $this->make_attempt($instance, $user, ['currentphase' => 2]);
        }

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $viewer->id);

        $names = array_column($ranking['rows'], 'fullname');
        $this->assertCount(2, $names);
        $this->assertNotContains(fullname($other), $names);
    }

    /**
     * Tests that the top rows are capped, and a viewer ranked below them still sees their own
     * row separately.
     *
     * @return void
     */
    public function test_viewer_outside_the_top_gets_their_own_row(): void {
        [$instance, $cm] = $this->make_instance();
        for ($i = 0; $i < ranking_service::TOP_N; $i++) {
            $this->make_attempt($instance, $this->make_student('Top' . $i), ['currentphase' => 6]);
        }
        $viewer = $this->make_student('Viewer');
        $this->make_attempt($instance, $viewer, ['currentphase' => 2]);

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $viewer->id);

        $this->assertCount(ranking_service::TOP_N, $ranking['rows']);
        $this->assertTrue($ranking['hasoutsider']);
        $this->assertSame(ranking_service::TOP_N + 1, $ranking['outsiderrow']['position']);
        $this->assertTrue($ranking['outsiderrow']['iscurrentuser']);
    }

    /**
     * Tests that an activity nobody has played yet gives an empty ranking.
     *
     * @return void
     */
    public function test_empty_when_nobody_played(): void {
        [$instance, $cm] = $this->make_instance();
        $ana = $this->make_student('Ana');

        $ranking = ranking_service::get_ranking($instance, $cm, (int) $ana->id);

        $this->assertTrue($ranking['isempty']);
        $this->assertSame([], $ranking['rows']);
        $this->assertFalse($ranking['hasoutsider']);
    }
}
