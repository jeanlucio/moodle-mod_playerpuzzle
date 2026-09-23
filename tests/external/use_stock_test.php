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
 * External function tests for use_stock.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\attempt_consumables;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\questions_repository;
use mod_playerpuzzle\local\user_stock;

/**
 * Tests for the mod_playerpuzzle_use_stock web service.
 *
 * @covers \mod_playerpuzzle\external\use_stock
 */
final class use_stock_test extends \advanced_testcase {
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
     * Creates a playerpuzzle instance.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $record = array_merge(['course' => $this->course->id], $overrides);

        return $generator->create_instance($record);
    }

    /**
     * Calls the mod_playerpuzzle_use_stock web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_use_stock(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_use_stock', $args);
    }

    /**
     * Tests a successful use: debits 1 unit of owned stock, records the use, and returns
     * the new owned quantity.
     *
     * @return void
     */
    public function test_use_success(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'potion', 3);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = $this->attempt_id_for($token);

        $result = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'potion']);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame(2, $result['data']['newquantity']);
        $this->assertSame(2, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'potion'));
        $this->assertSame(1, attempt_consumables::get_uses($attemptid, 'potion'));
    }

    /**
     * Tests that using a type the student owns none of is rejected, recording nothing.
     *
     * @return void
     */
    public function test_use_rejects_insufficient_stock(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = $this->attempt_id_for($token);

        $result = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'potion']);

        $this->assertTrue($result['error']);
        $this->assertSame('insufficientstock', $result['exception']->errorcode);
        $this->assertSame(0, attempt_consumables::get_uses($attemptid, 'potion'));
    }

    /**
     * Tests that a type with a fixed per-phase limit (Shield: 1) is rejected once that
     * limit is reached, even with plenty of stock left owned.
     *
     * @return void
     */
    public function test_use_rejects_when_phase_limit_reached(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'shield', 5);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $first = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'shield']);
        $this->assertFalse($first['error']);

        $second = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'shield']);
        $this->assertTrue($second['error']);
        $this->assertSame('consumablelimitreached', $second['exception']->errorcode);
        // The rejected second attempt never debited stock: still 4 (5 - 1 from the first).
        $this->assertSame(4, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'shield'));
    }

    /**
     * Tests that Potion/Sword (limit 3) allow exactly three uses per phase before the
     * fourth is rejected.
     *
     * @return void
     */
    public function test_use_allows_up_to_the_fixed_limit_then_rejects(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'sword', 10);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        for ($i = 0; $i < 3; $i++) {
            $result = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'sword']);
            $this->assertFalse($result['error']);
        }

        $fourth = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'sword']);
        $this->assertTrue($fourth['error']);
        $this->assertSame('consumablelimitreached', $fourth['exception']->errorcode);
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
     * Tests a successful hint use: debits 1 unit of hint stock, records the use, and
     * returns the question's own hint text, formatted. Hint has no fixed phase limit, so
     * many uses within the same phase are all allowed, bounded only by owned stock.
     *
     * @return void
     */
    public function test_use_hint_reveals_text_and_debits_stock(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id, 'Think about it.');
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'hint', 2);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_use_stock([
            'cmid'       => $instance->cmid,
            'token'      => $token,
            'type'       => 'hint',
            'questionid' => $questionid,
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertStringContainsString('Think about it.', $result['data']['hinttext']);
        $this->assertSame(1, $result['data']['newquantity']);
    }

    /**
     * Tests that a question with no hint is rejected, without debiting anything.
     *
     * @return void
     */
    public function test_use_hint_rejects_a_question_without_a_hint(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'hint', 2);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_use_stock([
            'cmid'       => $instance->cmid,
            'token'      => $token,
            'type'       => 'hint',
            'questionid' => $questionid,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('hintnotavailable', $result['exception']->errorcode);
        $this->assertSame(2, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'hint'));
    }

    /**
     * Tests instance isolation: a question id belonging to a different instance is rejected
     * even though it has a hint of its own.
     *
     * @return void
     */
    public function test_use_hint_rejects_a_question_from_another_instance(): void {
        $instance = $this->make_instance();
        $otherinstance = $this->make_instance();
        $questionid = $this->make_question((int) $otherinstance->id, 'Not yours.');
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_use_stock([
            'cmid'       => $instance->cmid,
            'token'      => $token,
            'type'       => 'hint',
            'questionid' => $questionid,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('hintnotavailable', $result['exception']->errorcode);
    }

    /**
     * Tests that a Demo attempt can use a consumable for free, without touching the real
     * stock table at all — playerpuzzle_user_stock is keyed by user+instance, not by
     * attempt, so debiting it during a disposable practice fight would eat into the same
     * balance a real Campaign attempt paid real coins for.
     *
     * @return void
     */
    public function test_demo_attempt_uses_for_free_without_touching_real_stock(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'potion', 5);
        $token = security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );

        $result = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'potion']);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        // Real stock is untouched — still 5, not debited by the Demo use.
        $this->assertSame(5, $result['data']['newquantity']);
        $this->assertSame(5, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'potion'));
    }

    /**
     * Tests that a Demo attempt can use a consumable it owns none of at all — Demo grants
     * the mechanic for free, it is never gated by real ownership.
     *
     * @return void
     */
    public function test_demo_attempt_uses_type_never_owned(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );

        $result = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'potion']);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame(0, $result['data']['newquantity']);
    }

    /**
     * Tests that a Demo attempt still respects the fixed per-phase limit — free does not
     * mean unbounded spam within the same phase.
     *
     * @return void
     */
    public function test_demo_attempt_still_respects_phase_limit(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );

        $first = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'shield']);
        $this->assertFalse($first['error']);

        $second = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'shield']);
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

        $result = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'bogus']);

        $this->assertTrue($result['error']);
        $this->assertSame('consumabletypeinvalid', $result['exception']->errorcode);
    }

    /**
     * Tests that an unknown/forged token is rejected with the dedicated exception.
     *
     * @return void
     */
    public function test_unknown_token_is_rejected(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_use_stock([
            'cmid'  => $instance->cmid,
            'token' => str_repeat('a', 64),
            'type'  => 'potion',
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced.
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
        use_stock::execute($instance->cmid, $token, 'potion');
    }

    /**
     * Looks up the attempt id for a freshly generated token.
     *
     * @param string $token The attempt token.
     * @return int
     */
    private function attempt_id_for(string $token): int {
        global $DB;
        return (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token], MUST_EXIST);
    }

    /**
     * Tests that using a consumable debits the stock under the stock lock a Lobby purchase
     * takes, nested inside the attempt lock — a purchase of the same type racing the use
     * could otherwise overwrite the debit and hand the unit back.
     *
     * @return void
     */
    public function test_use_debits_under_the_attempt_and_stock_locks(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/recording_lock_factory.php');

        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'potion', 3);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = $this->attempt_id_for($token);
        $stock = 'stock_' . $this->student->id . '_' . $instance->id;
        \mod_playerpuzzle_recording_lock_factory::install();

        $result = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'potion']);

        $this->assertFalse($result['error']);
        $this->assertSame([
            "acquire attempt_{$attemptid}",
            "acquire {$stock}",
            "release {$stock}",
            "release attempt_{$attemptid}",
        ], \mod_playerpuzzle_recording_lock_factory::events_for('mod_playerpuzzle'));
    }

    /**
     * Tests that when the stock lock cannot be had, the use fails without debiting the stock
     * or counting towards the phase limit.
     *
     * @return void
     */
    public function test_use_with_a_busy_stock_lock_changes_nothing(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/recording_lock_factory.php');

        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, 'potion', 3);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = $this->attempt_id_for($token);
        $stock = 'stock_' . $this->student->id . '_' . $instance->id;
        \mod_playerpuzzle_recording_lock_factory::install(["mod_playerpuzzle/{$stock}"]);

        $result = $this->call_use_stock(['cmid' => $instance->cmid, 'token' => $token, 'type' => 'potion']);

        $this->assertTrue($result['error']);
        $this->assertSame('stockbusy', $result['exception']->errorcode);
        $this->assertSame(3, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'potion'));
        $this->assertSame(0, attempt_consumables::get_uses($attemptid, 'potion'));
    }
}
