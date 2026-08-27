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
 * External function tests for save_progress.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_course;
use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\hud_service;

/**
 * Tests for the mod_playerpuzzle_save_progress web service.
 *
 * @covers \mod_playerpuzzle\external\save_progress
 */
final class save_progress_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
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

        $ctx = context_course::instance($this->course->id);
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
            'name'            => 'Gold Coin',
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
     * Creates a playerpuzzle instance, optionally with a configured coin item.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $record = array_merge(['course' => $this->course->id, 'basebosshp' => 1000], $overrides);
        $instance = $generator->create_instance($record);

        return $instance;
    }

    /**
     * Calls the mod_playerpuzzle_save_progress web service through the real dispatch
     * path, exercising sesskey, capability and parameter validation.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_save_progress(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_save_progress', $args);
    }

    /**
     * Tests that a victory credits the configured coin item with the exact gold amount.
     *
     * @return void
     */
    public function test_victory_credits_configured_coin_item(): void {
        [$biid, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $itemid]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_save_progress([
            'cmid'    => $instance->cmid,
            'token'   => $token,
            'gold'    => 42,
            'victory' => 1,
            'damage'  => 500,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(42, $result['data']['coinsbanked']);
        $this->assertSame(42, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));
    }

    /**
     * Tests that a defeat discards the session's gold — nothing is credited even
     * though a positive amount was reported.
     *
     * @return void
     */
    public function test_defeat_discards_gold(): void {
        [, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $itemid]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_save_progress([
            'cmid'    => $instance->cmid,
            'token'   => $token,
            'gold'    => 42,
            'victory' => 0,
            'damage'  => 200,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(0, $result['data']['coinsbanked']);
    }

    /**
     * Tests that reported damage is clamped to the instance's own basebosshp, never
     * trusting a client-reported value beyond what the server itself configured.
     *
     * @return void
     */
    public function test_damage_is_clamped_to_basebosshp(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 1000]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_save_progress([
            'cmid'    => $instance->cmid,
            'token'   => $token,
            'gold'    => 0,
            'victory' => 1,
            'damage'  => 999999,
        ]);

        $this->assertFalse($result['error']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(0, (int) $attempt->bosshp_remaining);
        $this->assertEqualsWithDelta(100.0, (float) $attempt->score, 0.001);
    }

    /**
     * Tests that the damage clamp uses the boss HP scaled for the attempt's own
     * level/phase (combat::calculate_boss_hp()), not the raw configured base — a
     * student mid-Campaign at Level 5, Phase 1 has a boss with 300 HP (basebosshp=100),
     * not 100. Before this was fixed, every Campaign attempt past Level 1 Phase 1 was
     * wrongly capped to the base value.
     *
     * @return void
     */
    public function test_damage_is_clamped_to_phase_scaled_hp_not_base(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 5, ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 1, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'    => $instance->cmid,
            'token'   => $token,
            'gold'    => 0,
            'victory' => 1,
            'damage'  => 250,
        ]);

        $this->assertFalse($result['error']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        // Boss HP at Level 5, Phase 1 with basebosshp=100 is 300.
        // 250 damage is well within that, so it must not be clamped down to 100.
        $this->assertSame(50, (int) $attempt->bosshp_remaining);
        $this->assertEqualsWithDelta(83.33333, (float) $attempt->score, 0.001);
    }

    /**
     * Tests that the damage clamp also applies the run's difficulty factor: on Hard the
     * boss has double the HP, so a partial run is scored against that doubled total rather
     * than being wrongly capped at 100% for the Normal-sized boss.
     *
     * @return void
     */
    public function test_damage_clamp_respects_difficulty(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'hard');

        // Hard boss HP at Level 1, Phase 1 with basebosshp=100 is 200. 150 damage is a
        // 75% dent — the score, not a clamped-to-100 100%.
        $result = $this->call_save_progress([
            'cmid'    => $instance->cmid,
            'token'   => $token,
            'gold'    => 0,
            'victory' => 0,
            'damage'  => 150,
        ]);

        $this->assertFalse($result['error']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(50, (int) $attempt->bosshp_remaining);
        $this->assertEqualsWithDelta(75.0, (float) $attempt->score, 0.001);
    }

    /**
     * Tests that an unknown/forged token is rejected with the dedicated exception —
     * never silently accepted, never a generic coding error.
     *
     * @return void
     */
    public function test_invalid_token_is_rejected(): void {
        $instance = $this->make_instance();

        $this->setUser($this->student);

        $result = $this->call_save_progress([
            'cmid'    => $instance->cmid,
            'token'   => str_repeat('a', 64),
            'gold'    => 10,
            'victory' => 1,
            'damage'  => 10,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that a token already consumed by a previous call cannot be replayed to
     * bank coins a second time.
     *
     * @return void
     */
    public function test_replayed_token_is_rejected(): void {
        [, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $itemid]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $args = [
            'cmid'    => $instance->cmid,
            'token'   => $token,
            'gold'    => 42,
            'victory' => 1,
            'damage'  => 500,
        ];
        $first = $this->call_save_progress($args);
        $second = $this->call_save_progress($args);

        $this->assertFalse($first['error']);
        $this->assertTrue($second['error']);
        $this->assertSame('invalidattempttoken', $second['exception']->errorcode);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced, not just
     * declared — a role with it explicitly prohibited is denied. cm_info's own
     * visibility computation reads this exact capability (is_user_access_restricted_
     * by_capability(), core/classes/cm_info.php), so the module becomes uservisible =
     * false and validate_context()'s require_login() call rejects the request before
     * execute()'s own require_capability() line is ever reached.
     *
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance();
        $modcontext = context_module::instance($instance->cmid);

        $prohibitedrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/playerpuzzle:view', CAP_PROHIBIT, $prohibitedrole, $modcontext);
        role_assign($prohibitedrole, $this->student->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $this->expectException(\core\exception\require_login_exception::class);
        save_progress::execute($instance->cmid, $token, 10, 1, 10);
    }
}
