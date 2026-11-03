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
 * Tests for the student's Campaigns.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\security;

/**
 * Tests for campaigns, and for the new Campaign that security::resume_or_create_attempt_token() starts.
 *
 * @covers \mod_playerpuzzle\local\campaigns
 * @covers \mod_playerpuzzle\local\engine\security
 */
final class campaigns_test extends \advanced_testcase {
    /** @var \stdClass Activity instance. */
    private \stdClass $instance;

    /** @var \stdClass Student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')->create_instance([
            'course' => $course->id,
            'maxlevels' => 2,
            'minquestions' => 0,
        ]);
    }

    /**
     * Inserts a finished attempt.
     *
     * @param string $status Final status.
     * @param int $campaign Campaign number.
     * @param int $level Level it ended on.
     * @param int $phase Phase it ended on.
     * @param int $isdemo Whether it is a Demo.
     * @return void
     */
    private function finish(string $status, int $campaign = 1, int $level = 1, int $phase = 1, int $isdemo = 0): void {
        global $DB;

        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $this->instance->id,
            'userid' => $this->student->id,
            'token' => bin2hex(random_bytes(32)),
            'status' => $status,
            'campaign' => $campaign,
            'currentlevel' => $level,
            'currentphase' => $phase,
            'isdemo' => $isdemo,
            'timecreated' => time(),
            'timefinished' => time(),
        ]);
    }

    /**
     * Where the next attempt starts.
     *
     * @param int $maxattempts Attempts allowed in each Campaign.
     * @return \stdClass
     */
    private function next(int $maxattempts = 0): \stdClass {
        return campaigns::next_start((int) $this->instance->id, (int) $this->student->id, 2, $maxattempts);
    }

    /**
     * Tests that the first attempt ever starts the first Campaign, without calling it a new one.
     *
     * @return void
     */
    public function test_next_start_with_no_attempt_is_the_first_campaign(): void {
        $start = $this->next();

        $this->assertSame([1, 1, 1, false, ''], [$start->campaign, $start->level, $start->phase, $start->startsnew, $start->ended]);
    }

    /**
     * Tests that after a defeat the same Campaign resumes on the phase lost, and a Demo changes nothing.
     *
     * @return void
     */
    public function test_next_start_after_a_defeat_resumes_the_same_campaign(): void {
        $this->finish('lost', 2, 2, 4);
        $this->finish('lost', 1, 1, 1, 1);

        $start = $this->next(3);

        $this->assertSame([2, 2, 4, false], [$start->campaign, $start->level, $start->phase, $start->startsnew]);
    }

    /**
     * Tests that a won Campaign is followed by the next one at Level 1, Phase 1.
     *
     * @return void
     */
    public function test_next_start_after_a_win_starts_the_next_campaign(): void {
        $this->finish('won', 1, 2, 10);

        $start = $this->next();

        $this->assertSame(
            [2, 1, 1, true, campaigns::ENDED_WON],
            [$start->campaign, $start->level, $start->phase, $start->startsnew, $start->ended]
        );
    }

    /**
     * Tests that spending the last attempt of a Campaign ends it, while unlimited attempts never do.
     *
     * @return void
     */
    public function test_next_start_out_of_attempts_starts_the_next_campaign(): void {
        $this->finish('lost', 1, 1, 3);
        $this->finish('lost', 1, 1, 3);

        $this->assertSame(1, $this->next(3)->campaign);
        $this->assertSame(1, $this->next(0)->campaign);
        $start = $this->next(2);
        $this->assertSame(
            [2, 1, 1, true, campaigns::ENDED_ATTEMPTS],
            [$start->campaign, $start->level, $start->phase, $start->startsnew, $start->ended]
        );
    }

    /**
     * Tests that a Campaign resumes at the start of the last level when the teacher removed the level
     * it had reached.
     *
     * @return void
     */
    public function test_next_start_clamps_a_removed_level(): void {
        $this->finish('lost', 1, 2, 7);

        $start = campaigns::next_start((int) $this->instance->id, (int) $this->student->id, 1, 0);

        $this->assertSame([1, 1, 1], [$start->campaign, $start->level, $start->phase]);
    }

    /**
     * Tests is_finished() and graded() on attempts held in memory.
     *
     * @return void
     */
    public function test_graded_keeps_the_first_and_the_ended_campaigns(): void {
        $instance = (object) ['maxattempts' => 2];
        $attempt = fn(int $campaign, string $status): \stdClass => (object) ['campaign' => $campaign, 'status' => $status];
        $attempts = [
            $attempt(1, 'lost'),
            $attempt(1, 'inprogress'),
            $attempt(2, 'won'),
            $attempt(3, 'lost'),
            $attempt(3, 'lost'),
            $attempt(4, 'lost'),
        ];

        $this->assertFalse(campaigns::is_finished($instance, [$attempts[0], $attempts[1]]));
        $this->assertSame([1, 2, 3], array_keys(campaigns::graded($instance, $attempts)));
        $this->assertSame([1, 2, 3, 4], array_keys(campaigns::group($attempts)));
    }

    /**
     * Tests that starting a new Campaign empties the student's PuzzleCoin and stock and numbers the
     * attempt, while resuming the first Campaign after a defeat keeps both.
     *
     * @return void
     */
    public function test_a_new_campaign_starts_with_an_empty_stock(): void {
        global $DB;

        $userid = (int) $this->student->id;
        $instanceid = (int) $this->instance->id;
        user_stock::credit($userid, $instanceid, user_stock::CURRENCY_TYPE, 40);
        user_stock::credit($userid, $instanceid, 'potion', 2);

        $this->finish('lost', 1, 1, 5);
        $resumed = security::resume_or_create_attempt_token($instanceid, $userid, 'normal', 2);
        $this->assertSame(1, (int) $DB->get_field('playerpuzzle_attempts', 'campaign', ['id' => $resumed->attemptid]));
        $this->assertSame(40, user_stock::get_quantity($userid, $instanceid, user_stock::CURRENCY_TYPE));
        $DB->set_field('playerpuzzle_attempts', 'status', 'won', ['id' => $resumed->attemptid]);

        $other = $this->getDataGenerator()->create_user();
        user_stock::credit((int) $other->id, $instanceid, user_stock::CURRENCY_TYPE, 7);

        $next = security::resume_or_create_attempt_token($instanceid, $userid, 'normal', 2);

        $this->assertSame(2, (int) $DB->get_field('playerpuzzle_attempts', 'campaign', ['id' => $next->attemptid]));
        $this->assertSame([1, 1], [$next->currentlevel, $next->currentphase]);
        $this->assertSame(0, user_stock::get_quantity($userid, $instanceid, user_stock::CURRENCY_TYPE));
        $this->assertSame(0, user_stock::get_quantity($userid, $instanceid, 'potion'));
        $this->assertSame(7, user_stock::get_quantity((int) $other->id, $instanceid, user_stock::CURRENCY_TYPE));
    }

    /**
     * Tests that a Single Match never leaves the first Campaign, however many matches are won.
     *
     * @return void
     */
    public function test_single_match_stays_on_the_first_campaign(): void {
        global $DB;

        $DB->set_field('playerpuzzle', 'gamemode', constants::GAMEMODE_SINGLE, ['id' => $this->instance->id]);
        $userid = (int) $this->student->id;
        $instanceid = (int) $this->instance->id;
        user_stock::credit($userid, $instanceid, user_stock::CURRENCY_TYPE, 40);
        $this->finish('won');

        $next = security::resume_or_create_attempt_token($instanceid, $userid);

        $this->assertSame(1, (int) $DB->get_field('playerpuzzle_attempts', 'campaign', ['id' => $next->attemptid]));
        $this->assertSame(40, user_stock::get_quantity($userid, $instanceid, user_stock::CURRENCY_TYPE));
    }
}
