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
use mod_playerpuzzle\local\move_log;
use mod_playerpuzzle\local\replay_credit;
use mod_playerpuzzle\local\user_stock;

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
        // Minquestions defaults to 0 here (the generator's own default is 3) so every
        // existing test in this file, none of which exercises the minimum-questions
        // backstop, keeps banking a reported victory without first answering any
        // questions; test_minquestions_* below override it explicitly.
        $record = array_merge(
            ['course' => $this->course->id, 'basebosshp' => 1000, 'minquestions' => 0],
            $overrides
        );
        $instance = $generator->create_instance($record);

        return $instance;
    }

    /**
     * Gives an attempt a phase the server-side replay verifies, as the live client's own log
     * would: a seed and its recorded moves, with the frozen config they were played at. Boss
     * HP and combo damage are frozen equal, so a single 3-Sword match finishes the boss at
     * any level, phase or difficulty. The default is seed 2's one-move win (see
     * tests/local/engine/replay_test.php): 15 coins for the player, none for the boss.
     *
     * @param string $token The attempt's token.
     * @param int $seed The phase's PRNG seed.
     * @param array $moves Recorded swaps, each [r1, c1, r2, c2].
     * @return void
     */
    private function make_verified_phase(string $token, int $seed = 2, array $moves = [[3, 1, 3, 2]]): void {
        global $DB;

        $events = array_map(
            static fn(array $m): array => ['type' => 'move', 'r1' => $m[0], 'c1' => $m[1], 'r2' => $m[2], 'c2' => $m[3]],
            $moves
        );
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token], MUST_EXIST);
        $DB->update_record('playerpuzzle_attempts', (object) [
            'id' => $attemptid,
            'rngseed' => $seed,
            'movelog' => move_log::encode($events),
            'moveseq' => count($events),
            'frozenbasebosshp' => 10,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
        ]);
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
     * Tests that a victory credits PuzzleCoin, from the replay-verified coin total
     * rather than a raw client-reported one — and never auto-credits the configured
     * PlayerHUD coin item, even though one is configured: PlayerHUD coins only ever reach
     * PuzzleCoin through an explicit, student-initiated transfer.
     *
     * @return void
     */
    public function test_victory_credits_puzzlecoin(): void {
        [$biid, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $itemid]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->make_verified_phase($token);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 500,
            'coinsearnedsofar'     => 42,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(15, $result['data']['coinsbanked']);
        $this->assertSame(
            15,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
        $this->assertSame(0, hud_service::get_upgrade_level($biid, $this->student->id, $itemid));
    }

    /**
     * Tests that a victory credits PuzzleCoin even without PlayerHUD configured at all —
     * buying and banking consumables must work purely on the plugin's own currency, never
     * requiring PlayerHUD, so a course without the block can still use consumables.
     *
     * @return void
     */
    public function test_victory_credits_puzzlecoin_without_playerhud(): void {
        $instance = $this->make_instance();

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->make_verified_phase($token);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 500,
            'coinsearnedsofar'     => 42,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(15, $result['data']['coinsbanked']);
        $this->assertSame(
            15,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
    }

    /**
     * Tests that a victory grants the win-grant item, separate from the coin balance.
     *
     * @return void
     */
    public function test_victory_grants_the_win_grant_item(): void {
        global $DB;

        [$biid, $coinitemid] = $this->make_hud_item();
        $grantitemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $biid,
            'name'            => 'Campaign Trophy',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        $instance = $this->make_instance([
            'hud_coin_item'      => $coinitemid,
            'hud_win_grant_item' => $grantitemid,
            'hud_win_grant_qty'  => 3,
        ]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->make_verified_phase($token);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 500,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(3, hud_service::get_upgrade_level($biid, $this->student->id, $grantitemid));
    }

    /**
     * Tests that the win-grant's suppressxp check reads max_single_matches (not maxattempts)
     * in Single Match mode — a victory still grants the item even with max_single_matches
     * set to Unlimited (0); only the item's own XP is what would be withheld, which is
     * block_playerhud's own concern, not something this test can observe here.
     *
     * @return void
     */
    public function test_victory_grants_the_win_grant_item_in_single_match_mode(): void {
        global $DB;

        [$biid, $coinitemid] = $this->make_hud_item();
        $grantitemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $biid,
            'name'            => 'Match Trophy',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        $instance = $this->make_instance([
            'gamemode'           => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'max_single_matches' => 0,
            'hud_coin_item'      => $coinitemid,
            'hud_win_grant_item' => $grantitemid,
            'hud_win_grant_qty'  => 1,
        ]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->make_verified_phase($token);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 500,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(1, hud_service::get_upgrade_level($biid, $this->student->id, $grantitemid));
    }

    /**
     * Tests that a defeat never grants the win-grant item.
     *
     * @return void
     */
    public function test_defeat_does_not_grant_the_win_grant_item(): void {
        global $DB;

        [$biid, $coinitemid] = $this->make_hud_item();
        $grantitemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $biid,
            'name'            => 'Campaign Trophy',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        $instance = $this->make_instance([
            'hud_coin_item'      => $coinitemid,
            'hud_win_grant_item' => $grantitemid,
        ]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 0,
            'damage'               => 100,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(0, hud_service::get_upgrade_level($biid, $this->student->id, $grantitemid));
    }

    /**
     * Tests that save_progress returns the phase's answered questions for the post-game
     * review — this phase's rows only, oldest first.
     *
     * @return void
     */
    public function test_returns_the_phase_question_log(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 2, ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 5, ['token' => $token]);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        \mod_playerpuzzle\local\attempt_questions::record($attemptid, 1, 2, 5, '<p>A</p>', 'yes', 'yes', true);
        \mod_playerpuzzle\local\attempt_questions::record($attemptid, 2, 2, 5, '<p>B</p>', 'no', 'maybe', false);
        \mod_playerpuzzle\local\attempt_questions::record($attemptid, 3, 2, 4, '<p>C</p>', 'x', 'x', true);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 0,
            'damage'               => 0,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $log = $result['data']['questionlog'];
        $this->assertCount(2, $log);
        $this->assertSame('<p>A</p>', $log[0]['questiontext']);
        $this->assertTrue($log[0]['iscorrect']);
        $this->assertFalse($log[1]['iscorrect']);
        $this->assertSame('maybe', $log[1]['correctanswer']);
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
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 0,
            'damage'               => 200,
            'coinsearnedsofar'     => 42,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(0, $result['data']['coinsbanked']);
    }

    /**
     * Tests that a verified win scores full marks against the boss HP the phase was really
     * fought at — scaled for the attempt's own level/phase from the frozen config — whatever
     * damage the client claims.
     *
     * @return void
     */
    public function test_a_verified_win_scores_full_marks_against_the_phase_hp(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 5, ['token' => $token]);
        $this->make_verified_phase($token);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 1,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame('won', $result['data']['outcome']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame('won', $attempt->status);
        $this->assertSame(0, (int) $attempt->bosshp_remaining);
        $this->assertEqualsWithDelta(100.0, (float) $attempt->score, 0.001);
    }

    /**
     * Tests that a defeat the replay cannot verify to the end is scored by the damage its log
     * still vouches for, against the phase-scaled HP — never by the claim. Seed 2's kill move
     * against a 1000 HP boss (frozen) at Level 5, Phase 1: everything scales x3, so the
     * verified Sword match deals 30 of 3000.
     *
     * @return void
     */
    public function test_an_unverifiable_defeat_scores_only_the_verified_damage(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 5, ['token' => $token]);
        $this->make_verified_phase($token);
        $DB->set_field('playerpuzzle_attempts', 'frozenbasebosshp', 1000, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 0,
            'damage'               => 2999,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame('lost', $result['data']['outcome']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(2970, (int) $attempt->bosshp_remaining);
        $this->assertEqualsWithDelta(1.0, (float) $attempt->score, 0.001);
    }

    /**
     * Tests that scoring applies the run's difficulty factor: on Hard the boss has double the
     * HP (and deals double damage), so the same verified Sword match is 20 of 2000.
     *
     * @return void
     */
    public function test_scoring_respects_difficulty(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 100]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'hard', 1, 1);
        $this->make_verified_phase($token);
        $DB->set_field('playerpuzzle_attempts', 'frozenbasebosshp', 1000, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 0,
            'damage'               => 150,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(1980, (int) $attempt->bosshp_remaining);
        $this->assertEqualsWithDelta(1.0, (float) $attempt->score, 0.001);
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
            'cmid'                 => $instance->cmid,
            'token'                => str_repeat('a', 64),
            'victory'              => 1,
            'damage'               => 10,
            'coinsearnedsofar'     => 10,
            'bosscoinsearnedsofar' => 0,
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
        $this->make_verified_phase($token);

        $args = [
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 500,
            'coinsearnedsofar'     => 42,
            'bosscoinsearnedsofar' => 0,
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
        save_progress::execute($instance->cmid, $token, 1, 10, 10, 0);
    }

    /**
     * Tests that a claimed victory is rejected when the attempt hasn't answered enough
     * questions yet — the server-side backstop against a client that bypasses the
     * boss-revive rule entirely, leaving the attempt untouched (still 'inprogress',
     * token unconsumed) so a legitimate follow-up call can still finish it properly.
     *
     * @return void
     */
    public function test_minquestions_backstop_rejects_premature_victory(): void {
        global $DB;

        $instance = $this->make_instance(['minquestions' => 3]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'questions_total', 2, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 1000,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('minquestionsnotmet', $result['exception']->errorcode);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame('inprogress', $attempt->status);
    }

    /**
     * Tests that the minquestions backstop never blocks a defeat/timeout — the
     * requirement only gates ending in victory.
     *
     * @return void
     */
    public function test_minquestions_backstop_does_not_block_defeat(): void {
        global $DB;

        $instance = $this->make_instance(['minquestions' => 3]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'questions_total', 1, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 0,
            'damage'               => 100,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
    }

    /**
     * Tests that a victory is accepted once the attempt has answered at least the
     * configured minimum.
     *
     * @return void
     */
    public function test_minquestions_backstop_allows_victory_once_met(): void {
        global $DB;

        $instance = $this->make_instance(['minquestions' => 3]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'questions_total', 3, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 1000,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
    }

    /**
     * Tests that a victory the replay cannot verify restarts the phase within the same
     * attempt: nothing banked, the attempt still in progress on a fresh seed, the same token
     * still valid, and one restart counted.
     *
     * @return void
     */
    public function test_an_unverifiable_victory_restarts_the_phase(): void {
        global $DB;

        $instance = $this->make_instance();

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $before = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 1000,
            'coinsearnedsofar'     => 99999,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame('restarted', $result['data']['outcome']);
        $this->assertSame(0, $result['data']['coinsbanked']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['id' => $before->id], '*', MUST_EXIST);
        $this->assertSame('inprogress', $attempt->status);
        $this->assertSame($token, $attempt->token);
        $this->assertSame(1, (int) $attempt->phaserestarts);
        $this->assertSame(0, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE));
    }

    /**
     * Tests that an unverifiable victory with no restart left counts as a defeat, and says so.
     *
     * @return void
     */
    public function test_an_unverifiable_victory_counts_as_lost_once_restarts_run_out(): void {
        global $DB;

        $instance = $this->make_instance();

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'phaserestarts', replay_credit::MAX_PHASE_RESTARTS, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 1000,
            'coinsearnedsofar'     => 99999,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame('lost', $result['data']['outcome']);
        $this->assertSame(get_string('victoryunverifiedlost', 'mod_playerpuzzle'), $result['data']['message']);
        $this->assertSame('lost', $DB->get_field('playerpuzzle_attempts', 'status', ['token' => $token]));
        $this->assertSame(0, $result['data']['coinsbanked']);
    }

    /**
     * Tests that a claimed victory the replay verifies as a defeat is recorded as one: on
     * seed 2, a coin match then the boss's turn finishes a 1 HP student.
     *
     * @return void
     */
    public function test_a_claimed_victory_the_replay_finds_lost_counts_as_lost(): void {
        global $DB;

        $instance = $this->make_instance(['basestudenthp' => 1]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->make_verified_phase($token, 2, [[0, 4, 1, 4]]);
        $DB->set_field('playerpuzzle_attempts', 'frozenbasebosshp', 1000, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 1000,
            'coinsearnedsofar'     => 10,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame('lost', $result['data']['outcome']);
        $this->assertSame('lost', $DB->get_field('playerpuzzle_attempts', 'status', ['token' => $token]));
        $this->assertSame(0, $result['data']['coinsbanked']);
    }

    /**
     * Tests that a victorious Demo attempt never banks coins, even with a
     * PlayerHUD coin item configured and a genuine win reported — a Demo has no economic
     * effect by design, since it is repeatable at will.
     *
     * @return void
     */
    public function test_demo_victory_never_banks_coins(): void {
        [$biid, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $itemid, 'basebosshp' => 1000]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => \mod_playerpuzzle\local\engine\combat::DEMO_HP,
            'coinsearnedsofar'     => 100,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(0, $result['data']['coinsbanked']);
        $this->assertSame(0, hud_service::get_upgrade_level($biid, (int) $this->student->id, $itemid));
        $this->assertSame(
            0,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
    }

    /**
     * Tests that a finished Demo attempt never triggers a gradebook update — a repeatable,
     * fixed-HP practice fight has no grade to contribute.
     *
     * @return void
     */
    public function test_demo_attempt_never_updates_the_gradebook(): void {
        global $DB;

        $instance = $this->make_instance(['grade' => 100]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );

        $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => \mod_playerpuzzle\local\engine\combat::DEMO_HP,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $itemid = $DB->get_field('grade_items', 'id', [
            'itemmodule'   => 'playerpuzzle',
            'iteminstance' => $instance->id,
        ]);
        $this->assertSame(0, $DB->count_records('grade_grades', ['itemid' => $itemid, 'userid' => $this->student->id]));
    }

    /**
     * Tests that the boss's own coin gain nets against the player's before
     * payout — the boss's combos never bank anything for itself, they only reduce what
     * the student takes home.
     *
     * @return void
     */
    public function test_boss_coins_net_against_payout(): void {
        [$biid, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $itemid]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        // Seed 20: a first match, the boss's turn earns it 10 coins, then the finishing
        // Sword match — 20 coins for the player in all.
        $this->make_verified_phase($token, 20, [[2, 6, 2, 7], [3, 4, 3, 5]]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 500,
            'coinsearnedsofar'     => 20,
            'bosscoinsearnedsofar' => 10,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(10, $result['data']['coinsbanked']);
    }

    /**
     * Tests that a saved combat checkpoint is cleared once the attempt
     * reaches a final status — there is no fight left to resume.
     *
     * @return void
     */
    public function test_finishing_the_attempt_clears_the_combat_checkpoint(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'combatstate', '{"boardgrid":[]}', ['id' => $attemptid]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 500,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertNull($DB->get_field('playerpuzzle_attempts', 'combatstate', ['id' => $attemptid]));
    }

    /**
     * Tests that a victory recomputes and persists the completionwins custom rule
     * immediately, not just at the next cron run (Moodle has none for completion) — mirrors
     * mod_playerwords' own update_state() call right after a round finishes.
     *
     * @return void
     */
    public function test_victory_updates_completion_state_when_wins_required(): void {
        global $CFG;
        $CFG->enablecompletion = true;

        // A course with completion enabled is required here — the shared $this->course from
        // setUp() does not have it, and add_moduleinfo() silently leaves the course_module's
        // own completion tracking at NONE whenever the course itself has completion off,
        // regardless of what is passed in the instance record.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $instance = $this->make_instance([
            'course'                => $course->id,
            'completion'            => COMPLETION_TRACKING_AUTOMATIC,
            'completionwinsenabled' => true,
            'completionwins'        => 1,
        ]);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->make_verified_phase($token);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 500,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $cm = get_coursemodule_from_id('playerpuzzle', $instance->cmid, 0, false, MUST_EXIST);
        $completioninfo = new \completion_info($course);
        $data = $completioninfo->get_data($cm, false, (int) $this->student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $data->completionstate);
    }

    /**
     * Tests the anti-cheat replay end to end, through the real web service: a known seed and
     * a known recorded event log, re-simulated server-side, drive what gets persisted and
     * banked — never the client's own wildly inflated claim. The seed/move pair is the same
     * one tests/local/engine/replay_test.php locks in as always deriving damage 10, playergold
     * 15, bossgold 0 (a single Sword-match kill of a 10 HP boss, with an incidental Coin match
     * in the same cascade) — see that test's own docblock for where those numbers come from.
     * The derived truth (10 damage, 15 coins), not the forged claim (99999/99999), is what
     * reaches both the persisted attempt row and the banked total.
     *
     * @return void
     */
    public function test_replay_credits_the_derived_value_not_an_inflated_claim(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 10, 'bossdamage' => 10, 'coingain' => 10]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'rngseed', 2, ['token' => $token]);
        $DB->set_field(
            'playerpuzzle_attempts',
            'movelog',
            move_log::encode([['type' => 'move', 'r1' => 3, 'c1' => 1, 'r2' => 3, 'c2' => 2]]),
            ['token' => $token]
        );

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            // A forged claim of far more damage/gold than the recorded seed+move actually
            // produced — the replay's own derived truth must win, not this claim.
            'damage'               => 99999,
            'coinsearnedsofar'     => 99999,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(15, $result['data']['coinsbanked']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(0, (int) $attempt->bosshp_remaining);
        $this->assertEqualsWithDelta(100.0, (float) $attempt->score, 0.001);
    }

    /**
     * Tests that events the last checkpoint never sent, carried by the final call itself,
     * reach the replay: nothing is stored beforehand, the claim undersells the match (1
     * damage, no coins), and only the replay of the move sent with the call can produce the
     * full 100% score and the 10 coins this seed-2 win is worth (see the test above).
     *
     * @return void
     */
    public function test_the_final_call_carries_the_events_the_replay_needs(): void {
        global $DB;

        $instance = $this->make_instance(['basebosshp' => 10, 'bossdamage' => 10, 'coingain' => 10]);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'rngseed', 2, ['token' => $token]);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 1,
            'damage'               => 1,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
            'eventoffset'          => 0,
            'movelog'              => [['type' => 'move', 'r1' => 3, 'c1' => 1, 'r2' => 3, 'c2' => 2]],
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(15, $result['data']['coinsbanked']);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(100.0, (float) $attempt->score, 0.001);
        $this->assertSame(1, (int) $attempt->moveseq);
    }

    /**
     * Tests that a defeat does NOT satisfy completionwins, even though the attempt is now
     * finished — only completionattempts counts a loss towards its own threshold.
     *
     * @return void
     */
    public function test_defeat_does_not_satisfy_completion_wins(): void {
        global $CFG;
        $CFG->enablecompletion = true;

        // Same reasoning as test_victory_updates_completion_state_when_wins_required(): a
        // dedicated course with completion enabled, not the shared $this->course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $instance = $this->make_instance([
            'course'                => $course->id,
            'completion'            => COMPLETION_TRACKING_AUTOMATIC,
            'completionwinsenabled' => true,
            'completionwins'        => 1,
        ]);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_save_progress([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'victory'              => 0,
            'damage'               => 0,
            'coinsearnedsofar'     => 0,
            'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $cm = get_coursemodule_from_id('playerpuzzle', $instance->cmid, 0, false, MUST_EXIST);
        $completioninfo = new \completion_info($course);
        $data = $completioninfo->get_data($cm, false, (int) $this->student->id);
        $this->assertEquals(COMPLETION_INCOMPLETE, $data->completionstate);
    }

    /**
     * Tests that a win banks its coins under the stock lock a Lobby purchase takes, nested
     * inside the attempt lock — two different locks on the same stock row would not exclude
     * each other, and a purchase racing the payout could lose one of the two updates.
     *
     * @return void
     */
    public function test_victory_banks_coins_under_the_attempt_and_stock_locks(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/recording_lock_factory.php');

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->make_verified_phase($token);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);
        $stock = 'stock_' . $this->student->id . '_' . $instance->id;
        \mod_playerpuzzle_recording_lock_factory::install();

        $result = $this->call_save_progress([
            'cmid' => $instance->cmid, 'token' => $token, 'victory' => 1, 'damage' => 10,
            'coinsearnedsofar' => 15, 'bosscoinsearnedsofar' => 0,
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(15, $result['data']['coinsbanked']);
        $this->assertSame([
            "acquire attempt_{$attemptid}",
            "acquire {$stock}",
            "release {$stock}",
            "release attempt_{$attemptid}",
        ], \mod_playerpuzzle_recording_lock_factory::events_for('mod_playerpuzzle'));
    }

    /**
     * Tests that when the stock lock cannot be had, the call fails before writing anything:
     * the attempt stays in progress with its token, no coins are banked, and the same call
     * succeeds once the lock is free again.
     *
     * @return void
     */
    public function test_a_busy_stock_lock_leaves_the_attempt_to_retry(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/recording_lock_factory.php');

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->make_verified_phase($token);
        $stock = 'stock_' . $this->student->id . '_' . $instance->id;
        \mod_playerpuzzle_recording_lock_factory::install(["mod_playerpuzzle/{$stock}"]);
        $args = [
            'cmid' => $instance->cmid, 'token' => $token, 'victory' => 1, 'damage' => 10,
            'coinsearnedsofar' => 15, 'bosscoinsearnedsofar' => 0,
        ];

        $result = $this->call_save_progress($args);

        $this->assertTrue($result['error']);
        $this->assertSame('stockbusy', $result['exception']->errorcode);
        $this->assertSame('inprogress', $DB->get_field('playerpuzzle_attempts', 'status', ['token' => $token]));
        $this->assertSame(
            0,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );

        \mod_playerpuzzle_recording_lock_factory::install();
        $retry = $this->call_save_progress($args);

        $this->assertFalse($retry['error']);
        $this->assertSame(15, $retry['data']['coinsbanked']);
    }
}
