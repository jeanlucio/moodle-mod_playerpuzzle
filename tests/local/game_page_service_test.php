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
 * Unit tests for game_page_service.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for game_page_service.
 *
 * @covers \mod_playerpuzzle\local\game_page_service
 * @covers \mod_playerpuzzle\local\question_results
 */
final class game_page_service_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Student used by every test. */
    private \stdClass $student;

    /** @var \moodle_url Dummy return URL used by every test. */
    private \moodle_url $returnurl;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->returnurl = new \moodle_url('/mod/playerpuzzle/view.php', ['id' => 1]);
    }

    /**
     * Creates a course module and instance for the given overrides.
     *
     * @param array $overrides Instance field overrides.
     * @return array{0: \stdClass, 1: \stdClass} [$cm, $instance]
     */
    private function make_cm_and_instance(array $overrides = []): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        // No minimum of questions by default: the generator's own default (3) would need approved
        // questions for every match these tests start (see the check_has_questions tests below).
        $record = array_merge(['course' => $this->course->id, 'minquestions' => 0], $overrides);
        $instance = $generator->create_instance($record);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        return [$cm, $instance];
    }

    /**
     * Inserts a finished attempt row for the given instance/user.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @param string $status Final status.
     * @param int $campaign Campaign the attempt belongs to.
     * @return void
     */
    private function make_finished_attempt(int $instanceid, int $userid, string $status = 'lost', int $campaign = 1): void {
        global $DB;
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instanceid,
            'userid'         => $userid,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => $status,
            'campaign'       => $campaign,
            'timecreated'    => time(),
            'timefinished'   => time(),
        ]);
    }

    /**
     * Inserts a finished attempt row with a specific timefinished, for cooldown tests.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @param int $timefinished Epoch the attempt is recorded as having finished.
     * @return void
     */
    private function make_finished_attempt_at(int $instanceid, int $userid, int $timefinished): void {
        global $DB;
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instanceid,
            'userid'         => $userid,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'lost',
            'timecreated'    => $timefinished,
            'timefinished'   => $timefinished,
        ]);
    }

    /**
     * Inserts a finished Demo attempt row for the given instance/user.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @return void
     */
    private function make_finished_demo_attempt(int $instanceid, int $userid): void {
        global $DB;
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instanceid,
            'userid'         => $userid,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'lost',
            'isdemo'         => 1,
            'timecreated'    => time(),
            'timefinished'   => time(),
        ]);
    }

    /**
     * Inserts a block_playerhud block instance and one item in the course, returning
     * both IDs.
     *
     * @return array{0: int, 1: int} [$blockinstanceid, $itemid]
     */
    private function make_hud_item(): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }

        $ctx = \context_course::instance($this->course->id);
        $biid = $DB->insert_record('block_instances', (object) [
            'blockname'         => 'playerhud',
            'parentcontextid'   => $ctx->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'   => 'course-view-*',
            'subpagepattern'    => null,
            'defaultregion'     => 'side-pre',
            'defaultweight'     => 0,
            'configdata'        => base64_encode(serialize(new \stdClass())),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
        $itemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $biid,
            'name'            => 'Retry Token',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        return [$biid, $itemid];
    }

    /**
     * Grants $qty units of a PlayerHUD item to a user, via the same stack table
     * external_items::grant() writes to.
     *
     * @param int $blockinstanceid Block instance ID.
     * @param int $itemid Item ID.
     * @param int $userid User ID.
     * @param int $qty Quantity to grant.
     * @return void
     */
    private function grant_hud_item(int $blockinstanceid, int $itemid, int $userid, int $qty): void {
        \block_playerhud\local\external_items::grant($blockinstanceid, $itemid, $userid, $qty, 'test', false);
    }

    /**
     * Tests that a limit of 0 (unlimited) never blocks, for either game mode.
     *
     * @return void
     */
    public function test_check_attempt_limit_zero_is_unlimited(): void {
        [, $campaign] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'maxattempts' => 0]);
        [, $single] = $this->make_cm_and_instance([
            'gamemode' => constants::GAMEMODE_SINGLE,
            'max_single_matches' => 0,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->make_finished_attempt($campaign->id, (int) $this->student->id);
            $this->make_finished_attempt($single->id, (int) $this->student->id);
        }

        game_page_service::check_attempt_limit($campaign, (int) $this->student->id, $this->returnurl);
        game_page_service::check_attempt_limit($single, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that running out of attempts ends the Campaign rather than the activity: with another
     * Campaign allowed the student may play on, and with none left they are told they ran out of
     * attempts.
     *
     * @return void
     */
    public function test_check_attempt_limit_campaign_out_of_attempts_ends_the_campaign(): void {
        [, $twocampaigns] = $this->make_cm_and_instance([
            'gamemode'     => constants::GAMEMODE_CAMPAIGN,
            'maxattempts'  => 2,
            'maxcampaigns' => 2,
        ]);
        [, $onecampaign] = $this->make_cm_and_instance([
            'gamemode'     => constants::GAMEMODE_CAMPAIGN,
            'maxattempts'  => 2,
            'maxcampaigns' => 1,
        ]);
        foreach ([$twocampaigns, $onecampaign] as $instance) {
            $this->make_finished_attempt($instance->id, (int) $this->student->id);
            $this->make_finished_attempt($instance->id, (int) $this->student->id);
        }

        game_page_service::check_attempt_limit($twocampaigns, (int) $this->student->id, $this->returnurl);
        $this->assertSame(
            'maxattemptsreached',
            game_page_service::campaign_limit_refusal($onecampaign, (int) $this->student->id)
        );
        $this->expectException(\moodle_exception::class);
        game_page_service::check_attempt_limit($onecampaign, (int) $this->student->id, $this->returnurl);
    }

    /**
     * Tests that a won Campaign spends one of maxcampaigns, whatever maxattempts is: the next Play
     * would start another Campaign, which is refused once none is left.
     *
     * @return void
     */
    public function test_check_attempt_limit_campaign_counts_campaigns(): void {
        [, $instance] = $this->make_cm_and_instance([
            'gamemode'     => constants::GAMEMODE_CAMPAIGN,
            'maxattempts'  => 0,
            'maxcampaigns' => 2,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id, 'lost');
        $this->make_finished_attempt($instance->id, (int) $this->student->id, 'won');
        $this->assertNull(game_page_service::campaign_limit_refusal($instance, (int) $this->student->id));

        $this->make_finished_attempt($instance->id, (int) $this->student->id, 'won', 2);
        $this->assertSame(
            'maxcampaignsreached',
            game_page_service::campaign_limit_refusal($instance, (int) $this->student->id)
        );
        $this->expectException(\moodle_exception::class);
        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
    }

    /**
     * Tests that only a Play that would begin a Campaign after an earlier one ended needs the
     * Lobby's confirmation: not the first Campaign, not a Campaign still being played, not a
     * Single Match.
     *
     * @return void
     */
    public function test_needs_new_campaign_confirmation(): void {
        global $DB;

        [, $campaign] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'maxattempts' => 2]);
        [, $single] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        $userid = (int) $this->student->id;

        $this->assertFalse(game_page_service::needs_new_campaign_confirmation($campaign, $userid));
        $this->make_finished_attempt($campaign->id, $userid);
        $this->assertFalse(game_page_service::needs_new_campaign_confirmation($campaign, $userid));
        $this->make_finished_attempt($campaign->id, $userid);
        $this->assertTrue(game_page_service::needs_new_campaign_confirmation($campaign, $userid));

        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $campaign->id,
            'userid'         => $userid,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'inprogress',
            'campaign'       => 2,
            'timecreated'    => time(),
        ]);
        $this->assertFalse(game_page_service::needs_new_campaign_confirmation($campaign, $userid));

        $this->make_finished_attempt($single->id, $userid, 'won');
        $this->assertFalse(game_page_service::needs_new_campaign_confirmation($single, $userid));
    }

    /**
     * Tests what a Campaign student still has to do about their hero: choose it first thing, in the first
     * Campaign and in one in progress that has none; confirm and choose it for a Campaign after one ended;
     * nothing once it is chosen, in a Single Match or when no Campaign is left to play.
     *
     * @return void
     */
    public function test_hero_stage(): void {
        [, $campaign] = $this->make_cm_and_instance([
            'gamemode' => constants::GAMEMODE_CAMPAIGN, 'maxattempts' => 1, 'maxcampaigns' => 2,
        ]);
        [, $single] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        $userid = (int) $this->student->id;

        $this->assertSame(game_page_service::HERO_FIRST, game_page_service::hero_stage($campaign, $userid));
        $this->assertSame(game_page_service::HERO_NONE, game_page_service::hero_stage($single, $userid));

        game_page_service::choose_campaign_hero($campaign, $userid, 'magef');
        $this->assertSame(game_page_service::HERO_NONE, game_page_service::hero_stage($campaign, $userid));

        $this->make_finished_attempt($campaign->id, $userid);
        $this->assertSame(game_page_service::HERO_NEW, game_page_service::hero_stage($campaign, $userid));
        $this->assertTrue(game_page_service::needs_new_campaign_confirmation($campaign, $userid));

        game_page_service::choose_campaign_hero($campaign, $userid, 'archerm');
        $this->assertSame(game_page_service::HERO_NONE, game_page_service::hero_stage($campaign, $userid));
        $this->assertFalse(game_page_service::needs_new_campaign_confirmation($campaign, $userid));

        $this->make_finished_attempt($campaign->id, $userid, 'lost', 2);
        $this->assertSame(
            game_page_service::HERO_NONE,
            game_page_service::hero_stage($campaign, $userid),
            'both Campaigns played: nothing is left to start'
        );
    }

    /**
     * Tests that choosing the hero of a new Campaign empties the student's PuzzleCoin and consumables, once
     * and for that student only, while choosing the hero of the first Campaign leaves them alone.
     *
     * @return void
     */
    public function test_choosing_the_hero_of_a_new_campaign_empties_the_stock(): void {
        [, $campaign] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);
        $userid = (int) $this->student->id;
        $other = $this->getDataGenerator()->create_user();
        user_stock::credit($userid, (int) $campaign->id, user_stock::CURRENCY_TYPE, 40);
        user_stock::credit($userid, (int) $campaign->id, 'potion', 2);
        user_stock::credit((int) $other->id, (int) $campaign->id, user_stock::CURRENCY_TYPE, 7);

        game_page_service::choose_campaign_hero($campaign, $userid, 'magef');
        $this->assertSame(40, user_stock::get_quantity($userid, (int) $campaign->id, user_stock::CURRENCY_TYPE));

        $this->make_finished_attempt($campaign->id, $userid, 'won');
        $hero = game_page_service::choose_campaign_hero($campaign, $userid, 'warriorf');

        $this->assertSame(2, (int) $hero->campaign);
        $this->assertSame(0, user_stock::get_quantity($userid, (int) $campaign->id, user_stock::CURRENCY_TYPE));
        $this->assertSame(0, user_stock::get_quantity($userid, (int) $campaign->id, 'potion'));
        $this->assertSame(7, user_stock::get_quantity((int) $other->id, (int) $campaign->id, user_stock::CURRENCY_TYPE));

        // Choosing again changes neither the hero nor the stock bought since.
        user_stock::credit($userid, (int) $campaign->id, user_stock::CURRENCY_TYPE, 5);
        $again = game_page_service::choose_campaign_hero($campaign, $userid, 'archerf');
        $this->assertSame('warriorf', $again->hero);
        $this->assertSame(5, user_stock::get_quantity($userid, (int) $campaign->id, user_stock::CURRENCY_TYPE));
    }

    /**
     * Tests that the game loads the sprite of the hero the phase was started with, the Campaign's own at its
     * stage, the one picked for a Single Match, and the old picture for an attempt from before heroes.
     *
     * @return void
     */
    public function test_build_game_config_loads_the_heroes_sprite(): void {
        global $DB;

        [$cm, $campaign] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);
        [$singlecm, $single] = $this->make_cm_and_instance([
            'gamemode' => constants::GAMEMODE_SINGLE, 'minquestions' => 0,
        ]);
        $userid = (int) $this->student->id;

        $context = \context_module::instance($cm->id);
        $legacy = game_page_service::build_game_config($cm, $campaign, $context, $userid, false);
        $this->assertStringContainsString('/mod/playerpuzzle/pix/heroes/warriorf1.webp', $legacy['playerurl']);
        $DB->delete_records('playerpuzzle_attempts', ['playerpuzzleid' => $campaign->id]);

        $row = game_page_service::choose_campaign_hero($campaign, $userid, 'magef');
        $DB->set_field('playerpuzzle_heroes', 'stage', 3, ['id' => $row->id]);
        $config = game_page_service::build_game_config($cm, $campaign, $context, $userid, false);
        $this->assertStringContainsString('/mod/playerpuzzle/pix/heroes/magef3.webp', $config['playerurl']);

        $singlecontext = \context_module::instance($singlecm->id);
        $args = [$singlecm, $single, $singlecontext, $userid, false, 'normal', false, 'archerm'];
        $match = game_page_service::build_game_config(...$args);
        $this->assertStringContainsString('/mod/playerpuzzle/pix/heroes/archerm1.webp', $match['playerurl']);
    }

    /**
     * Tests that Single Match mode counts finished attempts against
     * max_single_matches, entirely independent of maxattempts (which is
     * Campaign-only and hidden from the form in this mode).
     *
     * @return void
     */
    public function test_check_attempt_limit_single_match_uses_max_single_matches(): void {
        [, $instance] = $this->make_cm_and_instance([
            'gamemode'           => constants::GAMEMODE_SINGLE,
            'max_single_matches' => 1,
            'maxattempts'        => 0,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        $this->expectException(\moodle_exception::class);
        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
    }

    /**
     * Tests that an in-progress (unfinished) attempt is never refused, even once the attempts and
     * Campaigns are used up: Play only resumes it, and an abandoned in-progress row is meant to be
     * resumed, not to silently consume a slot.
     *
     * @return void
     */
    public function test_check_attempt_limit_ignores_inprogress_attempts(): void {
        global $DB;

        [, $instance] = $this->make_cm_and_instance([
            'gamemode'     => constants::GAMEMODE_CAMPAIGN,
            'maxattempts'  => 1,
            'maxcampaigns' => 1,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid'         => $this->student->id,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'inprogress',
            'timecreated'    => time(),
        ]);

        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that finished Demo attempts never count against the real attempt limit — a
     * student who has only ever played the Demo still gets every real try.
     *
     * @return void
     */
    public function test_check_attempt_limit_ignores_demo_attempts(): void {
        [, $instance] = $this->make_cm_and_instance([
            'gamemode'     => constants::GAMEMODE_CAMPAIGN,
            'maxattempts'  => 1,
            'maxcampaigns' => 1,
        ]);
        for ($i = 0; $i < 5; $i++) {
            $this->make_finished_demo_attempt($instance->id, (int) $this->student->id);
        }

        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that the very first attempt is always free, even with a retry-cost item
     * configured and no balance to pay it.
     *
     * @return void
     */
    public function test_check_retry_cost_first_attempt_is_free(): void {
        [, $itemid] = $this->make_hud_item();
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => $itemid]);

        game_page_service::check_retry_cost($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that the configured quantity is charged from the 2nd attempt onwards, when the
     * student holds enough of the item.
     *
     * @return void
     */
    public function test_check_retry_cost_charges_from_second_attempt(): void {
        [$biid, $itemid] = $this->make_hud_item();
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => $itemid, 'hud_retry_cost_qty' => 3]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $this->grant_hud_item($biid, $itemid, (int) $this->student->id, 5);

        game_page_service::check_retry_cost($instance, (int) $this->student->id, $this->returnurl);

        $this->assertSame(2, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));
    }

    /**
     * Tests that a student without enough of the configured item is blocked.
     *
     * @return void
     */
    public function test_check_retry_cost_blocks_when_insufficient(): void {
        [$biid, $itemid] = $this->make_hud_item();
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => $itemid, 'hud_retry_cost_qty' => 3]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $this->grant_hud_item($biid, $itemid, (int) $this->student->id, 1);

        $this->expectException(\moodle_exception::class);
        game_page_service::check_retry_cost($instance, (int) $this->student->id, $this->returnurl);
    }

    /**
     * Tests that retries stay free when no retry-cost item is configured, no matter how
     * many attempts have already been finished.
     *
     * @return void
     */
    public function test_check_retry_cost_free_when_item_unconfigured(): void {
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => 0]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        game_page_service::check_retry_cost($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that retries stay free when a retry-cost item is configured but there is no
     * block_playerhud instance in the course to charge it from — the gate degrades to free
     * rather than locking students out over a teacher/admin infrastructure gap.
     *
     * @return void
     */
    public function test_check_retry_cost_free_when_no_playerhud_block(): void {
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => 999]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        game_page_service::check_retry_cost($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that a play.php POST which will only resume an already in-progress attempt is
     * never charged — resuming is not "a new try".
     *
     * @return void
     */
    public function test_check_retry_cost_skips_when_resuming_inprogress(): void {
        global $DB;

        [, $itemid] = $this->make_hud_item();
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => $itemid]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid'         => $this->student->id,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'inprogress',
            'timecreated'    => time(),
        ]);

        // No balance granted at all — would throw if this were treated as a new attempt.
        game_page_service::check_retry_cost($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that a student who has only ever played the Demo still gets a free first real
     * attempt — Demo history never counts as "a finished attempt" for the retry-cost gate.
     *
     * @return void
     */
    public function test_check_retry_cost_ignores_demo_attempts(): void {
        [, $itemid] = $this->make_hud_item();
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => $itemid]);
        $this->make_finished_demo_attempt($instance->id, (int) $this->student->id);
        $this->make_finished_demo_attempt($instance->id, (int) $this->student->id);

        // No balance granted at all — would throw if Demo history counted as a real attempt.
        game_page_service::check_retry_cost($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that no cooldown at all (the default) never blocks, even with a very recent
     * finished attempt.
     *
     * @return void
     */
    public function test_check_cooldown_free_when_unconfigured(): void {
        [, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        game_page_service::check_cooldown($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that a configured cooldown never applies in Campaign mode — an attempt there is
     * a continuous winning streak, not a short repeatable match to farm.
     *
     * @return void
     */
    public function test_check_cooldown_never_applies_to_campaign_mode(): void {
        [, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);
        $instance->cooldown_seconds = 3600;
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        game_page_service::check_cooldown($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that a configured cooldown never blocks the very first attempt — there is no
     * prior finished match for it to count from.
     *
     * @return void
     */
    public function test_check_cooldown_free_on_first_attempt(): void {
        [, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        $instance->cooldown_seconds = 3600;

        game_page_service::check_cooldown($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that a play.php POST which will only resume an already in-progress attempt is
     * never blocked by the cooldown — resuming is not "a new match".
     *
     * @return void
     */
    public function test_check_cooldown_skips_when_resuming_inprogress(): void {
        global $DB;

        [, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        $instance->cooldown_seconds = 3600;
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid'         => $this->student->id,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'inprogress',
            'timecreated'    => time(),
        ]);

        game_page_service::check_cooldown($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that a new match is blocked while the configured cooldown since the last
     * finished match has not elapsed yet.
     *
     * @return void
     */
    public function test_check_cooldown_blocks_before_it_elapses(): void {
        [, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        $instance->cooldown_seconds = 60;
        $this->make_finished_attempt_at($instance->id, (int) $this->student->id, time() - 10);

        $this->expectException(\moodle_exception::class);
        game_page_service::check_cooldown($instance, (int) $this->student->id, $this->returnurl);
    }

    /**
     * Tests that a student blocked by the cooldown is not charged the retry-cost item: the
     * debit is the one gate with a side effect, so it must come after the cooldown check.
     *
     * @return void
     */
    public function test_check_can_start_attempt_does_not_charge_during_cooldown(): void {
        [$biid, $itemid] = $this->make_hud_item();
        [, $instance] = $this->make_cm_and_instance([
            'gamemode' => constants::GAMEMODE_SINGLE,
            'hud_retry_cost_item' => $itemid,
            'hud_retry_cost_qty' => 3,
        ]);
        $instance->cooldown_seconds = 60;
        $this->make_finished_attempt_at($instance->id, (int) $this->student->id, time() - 10);
        $this->grant_hud_item($biid, $itemid, (int) $this->student->id, 5);

        try {
            game_page_service::check_can_start_attempt($instance, (int) $this->student->id, $this->returnurl);
            $this->fail('The cooldown should have rejected the new attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('cooldownactive', $e->errorcode);
        }

        $this->assertSame(5, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));
    }

    /**
     * Tests that the retry cost is still charged once every other gate passes.
     *
     * @return void
     */
    public function test_check_can_start_attempt_charges_when_nothing_else_blocks(): void {
        [$biid, $itemid] = $this->make_hud_item();
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => $itemid, 'hud_retry_cost_qty' => 3]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $this->grant_hud_item($biid, $itemid, (int) $this->student->id, 5);

        game_page_service::check_can_start_attempt($instance, (int) $this->student->id, $this->returnurl);

        $this->assertSame(2, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));
    }

    /**
     * Tests that a new match is allowed once the configured cooldown since the last
     * finished match has elapsed.
     *
     * @return void
     */
    public function test_check_cooldown_allows_once_it_elapses(): void {
        [, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        $instance->cooldown_seconds = 60;
        $this->make_finished_attempt_at($instance->id, (int) $this->student->id, time() - 120);

        game_page_service::check_cooldown($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that a Demo match never counts as "the last finished match" for the cooldown —
     * a student who has only ever played the Demo still gets a free first real attempt
     * with no wait.
     *
     * @return void
     */
    public function test_check_cooldown_ignores_demo_attempts(): void {
        [, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        $instance->cooldown_seconds = 3600;
        $this->make_finished_demo_attempt($instance->id, (int) $this->student->id);

        game_page_service::check_cooldown($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that build_game_config() resolves the scaled boss HP for the attempt's own level/phase, not the
     * raw configured base, and gives the student the base HP, which does not scale.
     *
     * @return void
     */
    public function test_build_game_config_scales_hp_for_current_phase(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'      => constants::GAMEMODE_CAMPAIGN,
            'basebosshp'    => 100,
            'basestudenthp' => 100,
            'bossdamage'    => 10,
        ]);
        $context = \context_module::instance($cm->id);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        // The attempt reaches Level 5 the way a real one does: the phase starts, and freezes its values.
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $attempt->currentlevel = 5;
        $attempt->currentphase = 1;
        \mod_playerpuzzle\local\engine\security::seed_replay_state($attempt, $instance);
        $DB->update_record('playerpuzzle_attempts', $attempt);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        // Level 5, Phase 1 with base 100: boss 300, student 100, since the student's HP does not grow with
        // the level, only with the hero they buy for. The opponent frozen when the
        // attempt started is the slime, which has no profile.
        $this->assertSame(300, $config['bosshp']);
        $this->assertSame(100, $config['studenthp']);
        // Combat damage reuses the same boss HP growth curve: base 10 -> 30 at Level 5/Phase 1.
        $this->assertSame(30, $config['bossdamage']);
        $this->assertSame(5, $config['currentlevel']);
        $this->assertSame(1, $config['currentphase']);
        $this->assertNotSame($token, $config['token']);
    }

    /**
     * Tests that a resumed phase is configured from the boss-side values it was started with, not from
     * the activity's current ones. The server replay verifies the phase against the frozen copy, so a
     * teacher editing the activity mid-phase must not make the resumed client calculate other damage,
     * HP and coins than the server will.
     *
     * @return void
     */
    public function test_build_game_config_resumes_with_the_values_the_phase_started_with(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'   => constants::GAMEMODE_SINGLE,
            'basebosshp' => 200,
            'bossdamage' => 10,
            'coingain'   => 10,
            'bossavatar' => 'goblin',
        ]);
        $context = \context_module::instance($cm->id);
        $studentid = (int) $this->student->id;

        $first = game_page_service::build_game_config($cm, $instance, $context, $studentid, false);
        $this->assertSame(10, $first['coingain']);
        $this->assertSame(get_string('bossavatar_goblin', 'mod_playerpuzzle'), $first['opponentname']);

        // The teacher edits the activity while the student is in the middle of the phase.
        $DB->update_record('playerpuzzle', (object) [
            'id' => $instance->id, 'basebosshp' => 900, 'bossdamage' => 40, 'coingain' => 50,
        ]);
        $edited = $DB->get_record('playerpuzzle', ['id' => $instance->id], '*', MUST_EXIST);

        $resumed = game_page_service::build_game_config($cm, $edited, $context, $studentid, false);

        $this->assertSame(200, $resumed['bosshp']);
        $this->assertSame(10, $resumed['bossdamage']);
        $this->assertSame(10, $resumed['coingain']);
    }

    /**
     * Tests that Single Match mode always resolves to the base HP unchanged, since its
     * attempts stay at Level 1, Phase 1 — no special-casing needed in the service. The goblin,
     * a Brutamontes, leaves the HP as it is.
     *
     * @return void
     */
    public function test_build_game_config_single_match_uses_base_hp(): void {
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'      => constants::GAMEMODE_SINGLE,
            'basebosshp'    => 250,
            'basestudenthp' => 80,
            'bossdamage'    => 15,
            'bossavatar'    => 'goblin',
        ]);
        $context = \context_module::instance($cm->id);

        $config = game_page_service::build_game_config(
            $cm,
            $instance,
            $context,
            (int) $this->student->id,
            false
        );

        $this->assertSame(250, $config['bosshp']);
        $this->assertSame(80, $config['studenthp']);
        $this->assertSame(15, $config['bossdamage']);
    }

    /**
     * Tests that difficulty scales the boss HP and boss damage (Hard doubles, Easy halves)
     * on top of the level/phase scaling, never the student HP, and that the chosen
     * difficulty and its coin factor are passed through to the JS config.
     *
     * @return void
     */
    public function test_build_game_config_applies_difficulty_to_boss_only(): void {
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'      => constants::GAMEMODE_SINGLE,
            'basebosshp'    => 200,
            'basestudenthp' => 100,
            'bossdamage'    => 10,
            'bossavatar'    => 'goblin',
        ]);
        $context = \context_module::instance($cm->id);

        $studentid = (int) $this->student->id;
        $hard = game_page_service::build_game_config($cm, $instance, $context, $studentid, false, 'hard');
        $this->assertSame(400, $hard['bosshp']);
        $this->assertSame(20, $hard['bossdamage']);
        $this->assertSame(100, $hard['studenthp']);
        $this->assertSame('hard', $hard['difficulty']);
        $this->assertSame(3.0, $hard['coinfactor']);
    }

    /**
     * Tests that a Demo attempt always fights at the fixed combat::DEMO_HP,
     * ignoring the instance's own configured HP/difficulty entirely — even on Hard, even on
     * a Campaign instance whose gamemode is reported to the client as 'single' instead.
     *
     * @return void
     */
    public function test_build_game_config_demo_uses_fixed_hp(): void {
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'      => constants::GAMEMODE_CAMPAIGN,
            'basebosshp'    => 1000,
            'basestudenthp' => 250,
        ]);
        $context = \context_module::instance($cm->id);

        $config = game_page_service::build_game_config(
            $cm,
            $instance,
            $context,
            (int) $this->student->id,
            false,
            'hard',
            true
        );

        $this->assertSame(50, $config['bosshp']);
        $this->assertSame(50, $config['studenthp']);
        $this->assertSame(constants::GAMEMODE_SINGLE, $config['gamemode']);
        $this->assertSame(1, $config['currentlevel']);
        $this->assertSame(1, $config['currentphase']);
        $this->assertTrue($config['isdemo']);
    }

    /**
     * Tests that resuming an in-progress attempt keeps that attempt's own current-phase
     * difficulty: a run whose attempt carries Hard ignores a later Lobby request for Easy
     * (difficulty only changes between phases, via advance_phase).
     *
     * @return void
     */
    public function test_build_game_config_difficulty_is_kept_on_resume(): void {
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'   => constants::GAMEMODE_SINGLE,
            'basebosshp' => 200,
            'bossavatar' => 'goblin',
        ]);
        $context = \context_module::instance($cm->id);

        \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'hard',
            1,
            1
        );

        $studentid = (int) $this->student->id;
        $config = game_page_service::build_game_config($cm, $instance, $context, $studentid, false, 'easy');

        $this->assertSame('hard', $config['difficulty']);
        $this->assertSame(400, $config['bosshp']);
    }

    /**
     * Tests that build_game_config() passes the instance's minquestions through, and that a
     * fresh attempt starts questionstotal at 0 — the client's only sources of truth for the
     * boss-revive rule and the "Perguntas: X/N" HUD counter.
     *
     * @return void
     */
    public function test_build_game_config_passes_minquestions_and_questionstotal(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['minquestions' => 5]);
        $context = \context_module::instance($cm->id);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        $this->assertSame(5, $config['minquestions']);
        $this->assertSame(0, $config['questionstotal']);
    }

    /**
     * Tests that resuming an in-progress attempt carries its questions_total forward into
     * the game config — the count is cumulative for the whole Campaign attempt, not reset
     * by reloading play.php.
     *
     * @return void
     */
    public function test_build_game_config_carries_questionstotal_on_resume(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['minquestions' => 5]);
        $context = \context_module::instance($cm->id);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'questions_total', 4, ['token' => $token]);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        $this->assertSame(4, $config['questionstotal']);
    }

    /**
     * Tests that consumablestock reports the units of each type actually owned (bought
     * pre-match in the Lobby), in one bulk read for all five types.
     *
     * @return void
     */
    public function test_build_game_config_reports_consumablestock(): void {
        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'potion', 3);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'hint', 1);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        $this->assertSame(
            ['potion' => 3, 'shield' => 0, 'magic' => 0, 'sword' => 0, 'hint' => 1],
            $config['consumablestock']
        );
    }

    /**
     * Tests that enablespeech reflects the current user's own narration preference — a
     * per-student choice (sound_preferences), not a site setting — defaulting to false
     * and flipping once the student turns it on.
     *
     * @return void
     */
    public function test_build_game_config_reports_speech_preference(): void {
        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);
        $this->assertFalse($config['enablespeech']);

        sound_preferences::set_enabled('speech', true, (int) $this->student->id);
        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);
        $this->assertTrue($config['enablespeech']);
    }

    /**
     * Tests that a fresh attempt's config carries no combat checkpoint.
     *
     * @return void
     */
    public function test_build_game_config_combatstate_is_null_for_a_fresh_attempt(): void {
        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        $this->assertNull($config['combatstate']);
    }

    /**
     * Tests that the config carries the attempt's own rngseed/moveseq — the values that
     * seed the client's deterministic PRNG (engine/prng.js) and that sendCheckpoint()
     * continues incrementing from, rather than the client ever inventing its own.
     *
     * @return void
     */
    public function test_build_game_config_reports_rngseed_and_moveseq(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        $this->assertSame((int) $attempt->rngseed, $config['rngseed']);
        $this->assertSame((int) $attempt->moveseq, $config['moveseq']);
    }

    /**
     * Tests that resuming a real attempt rebuilds the phase through the server's own replay
     * and ignores whatever the client's last checkpoint declared: here the checkpoint claims
     * a boss at 1 HP, but nothing has been played, so the snapshot says the boss is untouched.
     *
     * @return void
     */
    public function test_build_game_config_rebuilds_a_resumed_phase_instead_of_its_checkpoint(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field(
            'playerpuzzle_attempts',
            'combatstate',
            '{"boardgrid":[4,5,6],"currentturn":"player","currentbosshp":1}',
            ['token' => $token]
        );

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        $this->assertNull($config['combatstate']);
        $this->assertSame('player', $config['snapshot']['turn']);
        $this->assertCount(64, $config['snapshot']['grid']);
        $this->assertEquals($config['bosshp'], $config['snapshot']['state']['currentHp']);
        $this->assertSame(0, $config['moveseq']);
    }

    /**
     * Tests that the balloon explaining an opponent's powers is offered only the first time the
     * student meets that opponent, in any activity, while the phase still counts as fresh until a
     * move is played in it.
     *
     * @return void
     */
    public function test_build_game_config_explains_an_opponent_only_the_first_time(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'bossavatar' => 'goblin']);
        $context = \context_module::instance($cm->id);
        $userid = (int) $this->student->id;

        $first = game_page_service::build_game_config($cm, $instance, $context, $userid, false);
        $this->assertTrue($first['freshphase']);
        $this->assertTrue($first['powernotice']);

        $again = game_page_service::build_game_config($cm, $instance, $context, $userid, false);
        $this->assertTrue($again['freshphase'], 'no move was played yet');
        $this->assertFalse($again['powernotice']);

        [$othercm, $other] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'bossavatar' => 'goblin']);
        $othercontext = \context_module::instance($othercm->id);
        $this->assertFalse(game_page_service::build_game_config($othercm, $other, $othercontext, $userid, false)['powernotice']);

        [$golemcm, $golem] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'bossavatar' => 'golem']);
        $golemcontext = \context_module::instance($golemcm->id);
        $this->assertTrue(game_page_service::build_game_config($golemcm, $golem, $golemcontext, $userid, false)['powernotice']);
        $this->assertSame(['goblin', 'golem'], seen_opponents::seen($userid));
    }

    /**
     * Tests that a phase with a move played in it is no longer fresh, so a first meeting there is
     * neither explained nor recorded, and that a Demo never records one.
     *
     * @return void
     */
    public function test_build_game_config_explains_nothing_once_the_phase_is_under_way(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'bossavatar' => 'goblin']);
        $context = \context_module::instance($cm->id);
        $userid = (int) $this->student->id;

        $demo = game_page_service::build_game_config($cm, $instance, $context, $userid, false, 'normal', true);
        $this->assertFalse($demo['powernotice']);
        $DB->delete_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id, 'userid' => $userid]);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token((int) $instance->id, $userid);
        $DB->update_record('playerpuzzle_attempts', (object) [
            'id' => (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]),
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 0, 'c1' => 4, 'r2' => 1, 'c2' => 4]]),
            'moveseq' => 1,
            'frozenbasebosshp' => 100000,
        ]);

        $config = game_page_service::build_game_config($cm, $instance, $context, $userid, false);
        $this->assertNotNull($config['snapshot']);
        $this->assertFalse($config['freshphase']);
        $this->assertFalse($config['powernotice']);
        $this->assertSame([], seen_opponents::seen($userid));
    }

    /**
     * Tests that an event the rebuild had to add — here, a question the server had already
     * judged before the reload, but whose marker never reached the log — is stored, and the
     * client continues from the new count.
     *
     * @return void
     */
    public function test_build_game_config_stores_events_the_rebuild_added(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);
        $DB->update_record('playerpuzzle_attempts', (object) [
            'id' => $attemptid,
            'rngseed' => 56,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 0, 'c1' => 7, 'r2' => 1, 'c2' => 7]]),
            'moveseq' => 1,
            'questionresults' => question_results::append(null, 'player', true, true),
            'questions_total' => 1,
            'frozenbasebosshp' => 100000,
        ]);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        $this->assertNull($config['snapshot']['pendingquestion']);
        $this->assertSame(2, $config['moveseq']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $this->assertSame(2, (int) $attempt->moveseq);
        $this->assertSame(
            ['type' => 'question', 'side' => 'player', 'outcome' => 'answered'],
            move_log::decode($attempt->movelog)[1]
        );
    }

    /**
     * Tests that a resumed Demo keeps resuming from its checkpoint: it fights at a fixed HP the
     * replay does not model, and has nothing at stake to verify.
     *
     * @return void
     */
    public function test_build_game_config_keeps_the_checkpoint_for_a_demo(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );
        $DB->set_field(
            'playerpuzzle_attempts',
            'combatstate',
            '{"boardgrid":[4,5,6],"currentturn":"player"}',
            ['token' => $token]
        );

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false, 'normal', true);

        $this->assertNull($config['snapshot']);
        $this->assertSame([4, 5, 6], $config['combatstate']['boardgrid']);
    }

    /**
     * Tests that the guest account is kept to the Demo: a real attempt is refused, the Demo
     * is allowed, and a real student is never affected.
     *
     * @return void
     */
    public function test_check_guest_demo_only(): void {
        [$cm] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        $this->setGuestUser();
        game_page_service::check_guest_demo_only(true, $this->returnurl, $context);

        $this->setUser($this->student);
        game_page_service::check_guest_demo_only(false, $this->returnurl, $context);

        $this->setGuestUser();
        try {
            game_page_service::check_guest_demo_only(false, $this->returnurl, $context);
            $this->fail('The guest account started a real attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('guestdemoonly', $e->errorcode);
        }
    }

    /**
     * Regression test: a logged-in user who is in the course only through guest access is a
     * visitor too, even though isguestuser() is false for them. Before the fix they could
     * start real attempts, with grades and coins of their own.
     *
     * @return void
     */
    public function test_check_guest_demo_only_also_keeps_a_logged_in_visitor_to_the_demo(): void {
        [$cm] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);
        $visitor = $this->getDataGenerator()->create_user();

        $this->setUser($visitor);
        game_page_service::check_guest_demo_only(true, $this->returnurl, $context);
        try {
            game_page_service::check_guest_demo_only(false, $this->returnurl, $context);
            $this->fail('A logged-in visitor started a real attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('guestdemoonly', $e->errorcode);
        }
    }

    /**
     * Tests that a retry cost whose item was deleted from PlayerHUD is waived instead of blocking
     * every student who has already played, with a message that claims their balance is too low.
     *
     * @return void
     */
    public function test_check_retry_cost_is_waived_when_the_item_was_deleted_from_playerhud(): void {
        global $DB;

        [, $itemid] = $this->make_hud_item();
        [, $instance] = $this->make_cm_and_instance(['hud_retry_cost_item' => $itemid, 'hud_retry_cost_qty' => 3]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $DB->delete_records('block_playerhud_items', ['id' => $itemid]);

        game_page_service::check_retry_cost($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Starts a match for the student the way play.php does.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $instance Activity instance.
     * @param bool $isdemo Whether the match is a Demo.
     * @return array The game config.
     */
    private function start_game(\stdClass $cm, \stdClass $instance, bool $isdemo = false): array {
        return game_page_service::start_game(
            $cm,
            $instance,
            \context_module::instance($cm->id),
            (int) $this->student->id,
            false,
            'normal',
            $isdemo,
            $this->returnurl
        );
    }

    /**
     * Tests that a real start runs its gates and creates the attempt inside one lock per student and
     * activity. PHPUnit runs one request at a time, so it cannot race two starts; what it can show is that
     * the check and the creation sit between the acquire and the release of that lock, which is what makes
     * a second simultaneous start wait for the first one's row before it counts the attempts.
     *
     * @return void
     */
    public function test_a_real_start_takes_the_start_lock_around_the_gates_and_the_creation(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/recording_lock_factory.php');

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);
        \mod_playerpuzzle_recording_lock_factory::install();

        $this->start_game($cm, $instance);

        $start = 'start_' . $this->student->id . '_' . $instance->id;
        $events = \mod_playerpuzzle_recording_lock_factory::events_for('mod_playerpuzzle');
        $this->assertSame("acquire {$start}", reset($events));
        $this->assertSame("release {$start}", end($events));
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['userid' => $this->student->id]));
    }

    /**
     * Tests that when another start of the same student holds the lock, nothing happens: no attempt is
     * created and the retry cost is not charged, and the same start works once the lock is free.
     *
     * @return void
     */
    public function test_a_busy_start_lock_creates_nothing_and_charges_nothing(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/recording_lock_factory.php');

        [$biid, $itemid] = $this->make_hud_item();
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode' => constants::GAMEMODE_SINGLE,
            'hud_retry_cost_item' => $itemid,
            'hud_retry_cost_qty' => 2,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $this->grant_hud_item($biid, $itemid, (int) $this->student->id, 5);
        $before = $DB->count_records('playerpuzzle_attempts');
        \mod_playerpuzzle_recording_lock_factory::install(
            ['mod_playerpuzzle/start_' . $this->student->id . '_' . $instance->id]
        );

        try {
            $this->start_game($cm, $instance);
            $this->fail('A start that cannot take the lock must be refused.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('startbusy', $exception->errorcode);
        }

        $this->assertSame($before, $DB->count_records('playerpuzzle_attempts'));
        $this->assertSame(5, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));

        \mod_playerpuzzle_recording_lock_factory::install();
        $this->start_game($cm, $instance);
        $this->assertSame($before + 1, $DB->count_records('playerpuzzle_attempts'));
        $this->assertSame(3, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));
    }

    /**
     * Tests the outcome of two starts that reach the server one after the other, the case the lock turns
     * the simultaneous one into: the second finds the first one's attempt and resumes it, so there is a
     * single attempt in progress and the retry cost is charged once.
     *
     * @return void
     */
    public function test_a_second_start_resumes_the_attempt_instead_of_creating_another(): void {
        global $DB;

        [$biid, $itemid] = $this->make_hud_item();
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode' => constants::GAMEMODE_SINGLE,
            'hud_retry_cost_item' => $itemid,
            'hud_retry_cost_qty' => 2,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $this->grant_hud_item($biid, $itemid, (int) $this->student->id, 5);

        $first = $this->start_game($cm, $instance);
        $second = $this->start_game($cm, $instance);

        $this->assertNotSame($first['token'], $second['token'], 'The resumed attempt gets a fresh token.');
        $this->assertSame(
            1,
            $DB->count_records('playerpuzzle_attempts', ['userid' => $this->student->id, 'status' => 'inprogress'])
        );
        $this->assertSame(3, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));
    }

    /**
     * Tests that a Demo takes no start lock: it has no limit, cooldown or cost to protect.
     *
     * @return void
     */
    public function test_a_demo_start_takes_no_start_lock(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/recording_lock_factory.php');

        [$cm, $instance] = $this->make_cm_and_instance();
        \mod_playerpuzzle_recording_lock_factory::install();

        $this->start_game($cm, $instance, true);

        $this->assertSame([], \mod_playerpuzzle_recording_lock_factory::events_for('mod_playerpuzzle'));
    }

    /**
     * Tests that a match is refused when the activity requires answering questions and has none approved:
     * the boss would be revived every time and the victory refused, so the student could only lose.
     *
     * @return void
     */
    public function test_a_match_is_refused_when_there_is_a_minimum_but_no_approved_question(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['minquestions' => 3]);

        try {
            $this->start_game($cm, $instance);
            $this->fail('A match that cannot be won must not start.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('noapprovedquestions', $exception->errorcode);
        }

        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts'));
    }

    /**
     * Tests that the Demo is refused for the same reason, although it does not require the minimum
     * itself: a practice fight with no question to ask would not show what the real one is like.
     *
     * @return void
     */
    public function test_a_demo_is_refused_for_the_same_reason(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['minquestions' => 3]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('noapprovedquestions', 'mod_playerpuzzle'));
        $this->start_game($cm, $instance, true);
    }

    /**
     * Tests what does not count as lacking questions: a zero minimum, an approved question, and (the other
     * way round) a question that exists but was not approved.
     *
     * @return void
     */
    public function test_only_an_approved_question_lifts_the_refusal(): void {
        [, $nominimum] = $this->make_cm_and_instance(['minquestions' => 0]);
        [, $withminimum] = $this->make_cm_and_instance(['minquestions' => 2]);
        $this->assertFalse(game_page_service::lacks_questions($nominimum));
        $this->assertTrue(game_page_service::lacks_questions($withminimum));

        $questionid = questions_repository::add_question(
            (int) $withminimum->id,
            'multichoice',
            'Q?',
            '',
            [['text' => 'A', 'iscorrect' => true], ['text' => 'B', 'iscorrect' => false]],
            2,
            'manual',
            false
        );
        $this->assertTrue(game_page_service::lacks_questions($withminimum), 'An unapproved question is not drawn.');

        questions_repository::set_approved($questionid, true);
        $this->assertFalse(game_page_service::lacks_questions($withminimum));
    }

    /**
     * Tests that a match shows its opponent and that opponent's stage: the teacher's pick in a
     * Single Match, kept for the whole match even if the teacher changes the pick meanwhile, and
     * the phase's opponent in a Campaign.
     *
     * @return void
     */
    public function test_the_game_shows_the_phases_opponent_and_its_stage(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'bossavatar' => 'spider']);
        $config = $this->start_game($cm, $instance);
        $this->assertSame('spider', $config['bossavatar']);
        $this->assertStringEndsWith('/mod/playerpuzzle/pix/opponents/spider.webp', $config['bossurl']);
        $this->assertStringEndsWith('/mod/playerpuzzle/pix/stages/cave.webp', $config['stagebgurl']);

        $DB->set_field('playerpuzzle', 'bossavatar', 'wolf', ['id' => $instance->id]);
        $resumed = $this->start_game($cm, $DB->get_record('playerpuzzle', ['id' => $instance->id]));
        $this->assertSame('spider', $resumed['bossavatar'], 'the match keeps the opponent it started with');

        [$cm, $campaign] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'bossavatar' => 'wolf']);
        $config = $this->start_game($cm, $campaign);
        $this->assertSame('slime', $config['bossavatar'], 'a Campaign starts on phase 1, whatever the pick');
        $this->assertStringEndsWith('/mod/playerpuzzle/pix/stages/ruins.webp', $config['stagebgurl']);
    }

    /**
     * Tests that the page hands the client the frozen opponent's profile and powers: the armor
     * and attack it fights with, a tough opponent's or the final boss's extra HP already in bosshp,
     * and its powers.
     *
     * @return void
     */
    public function test_build_game_config_passes_the_opponent_profile(): void {
        $expected = [
            'golem' => ['bosshp' => 200, 'bossattack' => 1.0, 'bossarmor' => 0.15, 'bosspowers' => ['iceshield']],
            'goblin' => ['bosshp' => 200, 'bossattack' => 1.15, 'bossarmor' => 0.0, 'bosspowers' => ['ambush']],
            'spider' => ['bosshp' => 230, 'bossattack' => 1.0, 'bossarmor' => 0.0, 'bosspowers' => ['fastgrimoire']],
            // A Single Match with no hero chosen: the final boss fights it as the warrior, with armor. Its 20% more
            // HP, and on Normal the 15% more of the final boss on top: 200 * 1.2 * 1.15.
            'vampire' => ['bosshp' => 276, 'bossattack' => 1.0, 'bossarmor' => 0.15, 'bosspowers' => ['drain']],
        ];
        foreach ($expected as $opponent => $values) {
            [$cm, $instance] = $this->make_cm_and_instance([
                'gamemode'   => constants::GAMEMODE_SINGLE,
                'basebosshp' => 200,
                'bossdamage' => 10,
                'bossavatar' => $opponent,
            ]);
            $context = \context_module::instance($cm->id);

            $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

            $this->assertSame($values, [
                'bosshp' => $config['bosshp'],
                'bossattack' => $config['bossattack'],
                'bossarmor' => $config['bossarmor'],
                'bosspowers' => $config['bosspowers'],
            ], $opponent);
            $this->assertSame(10, $config['bossdamage'], 'the profile never changes the base damage');
        }
    }

    /**
     * Tests that the final boss's second power follows the hero a Single Match is fought with, down to the
     * rhythm of its breath, which the client plays by.
     *
     * @return void
     */
    public function test_build_game_config_gives_the_final_boss_a_power_against_the_hero(): void {
        $expected = [
            'warriorm' => [0.15, ['drain'], 0],
            'archerf' => [0.0, ['drain', 'fastgrimoire'], 0],
            'magem' => [0.0, ['drain', 'breath'], 12],
        ];
        foreach ($expected as $hero => $values) {
            [$cm, $instance] = $this->make_cm_and_instance([
                'gamemode' => constants::GAMEMODE_SINGLE, 'minquestions' => 0, 'bossavatar' => 'vampire',
            ]);
            $context = \context_module::instance($cm->id);
            $args = [$cm, $instance, $context, (int) $this->student->id, false, 'normal', false, $hero];

            $config = game_page_service::build_game_config(...$args);

            $this->assertSame(
                $values,
                [$config['bossarmor'], $config['bosspowers'], $config['bossbreathevery']],
                $hero
            );
        }
    }

    /**
     * Tests that a Demo gets no minimum of questions, so its opponent never revives, while a real
     * match of the same activity keeps it.
     *
     * @return void
     */
    public function test_build_game_config_gives_a_demo_no_minimum_of_questions(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['minquestions' => 3]);
        questions_repository::add_question(
            (int) $instance->id,
            'truefalse',
            'Q?',
            '',
            [['text' => 'True', 'iscorrect' => true], ['text' => 'False', 'iscorrect' => false]],
            2
        );
        $context = \context_module::instance($cm->id);
        $studentid = (int) $this->student->id;

        $demo = game_page_service::build_game_config($cm, $instance, $context, $studentid, false, 'normal', true);
        $real = game_page_service::build_game_config($cm, $instance, $context, $studentid, false);

        $this->assertSame(0, $demo['minquestions']);
        $this->assertSame(3, $real['minquestions']);
    }

    /**
     * Tests the give up confirmation: a real match says what it costs, a Demo says only that it ends, and the
     * game's layout carries the dialog with both buttons.
     *
     * @return void
     */
    public function test_the_give_up_dialog(): void {
        global $OUTPUT;

        $real = game_page_service::giveup_dialog(false);
        $demo = game_page_service::giveup_dialog(true);

        $this->assertSame(get_string('giveup_text', 'mod_playerpuzzle'), $real['text']);
        $this->assertSame(get_string('giveup_demo', 'mod_playerpuzzle'), $demo['text']);
        $this->assertSame($real['title'], $demo['title']);

        $html = $OUTPUT->render_from_template('mod_playerpuzzle/game_layout', [
            'loadingtext' => 'Loading',
            'gameconfig' => '{}',
            'giveup' => $real,
        ]);
        $this->assertStringContainsString('id="playerpuzzle-giveup"', $html);
        $this->assertStringContainsString('id="pp-giveup-confirm"', $html);
        $this->assertStringContainsString('id="pp-giveup-cancel"', $html);
        $this->assertStringContainsString(get_string('giveup_text', 'mod_playerpuzzle'), $html);
    }
}
