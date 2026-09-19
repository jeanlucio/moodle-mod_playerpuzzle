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
 * Unit tests for report_service.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for report_service — requires database.
 *
 * @covers \mod_playerpuzzle\local\report_service
 */
final class report_service_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Activity instance used by the tests, with ->cmid and ->id. */
    private \stdClass $instance;

    /** @var \stdClass Real course_modules record for the instance. */
    private \stdClass $cm;

    /** @var \context_module Module context for the instance. */
    private \context_module $context;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');

        $this->course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $this->instance = $generator->create_instance([
            'course'      => $this->course->id,
            'basebosshp'  => 1000,
            'maxlevels'   => 1,
        ]);
        $this->cm = get_coursemodule_from_instance(
            'playerpuzzle',
            $this->instance->id,
            $this->course->id,
            false,
            MUST_EXIST
        );
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Enrols a user as a student.
     *
     * @param \stdClass $user User to enrol.
     * @return void
     */
    private function enrol_student(\stdClass $user): void {
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
    }

    /**
     * Inserts one attempt record.
     *
     * @param \stdClass $user Attempt owner.
     * @param array $overrides Fields to override on top of the defaults.
     * @return int New attempt id.
     */
    private function make_attempt(\stdClass $user, array $overrides = []): int {
        global $DB;

        $record = array_merge([
            'playerpuzzleid'    => $this->instance->id,
            'userid'            => $user->id,
            'token'             => bin2hex(random_bytes(32)),
            'currentlevel'      => 1,
            'currentphase'      => 1,
            'difficulty'        => 'normal',
            'bosshp_remaining'  => 0,
            'questions_correct' => 0,
            'questions_total'   => 0,
            'score'             => 0,
            'status'            => 'inprogress',
            'timecreated'       => time(),
            'timefinished'      => 0,
        ], $overrides);

        return $DB->insert_record('playerpuzzle_attempts', (object) $record);
    }

    /**
     * A course with no enrolled students yields an empty report.
     *
     * @return void
     */
    public function test_get_student_rows_is_empty_without_students(): void {
        $rows = report_service::get_student_rows($this->instance, $this->cm, $this->context, 0);

        $this->assertSame([], $rows);
    }

    /**
     * A student with zero attempts still gets a row, showing they have not engaged yet.
     *
     * @return void
     */
    public function test_get_student_rows_includes_a_student_with_no_attempts(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);

        $rows = report_service::get_student_rows($this->instance, $this->cm, $this->context, 0);

        $this->assertCount(1, $rows);
        $this->assertSame(fullname($student), $rows[0]['fullname']);
        $this->assertFalse($rows[0]['hasgrade']);
        $this->assertSame('-', $rows[0]['grade']);
        $this->assertSame(0, $rows[0]['attemptsused']);
        $this->assertSame('-', $rows[0]['lastmatch']);
    }

    /**
     * A student who won the whole Campaign (all configured phases) gets the maximum
     * grade and a computed average boss-damage percentage.
     *
     * @return void
     */
    public function test_get_student_rows_computes_grade_and_damage_for_a_won_campaign(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);

        $this->make_attempt($student, [
            'currentlevel'      => 1,
            'currentphase'      => 10,
            'status'            => 'won',
            'bosshp_remaining'  => 0,
            'questions_correct' => 4,
            'questions_total'   => 5,
            'timefinished'      => time(),
        ]);

        $rows = report_service::get_student_rows($this->instance, $this->cm, $this->context, 0);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['hasgrade']);
        $this->assertSame(format_float(100.0, 2), $rows[0]['grade']);
        $this->assertSame(1, $rows[0]['attemptsused']);
        // Boss HP fully depleted (bosshp_remaining = 0) means 100% damage dealt.
        $this->assertSame(format_float(100.0, 1), $rows[0]['avgdamagepercent']);
        $this->assertSame(format_float(4.0, 1), $rows[0]['avgcorrect']);
        $this->assertSame(format_float(5.0, 1), $rows[0]['avgtotal']);
        $this->assertNotSame('-', $rows[0]['lastmatch']);
    }

    /**
     * An attempt still 'inprogress' is not counted towards attemptsused or the damage/
     * question averages — only finished attempts represent something the student
     * actually completed.
     *
     * @return void
     */
    public function test_get_student_rows_ignores_inprogress_attempts(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);
        $this->make_attempt($student, ['status' => 'inprogress']);

        $rows = report_service::get_student_rows($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(0, $rows[0]['attemptsused']);
        $this->assertSame('-', $rows[0]['avgdamagepercent']);
    }

    /**
     * A finished Demo attempt never appears in the teacher report — it is a
     * disposable practice fight, not a real submission.
     *
     * @return void
     */
    public function test_get_student_rows_excludes_demo_attempts(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);
        $this->make_attempt($student, [
            'status'       => 'won',
            'timefinished' => time(),
            'isdemo'       => 1,
        ]);

        $rows = report_service::get_student_rows($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(0, $rows[0]['attemptsused']);
        $this->assertFalse($rows[0]['hasgrade']);
        $this->assertSame('-', $rows[0]['lastmatch']);
    }

    /**
     * A teacher (holder of the viewreport capability) is never listed as a student in
     * their own report, even if they happen to have attempts of their own.
     *
     * @return void
     */
    public function test_get_student_rows_excludes_report_viewers(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->make_attempt($teacher, ['status' => 'won', 'timefinished' => time()]);

        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);

        $rows = report_service::get_student_rows($this->instance, $this->cm, $this->context, 0);

        $this->assertCount(1, $rows);
        $this->assertSame(fullname($student), $rows[0]['fullname']);
    }

    /**
     * With SEPARATEGROUPS, the report only includes members of the current viewer's
     * own group, never students from a different group.
     *
     * @return void
     */
    public function test_get_student_rows_separategroups_filters_by_group_membership(): void {
        global $DB;

        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_instance(
            'playerpuzzle',
            $this->instance->id,
            $this->course->id,
            false,
            MUST_EXIST
        );

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);

        // The viewer is a plain enrolled student, deliberately not a teacher/manager —
        // those archetypes hold moodle/site:accessallgroups by default in this
        // environment's role config, which would bypass the SEPARATEGROUPS filter this
        // test exists to exercise. resolve_group_filter() itself does not care who is
        // asking beyond that capability; report.php is what actually gates the page on
        // mod/playerpuzzle:viewreport.
        $viewer = $this->getDataGenerator()->create_user(['firstname' => 'Viewer', 'lastname' => 'Student']);
        $samegroup = $this->getDataGenerator()->create_user(['firstname' => 'Same', 'lastname' => 'Group']);
        $othergroup = $this->getDataGenerator()->create_user(['firstname' => 'Other', 'lastname' => 'Group']);

        $this->enrol_student($viewer);
        $this->enrol_student($samegroup);
        $this->enrol_student($othergroup);

        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $viewer->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $samegroup->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $othergroup->id]);

        $rows = report_service::get_student_rows($this->instance, $this->cm, $this->context, (int) $viewer->id);

        $seennames = array_column($rows, 'fullname');
        $this->assertCount(2, $rows);
        $this->assertContains(fullname($viewer), $seennames);
        $this->assertContains(fullname($samegroup), $seennames);
        $this->assertNotContains(fullname($othergroup), $seennames);
    }

    /**
     * No logged questions yields an empty list.
     *
     * @return void
     */
    public function test_get_most_missed_questions_is_empty_without_questions(): void {
        $rows = report_service::get_most_missed_questions($this->instance, $this->cm, $this->context, 0);

        $this->assertSame([], $rows);
    }

    /**
     * Questions are ordered by error rate, most missed first.
     *
     * @return void
     */
    public function test_get_most_missed_questions_orders_by_error_rate_desc(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);
        $attemptid = $this->make_attempt($student);

        // Question 1: 1 of 2 wrong (50%).
        $this->log_question($attemptid, 1, 'Easy question', 1);
        $this->log_question($attemptid, 1, 'Easy question', 0);
        // Question 2: 2 of 2 wrong (100%).
        $this->log_question($attemptid, 2, 'Hard question', 0);
        $this->log_question($attemptid, 2, 'Hard question', 0);

        $rows = report_service::get_most_missed_questions($this->instance, $this->cm, $this->context, 0);

        $this->assertCount(2, $rows);
        $this->assertSame('Hard question', $rows[0]['questiontext']);
        $this->assertEqualsWithDelta(100.0, $rows[0]['errorrate'], 0.01);
        $this->assertSame('Easy question', $rows[1]['questiontext']);
        $this->assertEqualsWithDelta(50.0, $rows[1]['errorrate'], 0.01);
    }

    /**
     * With SEPARATEGROUPS, a viewer restricted to one group must never see error
     * statistics aggregated over a question only the other group answered.
     *
     * @return void
     */
    public function test_get_most_missed_questions_separategroups_filters_by_group_membership(): void {
        global $DB;

        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_instance(
            'playerpuzzle',
            $this->instance->id,
            $this->course->id,
            false,
            MUST_EXIST
        );

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);

        $viewer = $this->getDataGenerator()->create_user();
        $samegroup = $this->getDataGenerator()->create_user();
        $othergroup = $this->getDataGenerator()->create_user();

        $this->enrol_student($viewer);
        $this->enrol_student($samegroup);
        $this->enrol_student($othergroup);

        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $viewer->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $samegroup->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $othergroup->id]);

        // Group A only ever gets "Easy question" right; group B only ever answers
        // "Hard question", and always wrong. A viewer scoped to group A must not see
        // "Hard question" at all, since none of their own students ever answered it.
        $attemptidsamegroup = $this->make_attempt($samegroup);
        $this->log_question($attemptidsamegroup, 1, 'Easy question', 1);

        $attemptidothergroup = $this->make_attempt($othergroup);
        $this->log_question($attemptidothergroup, 2, 'Hard question', 0);

        $rows = report_service::get_most_missed_questions(
            $this->instance,
            $this->cm,
            $this->context,
            (int) $viewer->id
        );

        $seentexts = array_column($rows, 'questiontext');
        $this->assertContains('Easy question', $seentexts);
        $this->assertNotContains('Hard question', $seentexts);
    }

    /**
     * Inserts a question-log row for the given attempt.
     *
     * @param int $attemptid Attempt id.
     * @param int $questionid Source question id.
     * @param string $questiontext Snapshot question text.
     * @param int $iscorrect Whether the answer was correct.
     * @return void
     */
    private function log_question(int $attemptid, int $questionid, string $questiontext, int $iscorrect): void {
        global $DB;

        $DB->insert_record('playerpuzzle_attempt_questions', (object) [
            'attemptid'     => $attemptid,
            'questionid'    => $questionid,
            'attemptlevel'  => 1,
            'attemptphase'  => 1,
            'questiontext'  => $questiontext,
            'chosenanswer'  => 'x',
            'correctanswer' => 'y',
            'iscorrect'     => $iscorrect,
            'timecreated'   => time(),
        ]);
    }

    /**
     * Scores land in the correct fixed bucket at both boundaries.
     *
     * @return void
     */
    public function test_get_score_distribution_buckets_boundaries_correctly(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);

        foreach ([0, 20, 21, 40, 41, 60, 61, 80, 81, 100] as $score) {
            $this->make_attempt($student, ['status' => 'won', 'score' => $score, 'timefinished' => time()]);
        }

        $distribution = report_service::get_score_distribution($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(2, $distribution[0]['count']); // 0, 20.
        $this->assertSame(2, $distribution[1]['count']); // 21, 40.
        $this->assertSame(2, $distribution[2]['count']); // 41, 60.
        $this->assertSame(2, $distribution[3]['count']); // 61, 80.
        $this->assertSame(2, $distribution[4]['count']); // 81, 100.
    }

    /**
     * An inprogress attempt's score is never counted in the distribution.
     *
     * @return void
     */
    public function test_get_score_distribution_ignores_inprogress_attempts(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);
        $this->make_attempt($student, ['status' => 'inprogress', 'score' => 99]);

        $distribution = report_service::get_score_distribution($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(0, array_sum(array_column($distribution, 'count')));
    }

    /**
     * A finished Demo attempt's score is never counted in the class-wide
     * score distribution — it fought a fixed, disposable HP, not a real result.
     *
     * @return void
     */
    public function test_get_score_distribution_ignores_demo_attempts(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->enrol_student($student);
        $this->make_attempt($student, ['status' => 'won', 'score' => 99, 'timefinished' => time(), 'isdemo' => 1]);

        $distribution = report_service::get_score_distribution($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(0, array_sum(array_column($distribution, 'count')));
    }

    /**
     * Campaign mode: completion rate is the percentage of students who reached a
     * genuine 'won' status at least once.
     *
     * @return void
     */
    public function test_get_completion_rate_campaign_counts_won_attempts(): void {
        $winner = $this->getDataGenerator()->create_user();
        $loser = $this->getDataGenerator()->create_user();
        $this->enrol_student($winner);
        $this->enrol_student($loser);

        $this->make_attempt($winner, ['status' => 'won', 'timefinished' => time()]);
        $this->make_attempt($loser, ['status' => 'lost', 'timefinished' => time()]);

        $completion = report_service::get_completion_rate($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(1, $completion['completed']);
        $this->assertSame(2, $completion['total']);
        $this->assertEqualsWithDelta(50.0, $completion['percent'], 0.01);
    }

    /**
     * Single Match mode: completion rate is the percentage of students who have
     * finished at least one match, win or lose.
     *
     * @return void
     */
    public function test_get_completion_rate_single_match_counts_any_finished_attempt(): void {
        global $DB;
        $DB->set_field('playerpuzzle', 'gamemode', PLAYERPUZZLE_GAMEMODE_SINGLE, ['id' => $this->instance->id]);
        $this->instance->gamemode = PLAYERPUZZLE_GAMEMODE_SINGLE;

        $played = $this->getDataGenerator()->create_user();
        $neverplayed = $this->getDataGenerator()->create_user();
        $this->enrol_student($played);
        $this->enrol_student($neverplayed);

        $this->make_attempt($played, ['status' => 'lost', 'timefinished' => time()]);

        $completion = report_service::get_completion_rate($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(1, $completion['completed']);
        $this->assertSame(2, $completion['total']);
        $this->assertEqualsWithDelta(50.0, $completion['percent'], 0.01);
    }

    /**
     * A student who has only ever won a Demo match is never counted as
     * having completed the activity — a Demo win is not a real completion.
     *
     * @return void
     */
    public function test_get_completion_rate_ignores_demo_attempts(): void {
        $demoonly = $this->getDataGenerator()->create_user();
        $this->enrol_student($demoonly);
        $this->make_attempt($demoonly, ['status' => 'won', 'timefinished' => time(), 'isdemo' => 1]);

        $completion = report_service::get_completion_rate($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(0, $completion['completed']);
        $this->assertSame(1, $completion['total']);
    }

    /**
     * No students at all yields a zero-over-zero result, not a division-by-zero error.
     *
     * @return void
     */
    public function test_get_completion_rate_is_zero_without_students(): void {
        $completion = report_service::get_completion_rate($this->instance, $this->cm, $this->context, 0);

        $this->assertSame(0, $completion['completed']);
        $this->assertSame(0, $completion['total']);
        $this->assertSame(0.0, $completion['percent']);
    }
}
