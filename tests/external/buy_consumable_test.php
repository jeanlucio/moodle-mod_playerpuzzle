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
 * External function tests for buy_consumable.
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
use mod_playerpuzzle\local\questions_repository;

/**
 * Tests for the mod_playerpuzzle_buy_consumable web service.
 *
 * @covers \mod_playerpuzzle\external\buy_consumable
 */
final class buy_consumable_test extends \advanced_testcase {
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
     * Creates a playerpuzzle instance, with a comfortably high basebosshp so the coin
     * ceiling (sized to this phase's own boss HP) never accidentally clamps below what a
     * test needs.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $record = array_merge([
            'course'         => $this->course->id,
            'basebosshp'     => 1000,
            'bossdamage'     => 10,
            'coingain'       => 10,
            'maxconsumables' => 1,
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
            'name'            => 'Sword Charge',
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
     * Calls the mod_playerpuzzle_buy_consumable web service through the real dispatch
     * path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_buy_consumable(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_buy_consumable', $args);
    }

    /**
     * Default args for a local-coin purchase, merged with per-test overrides.
     *
     * @param \stdClass $instance Instance record.
     * @param string $token Attempt token.
     * @param array $overrides Argument overrides.
     * @return array
     */
    private function local_args(\stdClass $instance, string $token, array $overrides = []): array {
        return array_merge([
            'cmid'                 => $instance->cmid,
            'token'                => $token,
            'type'                 => 'potion',
            'source'               => 'local',
            'coinsearnedsofar'     => 100,
            'bosscoinsearnedsofar' => 0,
        ], $overrides);
    }

    /**
     * Tests a successful local-coin purchase: debits coins_spent, records the use, and
     * returns the correct remaining balance.
     *
     * @return void
     */
    public function test_local_purchase_success(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $result = $this->call_buy_consumable($this->local_args($instance, $token));

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame('potion', $result['data']['apply']);
        // Ceiling sized to this phase's boss HP (1000) / 10 scaled bossdamage * 10 coingain
        // * 1.0 = 1000, comfortably above the 100 reported; potion costs 8, so
        // newbalance = 100 - 8 = 92.
        $this->assertSame(92, $result['data']['newbalance']);
        $this->assertSame(8, (int) $DB->get_field('playerpuzzle_attempts', 'coins_spent', ['id' => $attemptid]));
        $this->assertSame(1, attempt_consumables::get_uses($attemptid, 'potion'));
    }

    /**
     * Tests that a purchase beyond the available balance is rejected without debiting
     * anything.
     *
     * @return void
     */
    public function test_local_purchase_rejects_insufficient_coins(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'             => 'magic',
            'coinsearnedsofar' => 0,
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('insufficientcoins', $result['exception']->errorcode);
        $this->assertSame(0, (int) $DB->get_field('playerpuzzle_attempts', 'coins_spent', ['id' => $attemptid]));
        $this->assertSame(0, attempt_consumables::get_uses($attemptid, 'magic'));
    }

    /**
     * Tests that a purchase succeeds on genuinely-earned coins even when the boss has not
     * taken any damage yet this phase — a regression check for sizing the coin ceiling to
     * damage dealt so far, which floored to 0 before a student's first Sword hit, blocking
     * every purchase even with real coins on hand. The ceiling is now sized to this phase's
     * own boss HP instead (combat::coin_ceiling()), a stable value independent of live
     * combat progress.
     *
     * @return void
     */
    public function test_local_purchase_works_before_any_damage_dealt(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        // 20 coins earned, boss earned 10, buying a 10-coin Shield — with the boss still at
        // full HP (no damage dealt this phase). bosscoinsearnedsofar is deliberately
        // non-zero here too, doubling as a regression check that the purchase gate calls
        // coin_ledger::spendable() (no boss netting), not coin_ledger::available() (nets the
        // boss's own coin share against the student's — correct for the final phase/match
        // payout, wrong for a mid-match spending check) — newbalance = 20 - 10 (shield price)
        // = 10, not 0 as it would be if the boss's 10 were still subtracted.
        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'                 => 'shield',
            'coinsearnedsofar'     => 20,
            'bosscoinsearnedsofar' => 10,
        ]));

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame(10, $result['data']['newbalance']);
    }

