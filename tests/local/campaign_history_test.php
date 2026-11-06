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
 * Tests for the student's Campaign history.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for campaign_history: one row for each Campaign, the latest first, from what the attempts record.
 *
 * @covers \mod_playerpuzzle\local\campaign_history
 * @covers \mod_playerpuzzle\local\campaigns
 * @covers \mod_playerpuzzle\local\user_hero
 */
final class campaign_history_test extends \advanced_testcase {
    /** @var \stdClass Activity instance: two levels, three attempts a Campaign. */
    private \stdClass $instance;

    /** @var \stdClass The student whose history is read. */
    private \stdClass $student;

    /** @var \stdClass Another student, whose attempts must never show. */
    private \stdClass $other;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')->create_instance([
            'course' => $course->id,
            'maxlevels' => 2,
            'maxattempts' => 3,
            'minquestions' => 0,
        ]);
    }

    /**
     * Inserts an attempt.
     *
     * @param string $status Its status.
     * @param int $campaign Campaign number.
     * @param int $level Level it is on or ended on.
     * @param int $phase Phase it is on or ended on.
     * @param array $extra Other columns to set (userid, isdemo, timecreated, timefinished).
     * @return void
     */
    private function attempt(string $status, int $campaign, int $level, int $phase, array $extra = []): void {
        global $DB;

        $DB->insert_record('playerpuzzle_attempts', (object) ($extra + [
            'playerpuzzleid' => $this->instance->id,
            'userid' => $this->student->id,
            'token' => bin2hex(random_bytes(32)),
            'status' => $status,
            'campaign' => $campaign,
            'currentlevel' => $level,
            'currentphase' => $phase,
            'isdemo' => 0,
            'timecreated' => time(),
            'timefinished' => $status === 'inprogress' ? 0 : time(),
        ]));
    }

    /**
     * Reads the history of the student.
     *
     * @return array The rows, latest first.
     */
    private function rows(): array {
        return campaign_history::context($this->instance, (int) $this->student->id)['historyrows'];
    }

    /**
     * Tests that a student with no Campaign has no rows.
     *
     * @return void
     */
    public function test_a_student_with_no_campaign_has_nothing_to_list(): void {
        $context = campaign_history::context($this->instance, (int) $this->student->id);

        $this->assertSame([], $context['historyrows']);
        $this->assertFalse($context['historyhasrows']);
    }

    /**
     * Tests the three states of a Campaign, the latest Campaign first, and what each row says.
     *
     * @return void
     */
    public function test_each_campaign_has_its_state_and_the_latest_comes_first(): void {
        // Campaign 1: won on the last phase (level 2, phase 10), after a defeat.
        $this->attempt('lost', 1, 1, 4);
        $this->attempt('won', 1, 2, 10);
        // Campaign 2: three defeats, the attempts it had.
        $this->attempt('lost', 2, 1, 2);
        $this->attempt('lost', 2, 1, 3);
        $this->attempt('lost', 2, 1, 3);
        // Campaign 3: one attempt under way on level 1, phase 5.
        $this->attempt('inprogress', 3, 1, 5);

        $rows = $this->rows();

        $this->assertSame(
            array_map(fn(int $n): string => get_string('lobby_campaignname', 'mod_playerpuzzle', $n), [3, 2, 1]),
            array_column($rows, 'title')
        );
        $this->assertSame(
            [
                get_string('history_status_current', 'mod_playerpuzzle'),
                get_string('history_status_spent', 'mod_playerpuzzle'),
                get_string('history_status_won', 'mod_playerpuzzle'),
            ],
            array_column($rows, 'statustext')
        );
        $this->assertSame(
            ['pp-history-current', 'pp-history-spent', 'pp-history-won'],
            array_column($rows, 'statusclass')
        );

        // Phases won: the one under way has cleared the four before it, the lost ones the phases before theirs.
        $total = 20;
        $this->assertSame(
            get_string('history_phases', 'mod_playerpuzzle', (object) ['won' => 4, 'total' => $total]),
            $rows[0]['phasestext']
        );
        $this->assertSame(
            get_string('history_phases', 'mod_playerpuzzle', (object) ['won' => 2, 'total' => $total]),
            $rows[1]['phasestext']
        );
        $this->assertSame(
            get_string('history_phases', 'mod_playerpuzzle', (object) ['won' => $total, 'total' => $total]),
            $rows[2]['phasestext']
        );

        // Attempts used count the ones that ended, against the three each Campaign has.
        $this->assertSame(
            get_string('history_attempts_of', 'mod_playerpuzzle', (object) ['used' => 0, 'max' => 3]),
            $rows[0]['attemptstext']
        );
        $this->assertSame(
            get_string('history_attempts_of', 'mod_playerpuzzle', (object) ['used' => 3, 'max' => 3]),
            $rows[1]['attemptstext']
        );
        $this->assertSame(
            get_string('history_attempts_of', 'mod_playerpuzzle', (object) ['used' => 2, 'max' => 3]),
            $rows[2]['attemptstext']
        );
    }

    /**
     * Tests that a Campaign completed when the activity had fewer levels still reads as complete, in its own phases
     * and in points, while one lost on the same phase is measured against the levels the activity has now.
     *
     * @return void
     */
    public function test_a_campaign_completed_before_levels_were_added_stays_complete(): void {
        // The activity has two levels now, but Campaign 1 was won on level 1's last phase.
        $this->attempt('won', 1, 1, 10);
        $this->attempt('lost', 2, 1, 10);

        $rows = $this->rows();

        $this->assertSame(
            get_string('history_phases', 'mod_playerpuzzle', (object) ['won' => 10, 'total' => 10]),
            $rows[1]['phasestext']
        );
        $this->assertSame(
            get_string('history_points', 'mod_playerpuzzle', format_float(100, 1, true, true)),
            $rows[1]['pointstext']
        );
        $this->assertSame(
            get_string('history_phases', 'mod_playerpuzzle', (object) ['won' => 9, 'total' => 20]),
            $rows[0]['phasestext']
        );
    }

    /**
     * Tests the points: the ones the ranking counts for a Campaign, and a whole Campaign is worth a hundred.
     *
     * @return void
     */
    public function test_the_points_are_the_rankings(): void {
        $this->attempt('won', 1, 2, 10);
        $this->attempt('lost', 2, 1, 10);

        $rows = $this->rows();

        $this->assertSame(
            get_string('history_points', 'mod_playerpuzzle', format_float(100, 1, true, true)),
            $rows[1]['pointstext']
        );
        // Campaign 2 lost on level 1, phase 10: nine phases of twenty, nine twentieths of a hundred.
        $this->assertSame(
            get_string('history_points', 'mod_playerpuzzle', format_float(45, 1, true, true)),
            $rows[0]['pointstext']
        );
    }

    /**
     * Tests that the hero of a Campaign comes with its row, and that a Campaign without one still lists.
     *
     * @return void
     */
    public function test_a_campaign_shows_its_hero(): void {
        $this->attempt('lost', 1, 1, 2);
        $this->attempt('lost', 2, 1, 2);
        user_hero::choose((int) $this->instance->id, (int) $this->student->id, 2, 'archerf');

        $rows = $this->rows();

        $this->assertTrue($rows[0]['herohas']);
        $this->assertSame(get_string('hero_name_archerf', 'mod_playerpuzzle'), $rows[0]['heroname']);
        $this->assertStringContainsString('/pix/heroes/archerf1.webp', $rows[0]['heroimageurl']);
        $this->assertSame(
            get_string('shop_hero_stage', 'mod_playerpuzzle', (object) ['stage' => 1, 'total' => heroes::STAGES]),
            $rows[0]['herostagetext']
        );
        $this->assertFalse($rows[1]['herohas']);
        $this->assertArrayNotHasKey('heroname', $rows[1]);
    }

    /**
     * Tests that an activity with no attempt limit says how many were used, without a maximum.
     *
     * @return void
     */
    public function test_unlimited_attempts_show_only_the_count(): void {
        global $DB;

        $DB->set_field('playerpuzzle', 'maxattempts', 0, ['id' => $this->instance->id]);
        $this->instance->maxattempts = 0;
        $this->attempt('lost', 1, 1, 2);
        $this->attempt('lost', 1, 1, 2);

        $this->assertSame(
            get_string('history_attempts', 'mod_playerpuzzle', 2),
            $this->rows()[0]['attemptstext']
        );
    }

    /**
     * Tests that a Demo and another student's attempts never show up in the history.
     *
     * @return void
     */
    public function test_demos_and_other_students_are_left_out(): void {
        $this->attempt('lost', 1, 1, 2);
        $this->attempt('won', 1, 2, 10, ['isdemo' => 1]);
        $this->attempt('won', 2, 2, 10, ['userid' => $this->other->id]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(get_string('lobby_campaignname', 'mod_playerpuzzle', 1), $rows[0]['title']);
        $this->assertSame(get_string('history_status_current', 'mod_playerpuzzle'), $rows[0]['statustext']);
    }

    /**
     * Tests the dates: the day a Campaign was played when it ended the same day, and the period when it did not.
     *
     * @return void
     */
    public function test_the_dates_name_the_day_or_the_period(): void {
        $format = get_string('strftimedatefullshort', 'langconfig');
        $start = strtotime('2026-10-01 12:00:00');
        $end = strtotime('2026-10-04 12:00:00');
        $this->attempt('lost', 1, 1, 2, ['timecreated' => $start, 'timefinished' => $start]);
        $this->attempt('won', 1, 2, 10, ['timecreated' => $start, 'timefinished' => $end]);
        $this->attempt('lost', 2, 1, 2, ['timecreated' => $start, 'timefinished' => $start]);
        $this->attempt('inprogress', 3, 1, 2, ['timecreated' => $end]);

        $rows = $this->rows();

        $this->assertSame(userdate($end, $format), $rows[0]['datestext']);
        $this->assertSame(userdate($start, $format), $rows[1]['datestext']);
        $this->assertSame(
            get_string('history_period', 'mod_playerpuzzle', (object) [
                'from' => userdate($start, $format),
                'to' => userdate($end, $format),
            ]),
            $rows[2]['datestext']
        );
    }
}
