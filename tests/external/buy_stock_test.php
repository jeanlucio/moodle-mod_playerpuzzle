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

use context_course;
use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\hud_service;
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
            'name'            => 'Coin',
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
     * Grants $qty units of a PlayerHUD item to a user.
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
     * Tests a successful purchase: debits the configured coin item at the right price,
     * credits 1 unit of loadout stock, and returns the new owned quantity.
     *
     * @return void
     */
    public function test_buy_stock_success(): void {
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $this->setUser($this->student);
        $this->grant_hud_item($biid, $coinitemid, (int) $this->student->id, 20);

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'potion']);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame(1, $result['data']['newquantity']);
        $this->assertSame(1, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'potion'));
        // Potion costs 8; 20 - 8 = 12 left in the coin item.
        $this->assertSame(12, $result['data']['newcoinbalance']);
        $this->assertSame(12, hud_service::get_upgrade_level($biid, (int) $this->student->id, $coinitemid));
    }

    /**
     * Tests that buying the same type twice accumulates stock instead of overwriting it.
     *
     * @return void
     */
    public function test_buy_stock_accumulates_on_repeat_purchase(): void {
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $this->setUser($this->student);
        $this->grant_hud_item($biid, $coinitemid, (int) $this->student->id, 100);

        $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'hint']);
        $second = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'hint']);

        $this->assertFalse($second['error']);
        $this->assertSame(2, $second['data']['newquantity']);
    }

    /**
     * Tests that a purchase beyond the available coin balance is rejected without
     * crediting any stock or debiting the coin item.
     *
     * @return void
     */
    public function test_buy_stock_rejects_insufficient_coins(): void {
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $this->setUser($this->student);
        $this->grant_hud_item($biid, $coinitemid, (int) $this->student->id, 5);

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'magic']);

        $this->assertTrue($result['error']);
        $this->assertSame('insufficientcoins', $result['exception']->errorcode);
        $this->assertSame(0, user_stock::get_quantity((int) $this->student->id, (int) $instance->id, 'magic'));
        $this->assertSame(5, hud_service::get_upgrade_level($biid, (int) $this->student->id, $coinitemid));
    }

    /**
     * Tests that a purchase is rejected when the instance has no coin item configured at
     * all — there is no funding source to spend from.
     *
     * @return void
     */
    public function test_buy_stock_rejects_when_hud_economy_unconfigured(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_buy_stock(['cmid' => $instance->cmid, 'type' => 'potion']);

        $this->assertTrue($result['error']);
        $this->assertSame('hudeconomyunavailable', $result['exception']->errorcode);
    }

    /**
     * Tests that an invalid consumable type is rejected.
     *
     * @return void
     */
    public function test_buy_stock_rejects_invalid_type(): void {
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
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
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $otherinstance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherstudent->id, $this->course->id, 'student');
        $this->grant_hud_item($biid, $coinitemid, (int) $this->student->id, 100);

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
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $modcontext = context_module::instance($instance->cmid);

        $prohibitedrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/playerpuzzle:view', CAP_PROHIBIT, $prohibitedrole, $modcontext);
        role_assign($prohibitedrole, $this->student->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->student);

        $this->expectException(\core\exception\require_login_exception::class);
        buy_stock::execute($instance->cmid, 'potion');
    }
}