    /**
     * Tests that the reported coin total is still capped by the plausibility ceiling
     * (sized to this phase's own boss HP) even though it is no longer damage-based — a
     * client claiming a wildly inflated balance is still bounded to a value proportional
     * to the phase's own size, not left unbounded.
     *
     * @return void
     */
    public function test_local_purchase_respects_the_coin_ceiling(): void {
        // A small basebosshp keeps the ceiling itself small and easy to check: boss HP 50
        // / scaled bossdamage 10 * coingain 10 * Normal factor 1.0 = 50.
        $instance = $this->make_instance(['basebosshp' => 50]);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'normal', 1, 1);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'             => 'sword',
            'coinsearnedsofar' => 99999,
        ]));

        $this->assertFalse($result['error']);
        // Reported 99999 clamped to the ceiling (50); sword costs 10, so newbalance = 40.
        $this->assertSame(40, $result['data']['newbalance']);
    }

    /**
     * Tests that a Demo attempt's local-shop coin ceiling is always sized
     * to the fixed combat::DEMO_HP, ignoring the instance's own configured basebosshp
     * entirely — a Demo may still use the local shop, just never anchored to real numbers.
     *
     * @return void
     */
    public function test_demo_attempt_uses_the_fixed_demo_hp_ceiling(): void {
        // Ceiling here is DEMO_HP (50) / scaled bossdamage 10 * coingain 10 * 1.0 = 50,
        // regardless of the huge basebosshp configured below.
        $instance = $this->make_instance(['basebosshp' => 100000]);
        $this->setUser($this->student);
        $token = security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'             => 'sword',
            'coinsearnedsofar' => 99999,
        ]));

        $this->assertFalse($result['error']);
        $this->assertSame(40, $result['data']['newbalance']);
    }

    /**
     * Tests that a Demo attempt may never spend the student's real
     * PlayerHUD inventory, even when the instance has a real item configured for that
     * consumable type — a Demo has no economic effect by design.
     *
     * @return void
     */
    public function test_demo_attempt_cannot_use_hud_source(): void {
        [$biid, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_sword_item' => $itemid]);
        $this->setUser($this->student);
        \block_playerhud\local\external_items::grant($biid, $itemid, (int) $this->student->id, 5, 'test', false);
        $token = security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'   => 'sword',
            'source' => 'hud',
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('consumablesourceunavailable', $result['exception']->errorcode);
    }

    /**
     * Tests that using a consumable type up to the configured limit is fine, but the
     * next use of the same type is rejected — the limit counts uses regardless of
     * source.
     *
     * @return void
     */
    public function test_consumable_limit_is_enforced_per_attempt(): void {
        $instance = $this->make_instance(['maxconsumables' => 1]);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $first = $this->call_buy_consumable($this->local_args($instance, $token));
        $this->assertFalse($first['error']);

        $second = $this->call_buy_consumable($this->local_args($instance, $token, [
            'coinsearnedsofar' => 200,
        ]));
        $this->assertTrue($second['error']);
        $this->assertSame('consumablelimitreached', $second['exception']->errorcode);
    }

    /**
     * Tests that an invalid consumable type is rejected.
     *
     * @return void
     */
    public function test_invalid_type_is_rejected(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, ['type' => 'bogus']));

        $this->assertTrue($result['error']);
        $this->assertSame('consumabletypeinvalid', $result['exception']->errorcode);
    }

    /**
     * Tests that an invalid funding source is rejected.
     *
     * @return void
     */
    public function test_invalid_source_is_rejected(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, ['source' => 'bogus']));

        $this->assertTrue($result['error']);
        $this->assertSame('consumablesourceinvalid', $result['exception']->errorcode);
    }

    /**
     * Tests that Quick Magic (magic) has no PlayerHUD source at all — it is
     * local-coin-only, so source=hud is always rejected for it.
     *
     * @return void
     */
    public function test_magic_has_no_hud_source(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'   => 'magic',
            'source' => 'hud',
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('consumablesourceunavailable', $result['exception']->errorcode);
    }

    /**
     * Tests that source=hud is rejected when the instance has no item configured for
     * that consumable type.
     *
     * @return void
     */
    public function test_hud_source_unconfigured_is_rejected(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'   => 'potion',
            'source' => 'hud',
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('consumablesourceunavailable', $result['exception']->errorcode);
    }

    /**
     * Tests a successful PlayerHUD-stock purchase: consumes 1 unit of the configured
     * item, never touches coins_spent, and records the use.
     *
     * @return void
     */
    public function test_hud_purchase_success(): void {
        global $DB;

        [$biid, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_potion_item' => $itemid]);
        $this->setUser($this->student);
        $this->grant_hud_item($biid, $itemid, (int) $this->student->id, 3);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'   => 'potion',
            'source' => 'hud',
        ]));

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame(2, hud_service::get_upgrade_level($biid, (int) $this->student->id, $itemid));
        $this->assertSame(0, (int) $DB->get_field('playerpuzzle_attempts', 'coins_spent', ['id' => $attemptid]));
        $this->assertSame(1, attempt_consumables::get_uses($attemptid, 'potion'));
    }

    /**
     * Tests that a PlayerHUD purchase is rejected when the student has no stock.
     *
     * @return void
     */
    public function test_hud_purchase_rejects_insufficient_stock(): void {
        [$biid, $itemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_sword_item' => $itemid]);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'   => 'sword',
            'source' => 'hud',
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('insufficienthudstock', $result['exception']->errorcode);
    }

    /**
     * Tests that an unknown/forged token is rejected with the dedicated exception.
     *
     * @return void
     */
    public function test_unknown_token_is_rejected(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_buy_consumable($this->local_args($instance, str_repeat('a', 64)));

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced — same
     * mechanism already verified for the other combat web services.
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
        buy_consumable::execute($instance->cmid, $token, 'potion', 'local', 100, 0);
    }

    /**
     * Creates an approved multichoice question with the given hint (or none), belonging to
     * the given instance.
     *
     * @param int $playerpuzzleid The instance the question belongs to.
     * @param string $hint Hint text; empty string stores null (no hint).
     * @return int The new question id.
     */
    private function make_question(int $playerpuzzleid, string $hint = ''): int {
        return questions_repository::add_question(
            $playerpuzzleid,
            'multichoice',
            'Question?',
            $hint,
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            0
        );
    }

    /**
     * Tests a successful hint purchase: debits coins, records the use, and returns the
     * question's own hint text, formatted.
     *
     * @return void
     */
    public function test_hint_purchase_success_reveals_text_and_debits_coins(): void {
        global $DB;

        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id, 'Think about it.');
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'       => 'hint',
            'questionid' => $questionid,
        ]));

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertStringContainsString('Think about it.', $result['data']['hinttext']);
        // Hint costs 5; newbalance = 100 - 5 = 95.
        $this->assertSame(95, $result['data']['newbalance']);
        $this->assertSame(5, (int) $DB->get_field('playerpuzzle_attempts', 'coins_spent', ['id' => $attemptid]));
        $this->assertSame(1, attempt_consumables::get_uses($attemptid, 'hint'));
    }

    /**
     * Tests that a question with no hint is rejected, without debiting anything.
     *
     * @return void
     */
    public function test_hint_purchase_rejects_a_question_without_a_hint(): void {
        global $DB;

        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'       => 'hint',
            'questionid' => $questionid,
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('hintnotavailable', $result['exception']->errorcode);
        $this->assertSame(0, (int) $DB->get_field('playerpuzzle_attempts', 'coins_spent', ['id' => $attemptid]));
    }

    /**
     * Tests instance isolation: a question id belonging to a different instance is rejected
     * even though it has a hint of its own.
     *
     * @return void
     */
    public function test_hint_purchase_rejects_a_question_from_another_instance(): void {
        $instance = $this->make_instance();
        $otherinstance = $this->make_instance();
        $questionid = $this->make_question((int) $otherinstance->id, 'Not yours.');
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'       => 'hint',
            'questionid' => $questionid,
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('hintnotavailable', $result['exception']->errorcode);
    }

    /**
     * Tests that Question Hint, like Quick Magic, has no PlayerHUD source — always
     * local-coin-only.
     *
     * @return void
     */
    public function test_hint_has_no_hud_source(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id, 'Think about it.');
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_buy_consumable($this->local_args($instance, $token, [
            'type'       => 'hint',
            'source'     => 'hud',
            'questionid' => $questionid,
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('consumablesourceunavailable', $result['exception']->errorcode);
    }
}
