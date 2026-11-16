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
 * Tests for giving up a Campaign.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\security;

/**
 * Tests for campaign_giveup, through the real start of an attempt and the real reading of where the next
 * Campaign begins.
 *
 * @covers \mod_playerpuzzle\local\campaign_giveup
 */
final class campaign_giveup_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Creates a Campaign activity.
     *
     * @param array $record Settings that differ from the defaults.
     * @return \stdClass[] The instance and its course module.
     */
    private function make(array $record = []): array {
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')->create_instance(
            ['course' => $this->course->id, 'minquestions' => 0] + $record
        );

        return [$instance, get_coursemodule_from_instance('playerpuzzle', $instance->id)];
    }

    /**
     * Inserts a finished real attempt.
     *
     * @param \stdClass $instance Activity instance.
     * @param string $status Final status.
     * @param int $campaign Campaign number.
     * @param int $phase Phase it ended on.
     * @param int|null $userid Whose, the student by default.
     * @return int The attempt ID.
     */
    private function finish(\stdClass $instance, string $status, int $campaign, int $phase, ?int $userid = null): int {
        global $DB;

        return $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid' => $userid ?? $this->student->id,
            'token' => bin2hex(random_bytes(32)),
            'status' => $status,
            'campaign' => $campaign,
            'currentlevel' => 1,
            'currentphase' => $phase,
            'timecreated' => time(),
            'timefinished' => time(),
        ]);
    }

    /**
     * Tests that nothing can be given up before a Campaign is under way: no hero, a hero without an attempt, a
     * Single Match, and a Campaign that already ended and has not begun the next.
     *
     * @return void
     */
    public function test_nothing_to_give_up_before_a_campaign_is_under_way(): void {
        [$instance] = $this->make();
        [$single] = $this->make(['gamemode' => constants::GAMEMODE_SINGLE]);
        $userid = (int) $this->student->id;

        $this->assertFalse(campaign_giveup::is_available($instance, $userid), 'no hero');

        game_page_service::choose_campaign_hero($instance, $userid, 'magef');
        $this->assertFalse(campaign_giveup::is_available($instance, $userid), 'a hero but no attempt');

        $this->finish($instance, 'lost', 1, 3);
        $this->assertTrue(campaign_giveup::is_available($instance, $userid));
        $this->assertFalse(campaign_giveup::is_available($single, $userid), 'a Single Match has no Campaign');

        $this->finish($instance, 'won', 1, 10);
        $this->assertFalse(campaign_giveup::is_available($instance, $userid), 'the Campaign ended, the next has no hero');
    }

    /**
     * Tests giving up a Campaign whose attempts are all finished: the mark goes on the last one, which keeps its
     * own status, the Campaign counts as ended and the next Play starts a new one.
     *
     * @return void
     */
    public function test_giving_up_marks_the_last_finished_attempt(): void {
        global $DB;

        [$instance, $cm] = $this->make();
        $userid = (int) $this->student->id;
        $other = (int) $this->getDataGenerator()->create_and_enrol($this->course, 'student')->id;
        game_page_service::choose_campaign_hero($instance, $userid, 'magef');
        game_page_service::choose_campaign_hero($instance, $other, 'archerf');
        $first = $this->finish($instance, 'lost', 1, 3);
        $last = $this->finish($instance, 'lost', 1, 5);
        $this->finish($instance, 'lost', 1, 2, $other);

        $this->assertTrue(campaign_giveup::give_up($instance, $cm, $userid));

        $this->assertSame('1', $DB->get_field('playerpuzzle_attempts', 'gaveup', ['id' => $last]));
        $this->assertSame('lost', $DB->get_field('playerpuzzle_attempts', 'status', ['id' => $last]));
        $this->assertSame('0', $DB->get_field('playerpuzzle_attempts', 'gaveup', ['id' => $first]));
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['gaveup' => 1]), 'another student is untouched');
        $this->assertSame(campaigns::ENDED_GAVEUP, game_page_service::next_campaign_start($instance, $userid)->ended);
        $this->assertSame(game_page_service::HERO_NEW, game_page_service::hero_stage($instance, $userid));
        $this->assertSame(game_page_service::HERO_NONE, game_page_service::hero_stage($instance, $other));
        $this->assertFalse(campaign_giveup::is_available($instance, $userid), 'it cannot be given up twice');
        $this->assertFalse(campaign_giveup::give_up($instance, $cm, $userid));
    }

    /**
     * Tests giving up while an attempt is open, started the way the game starts one: the attempt is closed as
     * abandoned, not lost, with the mark, and the token it carried no longer works.
     *
     * @return void
     */
    public function test_giving_up_closes_the_open_attempt(): void {
        global $DB;

        [$instance, $cm] = $this->make(['maxlevels' => 2]);
        $userid = (int) $this->student->id;
        game_page_service::choose_campaign_hero($instance, $userid, 'archerm');
        $token = security::generate_attempt_token((int) $instance->id, $userid);
        $this->assertTrue(campaign_giveup::is_available($instance, $userid), 'an open attempt is a Campaign under way');

        $this->assertTrue(campaign_giveup::give_up($instance, $cm, $userid));

        $row = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(['abandoned', '1'], [$row->status, $row->gaveup]);
        $this->assertGreaterThan(0, (int) $row->timefinished);
        $this->assertSame(
            campaigns::ENDED_GAVEUP,
            campaigns::next_start((int) $instance->id, $userid, 2, 0)->ended
        );
        $this->assertFalse(security::has_inprogress_attempt((int) $instance->id, $userid));
    }

    /**
     * Tests that a new Campaign after giving one up starts the way any other does: it asks for a hero, which
     * empties the stock, and its attempts count in the next Campaign.
     *
     * @return void
     */
    public function test_the_next_campaign_starts_after_giving_up(): void {
        global $DB;

        [$instance, $cm] = $this->make();
        $userid = (int) $this->student->id;
        game_page_service::choose_campaign_hero($instance, $userid, 'magef');
        $this->finish($instance, 'lost', 1, 4);
        user_stock::credit($userid, (int) $instance->id, user_stock::CURRENCY_TYPE, 30);

        $this->assertTrue(campaign_giveup::give_up($instance, $cm, $userid));
        $this->assertSame(30, user_stock::get_quantity($userid, (int) $instance->id, user_stock::CURRENCY_TYPE));
        $this->assertSame(
            hero_page_service::CHOICE_SAVED,
            hero_page_service::choose_for_play($instance, $userid, 'warriorm', true)
        );

        $this->assertSame(0, user_stock::get_quantity($userid, (int) $instance->id, user_stock::CURRENCY_TYPE));
        $this->assertSame('warriorm', user_hero::get((int) $instance->id, $userid, 2)->hero);
        $this->assertSame('magef', user_hero::get((int) $instance->id, $userid, 1)->hero);
        $this->assertTrue($DB->record_exists('playerpuzzle_attempts', ['gaveup' => 1, 'campaign' => 1]));
    }

    /**
     * Tests that the last Campaign an activity allows is told apart, so that the confirmation can say giving it
     * up ends the student's play for good, and that the activity then really allows no other.
     *
     * @return void
     */
    public function test_the_last_campaign_is_told_apart(): void {
        [$unlimited, $unlimitedcm] = $this->make(['maxcampaigns' => 0]);
        [$one, $onecm] = $this->make(['maxcampaigns' => 1]);
        [$two] = $this->make(['maxcampaigns' => 2]);
        $userid = (int) $this->student->id;

        foreach ([$unlimited, $one, $two] as $instance) {
            game_page_service::choose_campaign_hero($instance, $userid, 'magef');
            $this->finish($instance, 'lost', 1, 3);
        }

        $this->assertFalse(campaign_giveup::is_last_campaign($unlimited, $userid));
        $this->assertTrue(campaign_giveup::is_last_campaign($one, $userid));
        $this->assertFalse(campaign_giveup::is_last_campaign($two, $userid));

        $this->assertTrue(campaign_giveup::give_up($one, $onecm, $userid));
        $this->assertSame('maxcampaignsreached', game_page_service::campaign_limit_refusal($one, $userid));
        $this->assertTrue(campaign_giveup::give_up($unlimited, $unlimitedcm, $userid));
        $this->assertNull(game_page_service::campaign_limit_refusal($unlimited, $userid));
    }
}
