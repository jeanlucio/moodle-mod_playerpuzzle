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
 * External function tests for buy_stock.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\user_stock;

/**
 * Tests for the mod_playerpuzzle_buy_stock web service.
 *
 * @covers \mod_playerpuzzle\external\buy_stock
 */
final class buy_stock_test extends \advanced_testcase {
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
     * Calls the mod_playerpuzzle_buy_stock web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_buy_stock(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_buy_stock', $args);
    }

    /**
     * Tests a successful purchase: debits PuzzleCoin at the right price, credits 1 unit of
     * loadout stock, and returns the new owned quantity. No PlayerHUD involved at all —
     * PuzzleCoin is PlayerPuzzle's own balance, credited directly for this test's setup.
     *
     * @return void
     */
    public function test_buy_stock_success(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 20);

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'potion']);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame(1, $result['data']['newquantity']);
        $this->assertSame(1, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'potion'));
        // Potion costs 8; 20 - 8 = 12 left in PuzzleCoin.
        $this->assertSame(12, $result['data']['newcoinbalance']);
        $this->assertSame(
            12,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
    }

    /**
     * Tests that a purchase works with no PlayerHUD installed/configured at all — the
     * regression this correction closes.
     *
     * @return void
     */
    public function test_buy_stock_works_without_playerhud(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 8);

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'potion']);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame(0, $result['data']['newcoinbalance']);
    }

    /**
     * Tests that buying the same type twice accumulates stock instead of overwriting it.
     *
     * @return void
     */
    public function test_buy_stock_accumulates_on_repeat_purchase(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 100);

        $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'hint']);
        $second = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'hint']);

        $this->assertFalse($second['error']);
        $this->assertSame(2, $second['data']['newquantity']);
    }

    /**
     * Tests that a purchase beyond the available PuzzleCoin balance is rejected without
     * crediting any stock or debiting anything.
     *
     * @return void
     */
    public function test_buy_stock_rejects_insufficient_coins(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 5);

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'magic']);

        $this->assertTrue($result['error']);
        $this->assertSame('insufficientcoins', $result['exception']->errorcode);
        $this->assertSame(0, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'magic'));
        $this->assertSame(
            5,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
    }

    /**
     * Tests that a purchase with no PuzzleCoin at all (never played, never transferred) is
     * rejected the same way as any other insufficient balance.
     *
     * @return void
     */
    public function test_buy_stock_rejects_when_no_puzzlecoin_ever_earned(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'potion']);

        $this->assertTrue($result['error']);
        $this->assertSame('insufficientcoins', $result['exception']->errorcode);
    }

    /**
     * Tests that an invalid consumable type is rejected.
     *
     * @return void
     */
    public function test_buy_stock_rejects_invalid_type(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'bogus']);

        $this->assertTrue($result['error']);
        $this->assertSame('consumabletypeinvalid', $result['exception']->errorcode);
    }

    /**
     * Tests that stock is isolated per instance and per user — buying on one instance
     * never credits another, and one student's purchase never credits another's stock.
     *
     * @return void
     */
    public function test_buy_stock_is_isolated_by_instance_and_user(): void {
        $instance = $this->make_instance();
        $otherinstance = $this->make_instance();
        $otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherstudent->id, $this->course->id, 'student');
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 100);

        $this->setUser($this->student);
        $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'sword']);

        $this->assertSame(1, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'sword'));
        $this->assertSame(0, user_stock::get_quantity((int) $this->student->id, (int) $otherinstance->id, 'sword'));
        $this->assertSame(0, user_stock::get_quantity((int) $otherstudent->id, (int) $instance->id, 'sword'));
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

        $this->expectException(\core\exception\require_login_exception::class);
        buy_stock::execute($instance->cmid, 'potion');
    }

    /**
     * Tests that a purchase takes the same stock lock the match operations take (see
     * security::with_locked_attempt_and_stock()), and nothing else — a Lobby purchase has no
     * attempt to lock.
     *
     * @return void
     */
    public function test_buy_stock_takes_the_stock_lock(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/recording_lock_factory.php');

        $instance = $this->make_instance();
        $this->setUser($this->student);
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 20);
        $stock = 'stock_' . $this->student->id . '_' . $instance->id;
        \mod_playerpuzzle_recording_lock_factory::install();

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'potion']);

        $this->assertFalse($result['error']);
        $this->assertSame(
            ["acquire {$stock}", "release {$stock}"],
            \mod_playerpuzzle_recording_lock_factory::events_for('mod_playerpuzzle')
        );
    }
}
