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
        $record = array_merge(['course' => $this->course->id], $overrides);
        $instance = $generator->create_instance($record);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        return [$cm, $instance];
    }

    /**
     * Inserts a finished attempt row for the given instance/user.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @return void
     */
    private function make_finished_attempt(int $instanceid, int $userid): void {
        global $DB;
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instanceid,
            'userid'         => $userid,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'lost',
            'timecreated'    => time(),
            'timefinished'   => time(),
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
        [, $campaign] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN, 'maxattempts' => 0]);
        [, $single] = $this->make_cm_and_instance([
            'gamemode' => PLAYERPUZZLE_GAMEMODE_SINGLE,
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
     * Tests that Campaign mode counts finished attempts against maxattempts, and blocks
     * once the limit is reached.
     *
     * @return void
     */
    public function test_check_attempt_limit_campaign_uses_maxattempts(): void {
        [, $instance] = $this->make_cm_and_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'maxattempts' => 2,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        $this->expectException(\moodle_exception::class);
        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
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
            'gamemode'           => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'max_single_matches' => 1,
            'maxattempts'        => 0,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        $this->expectException(\moodle_exception::class);
        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
    }

    /**
     * Tests that an in-progress (unfinished) attempt never counts against the limit —
     * only attempts already in a final status do, since an abandoned in-progress row is
     * meant to be resumed, not to silently consume a slot.
     *
     * @return void
     */
    public function test_check_attempt_limit_ignores_inprogress_attempts(): void {
        global $DB;

        [, $instance] = $this->make_cm_and_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'maxattempts' => 1,
        ]);
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
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'maxattempts' => 1,
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
     * Tests that build_game_config() resolves the scaled boss/student HP for the
     * attempt's own level/phase, not the raw configured base.
     *
     * @return void
     */
    public function test_build_game_config_scales_hp_for_current_phase(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'      => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'basebosshp'    => 100,
            'basestudenthp' => 100,
            'bossdamage'    => 10,
        ]);
        $context = \context_module::instance($cm->id);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 5, ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 1, ['token' => $token]);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        // Level 5, Phase 1 with base 100: boss 300, student 220.
        $this->assertSame(300, $config['bosshp']);
        $this->assertSame(220, $config['studenthp']);
        // Combat damage reuses the same boss HP growth curve: base 10 -> 30 at Level 5/Phase 1.
        $this->assertSame(30, $config['bossdamage']);
        $this->assertSame(5, $config['currentlevel']);
        $this->assertSame(1, $config['currentphase']);
        $this->assertNotSame($token, $config['token']);
    }

    /**
     * Tests that Single Match mode always resolves to the base HP unchanged, since its
     * attempts stay at Level 1, Phase 1 — no special-casing needed in the service.
     *
     * @return void
     */
    public function test_build_game_config_single_match_uses_base_hp(): void {
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'      => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'basebosshp'    => 250,
            'basestudenthp' => 80,
            'bossdamage'    => 15,
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
            'gamemode'      => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'basebosshp'    => 200,
            'basestudenthp' => 100,
            'bossdamage'    => 10,
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
            'gamemode'      => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
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
        $this->assertSame(PLAYERPUZZLE_GAMEMODE_SINGLE, $config['gamemode']);
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
            'gamemode'   => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'basebosshp' => 200,
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
        $this->setGuestUser();
        game_page_service::check_guest_demo_only(true, $this->returnurl);

        $this->setUser($this->student);
        game_page_service::check_guest_demo_only(false, $this->returnurl);

        $this->setGuestUser();
        try {
            game_page_service::check_guest_demo_only(false, $this->returnurl);
            $this->fail('The guest account started a real attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('guestdemoonly', $e->errorcode);
        }
    }
}
