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
 * External function tests for advance_phase.
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
use mod_playerpuzzle\local\attempt_consumables;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\hud_service;

/**
 * Tests for the mod_playerpuzzle_advance_phase web service.
 *
 * @covers \mod_playerpuzzle\external\advance_phase
 */
final class advance_phase_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a Campaign instance with the given overrides.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        // Minquestions defaults to 0 here (the generator's own default is 3) so every
        // existing test in this file, none of which exercises the minimum-questions
        // backstop, keeps advancing a genuinely-won phase without first answering any
        // questions; test_minquestions_* below override it explicitly.
        $record = array_merge([
            'course'       => $this->course->id,
            'gamemode'     => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'maxlevels'    => 10,
            'minquestions' => 0,
        ], $overrides);
        return $generator->create_instance($record);
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
     * Puts an attempt at a specific level/phase and returns its token.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $level Level to set.
     * @param int $phase Phase to set.
     * @return string The attempt's token.
     */
    private function put_attempt_at(int $instanceid, int $level, int $phase): string {
        global $DB;
        $token = security::generate_attempt_token($instanceid, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', $level, ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', $phase, ['token' => $token]);
        return $token;
    }

    /**
     * Calls the mod_playerpuzzle_advance_phase web service through the real dispatch
     * path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_advance_phase(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_advance_phase', $args);
    }

    /**
     * Tests that advancing mid-level (phase < 10) only increments the phase.
     *
     * @return void
     */
    public function test_advance_phase_increments_phase_within_level(): void {
        $instance = $this->make_instance(['basebosshp' => 100]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 2, 3);

        // Boss HP at Level 2, Phase 3 with base 100: 100*(1+0.5*1+0.1*2) = 170.
        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 170,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(2, $result['data']['currentlevel']);
        $this->assertSame(4, $result['data']['currentphase']);
    }

    /**
     * Tests that advancing from Phase 10 rolls over to the next level, Phase 1.
     *
     * @return void
     */
    public function test_advance_phase_rolls_over_to_next_level(): void {
        $instance = $this->make_instance(['basebosshp' => 100, 'maxlevels' => 10]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 3, 10);

        // Boss HP at Level 3, Phase 10 with base 100: 100*(1+0.5*2+0.1*9) = 290.
        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 290,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(4, $result['data']['currentlevel']);
        $this->assertSame(1, $result['data']['currentphase']);
    }

    /**
     * Tests that the response's bosshp/studenthp are scaled for the new phase, not the
     * one just left.
     *
     * @return void
     */
    public function test_advance_phase_returns_hp_scaled_for_new_phase(): void {
        $instance = $this->make_instance(['basebosshp' => 100, 'basestudenthp' => 100]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        // Level 1, Phase 2 with base 100: boss 110, student 105 — one phase step past
        // the Phase 1 baseline.
        $this->assertFalse($result['error']);
        $this->assertSame(110, $result['data']['bosshp']);
        $this->assertSame(105, $result['data']['studenthp']);
    }

    /**
     * Tests that the token is rotated on every successful advance — a captured request
     * cannot be replayed to advance a second time for the same phase win.
     *
     * @return void
     */
    public function test_advance_phase_rotates_token_and_rejects_replay(): void {
        $instance = $this->make_instance(['basebosshp' => 100]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $args = [
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ];
        $first = $this->call_advance_phase($args);
        $second = $this->call_advance_phase($args);

        $this->assertFalse($first['error']);
        $this->assertNotSame($token, $first['data']['token']);
        $this->assertTrue($second['error']);
        $this->assertSame('invalidattempttoken', $second['exception']->errorcode);
    }

    /**
     * Tests that reported damage below the current phase's boss HP is rejected — the
     * client cannot simply claim victory without the server verifying it.
     *
     * @return void
     */
    public function test_advance_phase_rejects_insufficient_damage(): void {
        $instance = $this->make_instance(['basebosshp' => 1000]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 500,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('phasenotwon', $result['exception']->errorcode);
    }

    /**
     * Tests that the win check applies the run's difficulty factor: on Hard the boss has
     * double the HP, so damage that would clear a Normal-sized boss is not enough to
     * advance, but damage clearing the doubled HP is.
     *
     * @return void
     */
    public function test_advance_phase_win_check_respects_difficulty(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'hard');
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 1, ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 1, ['token' => $token]);

        // Normal boss HP here is 100; Hard doubles it to 200.
        $tooweak = $this->call_advance_phase([
            'cmid' => $instance->cmid, 'token' => $token, 'damage' => 150,
            'coinsearnedsofar' => 0, 'bosscoinsearnedsofar' => 0,
        ]);
        $this->assertTrue($tooweak['error']);
        $this->assertSame('phasenotwon', $tooweak['exception']->errorcode);

        $enough = $this->call_advance_phase([
            'cmid' => $instance->cmid, 'token' => $token, 'damage' => 200,
            'coinsearnedsofar' => 0, 'bosscoinsearnedsofar' => 0,
        ]);
        $this->assertFalse($enough['error']);
        $this->assertSame(2, $enough['data']['currentphase']);
    }

    /**
     * Tests that the difficulty chosen on the phase-complete screen is stored on the attempt
     * for the next phase and reflected in the returned scaled HP — Campaign lets the student
     * re-pick at every transition.
     *
     * @return void
     */
    public function test_advance_phase_stores_the_chosen_next_difficulty(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100, 'basestudenthp' => 100]);
        $this->setUser($this->student);
        // Fought this phase on Normal; boss HP here is 100.
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
            'difficulty'           => 'hard',
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame('hard', $result['data']['difficulty']);
        // Phase 2 boss HP is 100 * (1 + 0.1) = 110; Hard doubles it to 220.
        $this->assertSame(220, $result['data']['bosshp']);
        // Student HP is never touched by difficulty: 100 * (1 + 0.05) = 105.
        $this->assertSame(105, $result['data']['studenthp']);
        $this->assertSame('hard', $DB->get_field('playerpuzzle_attempts', 'difficulty', ['token' => $result['data']['token']]));
    }

    /**
     * Tests that the attempt row stays 'inprogress' after advancing — winning a phase
     * never opens or closes an attempt, it is the same continuous streak.
     *
     * @return void
     */
    public function test_advance_phase_leaves_attempt_inprogress(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $result = $this->call_advance_phase([
            'cmid' => $instance->cmid, 'token' => $token, 'damage' => 100,
            'coinsearnedsofar' => 0, 'bosscoinsearnedsofar' => 0,
        ]);

        $newtoken = $result['data']['token'];
        $status = $DB->get_field('playerpuzzle_attempts', 'status', ['token' => $newtoken]);
        $this->assertSame('inprogress', $status);
    }

    /**
     * A student who wins several phases in a row never spends more than the one attempt
     * row they started with — advancing always updates that same row, never inserts a
     * new one. This is the acceptance criterion for "an attempt is a continuous winning
     * streak" (a student who clears 100 phases without ever losing spends only 1 of the
     * `maxattempts` configured, not 100): each advance_phase call here reuses the token
     * the previous call returned, exactly like a real client chaining wins.
     *
     * @return void
     */
    public function test_advance_phase_never_creates_a_second_attempt_row_across_many_wins(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 10]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);
        $originalid = $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        for ($i = 0; $i < 5; $i++) {
            $result = $this->call_advance_phase([
                'cmid'                 => $instance->cmid,
                'token'                => $token,
                'damage'               => 999999,
                'coinsearnedsofar'     => 0,
                'bosscoinsearnedsofar' => 0,
            ]);
            $this->assertFalse($result['error']);
            $token = $result['data']['token'];
        }

        $this->assertSame(
            1,
            $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id, 'userid' => $this->student->id])
        );
        $survivingid = $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);
        $this->assertSame((int) $originalid, (int) $survivingid);
        $this->assertSame(6, (int) $DB->get_field('playerpuzzle_attempts', 'currentphase', ['id' => $originalid]));
    }

    /**
     * Tests that advancing from the last phase of the last level is rejected — there is
     * nothing left to advance to; the client must call save_progress to finish the
     * whole campaign instead.
     *
     * @return void
     */
    public function test_advance_phase_rejects_when_already_at_final_phase(): void {
        $instance = $this->make_instance(['basebosshp' => 100, 'maxlevels' => 3]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 3, 10);

        // Boss HP at Level 3, Phase 10 with base 100: 290 — enough to genuinely win, so
        // the rejection below is specifically the "no next phase" guard, not the damage
        // sanity check.
        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 290,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('nonextphase', $result['exception']->errorcode);
    }

    /**
     * Tests that advancing a phase credits the configured coin item, from the coin
     * ledger's own available balance — the same banking the final save_progress victory
     * itself performs.
     *
     * @return void
     */
    public function test_advance_phase_credits_configured_coin_item(): void {
        [$biid, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['basebosshp' => 100, 'hud_coin_item' => $itemid]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 42,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(42, $result['data']['coinsbanked']);
        $this->assertSame(42, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));
    }

    /**
     * Tests that the win-grant item is credited on every successful phase advance, separate
     * from the coin balance.
     *
     * @return void
     */
    public function test_advance_phase_grants_the_win_grant_item(): void {
        global $DB;

        [$biid, $coinitemid] = $this->make_hud_item();
        $grantitemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $biid,
            'name'            => 'Phase Trophy',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        $instance = $this->make_instance([
            'basebosshp'          => 100,
            'hud_coin_item'       => $coinitemid,
            'hud_win_grant_item'  => $grantitemid,
            'hud_win_grant_qty'   => 2,
        ]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(2, hud_service::get_upgrade_level($biid, $this->student->id, $grantitemid));
    }

    /**
     * Tests that the coin ledger resets to 0 once a phase's payout is banked, so the
     * next phase starts with a clean window rather than carrying the previous phase's
     * earnings forward.
     *
     * @return void
     */
    public function test_advance_phase_resets_the_ledger_after_banking(): void {
        global $DB;

        [, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['basebosshp' => 100, 'hud_coin_item' => $itemid]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 42,
            'bosscoinsearnedsofar' => 10,
        ]);

        $this->assertFalse($result['error']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $result['data']['token']], '*', MUST_EXIST);
        $this->assertSame(0, (int) $attempt->coins_earned);
        $this->assertSame(0, (int) $attempt->boss_coins_earned);
        $this->assertSame(0, (int) $attempt->coins_spent);
    }

    /**
     * Tests that advancing a phase also clears the maxconsumables use count, the same
     * per-phase window the coin ledger itself resets — a student who bought their one
     * allowed Poção in phase 1 must be able to buy one again in phase 2, not stay locked
     * out of that type for the rest of the Campaign attempt.
     *
     * @return void
     */
    public function test_advance_phase_resets_consumable_uses_after_banking(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);

        attempt_consumables::record_use((int) $attempt->id, 'potion');
        attempt_consumables::record_use((int) $attempt->id, 'sword');
        $this->assertSame(1, attempt_consumables::get_uses((int) $attempt->id, 'potion'));

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(0, attempt_consumables::get_uses((int) $attempt->id, 'potion'));
        $this->assertSame(0, attempt_consumables::get_uses((int) $attempt->id, 'sword'));
    }

    /**
     * Tests that advancing a phase clears the saved combat checkpoint — the next phase
     * always starts with a fresh board and full HP, never resuming the one just finished.
     *
     * @return void
     */
    public function test_advance_phase_clears_the_combat_checkpoint(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $DB->set_field('playerpuzzle_attempts', 'combatstate', '{"boardgrid":[]}', ['id' => $attempt->id]);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertNull($DB->get_field('playerpuzzle_attempts', 'combatstate', ['id' => $attempt->id]));
    }

    /**
     * Tests that an unknown/forged token is rejected with the dedicated exception.
     *
     * @return void
     */
    public function test_advance_phase_rejects_unknown_token(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => str_repeat('a', 64),
            'damage'               => 1000,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced — same
     * mechanism already verified for save_progress/validate_answer (cm_info's own
     * visibility computation reads this capability before execute()'s own
     * require_capability() line is ever reached).
     *
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance(['basebosshp' => 100]);
        $modcontext = context_module::instance($instance->cmid);

        $prohibitedrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/playerpuzzle:view', CAP_PROHIBIT, $prohibitedrole, $modcontext);
        role_assign($prohibitedrole, $this->student->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $this->expectException(\core\exception\require_login_exception::class);
        advance_phase::execute($instance->cmid, $token, 100, 0, 0);
    }

    /**
     * Tests that a genuinely-won phase is still rejected when the attempt hasn't
     * answered enough questions yet — the server-side backstop against a client that
     * bypasses the boss-revive rule entirely, leaving the attempt untouched (still on
     * the same phase, token unrotated) so a legitimate follow-up call can still advance
     * it once the requirement is met.
     *
     * @return void
     */
    public function test_minquestions_backstop_rejects_premature_advance(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100, 'minquestions' => 3]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);
        $DB->set_field('playerpuzzle_attempts', 'questions_total', 1, ['token' => $token]);

        $result = $this->call_advance_phase([
            'cmid' => $instance->cmid, 'token' => $token, 'damage' => 100,
            'coinsearnedsofar' => 0, 'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('minquestionsnotmet', $result['exception']->errorcode);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame('inprogress', $attempt->status);
        $this->assertSame(1, (int) $attempt->currentphase);
    }

    /**
     * Tests that advancing succeeds once the attempt has answered at least the
     * configured minimum.
     *
     * @return void
     */
    public function test_minquestions_backstop_allows_advance_once_met(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100, 'minquestions' => 3]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);
        $DB->set_field('playerpuzzle_attempts', 'questions_total', 3, ['token' => $token]);

        $result = $this->call_advance_phase([
            'cmid' => $instance->cmid, 'token' => $token, 'damage' => 100,
            'coinsearnedsofar' => 0, 'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
    }

    /**
     * Tests that the amount banked is capped by the plausibility ceiling — sized to this
     * phase's own boss HP, not to damage actually dealt.
     *
     * @return void
     */
    public function test_coin_ceiling_caps_an_inflated_report(): void {
        [, $itemid] = $this->make_hud_item();
        // Bossdamage/coingain default to 10; at Level 1 Phase 1 Normal the scaled combo
        // damage is 10 too, so with basebosshp 100 the ceiling is floor((100/10)*10*1) = 100.
        $instance = $this->make_instance(['basebosshp' => 100, 'hud_coin_item' => $itemid]);
        $this->setUser($this->student);
        $token = $this->put_attempt_at((int) $instance->id, 1, 1);

        $result = $this->call_advance_phase([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'damage'               => 100,
            'coinsearnedsofar'     => 99999,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(100, $result['data']['coinsbanked']);
    }
}
