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
 * External function tests for transfer_hud_coins.
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
 * Tests for the mod_playerpuzzle_transfer_hud_coins web service.
 *
 * @covers \mod_playerpuzzle\external\transfer_hud_coins
 */
final class transfer_hud_coins_test extends \advanced_testcase {
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
            'name'            => 'PlayerCoin',
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
     * Calls the mod_playerpuzzle_transfer_hud_coins web service through the real dispatch
     * path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_transfer(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_transfer_hud_coins', $args);
    }

    /**
     * Tests a successful transfer: debits the PlayerHUD coin item and credits the same
     * amount into PuzzleCoin.
     *
     * @return void
     */
    public function test_transfer_success(): void {
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $this->setUser($this->student);
        $this->grant_hud_item($biid, $coinitemid, (int) $this->student->id, 50);

        $result = $this->call_transfer(['cmid' => $instance->cmid, 'amount' => 30]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertSame(20, $result['data']['newhudbalance']);
        $this->assertSame(30, $result['data']['newpuzzlecoinbalance']);
        $this->assertSame(20, hud_service::get_upgrade_level($biid, (int) $this->student->id, $coinitemid));
        $this->assertSame(
            30,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
    }

    /**
     * Tests that transferring on top of an existing PuzzleCoin balance (earned by playing)
     * adds to it rather than overwriting it.
     *
     * @return void
     */
    public function test_transfer_adds_to_existing_puzzlecoin_balance(): void {
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $this->setUser($this->student);
        $this->grant_hud_item($biid, $coinitemid, (int) $this->student->id, 50);
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 10);

        $result = $this->call_transfer(['cmid' => $instance->cmid, 'amount' => 5]);

        $this->assertFalse($result['error']);
        $this->assertSame(15, $result['data']['newpuzzlecoinbalance']);
    }

    /**
     * Tests that a transfer beyond the PlayerHUD balance is rejected, crediting nothing.
     *
     * @return void
     */
    public function test_transfer_rejects_insufficient_hud_balance(): void {
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $this->setUser($this->student);
        $this->grant_hud_item($biid, $coinitemid, (int) $this->student->id, 5);

        $result = $this->call_transfer(['cmid' => $instance->cmid, 'amount' => 10]);

        $this->assertTrue($result['error']);
        $this->assertSame('insufficienthudstock', $result['exception']->errorcode);
        $this->assertSame(5, hud_service::get_upgrade_level($biid, (int) $this->student->id, $coinitemid));
        $this->assertSame(
            0,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
    }

    /**
     * Tests that a transfer is rejected when the instance has no PlayerHUD coin item
     * configured at all.
     *
     * @return void
     */
    public function test_transfer_rejects_when_hud_economy_unconfigured(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_transfer(['cmid' => $instance->cmid, 'amount' => 10]);

        $this->assertTrue($result['error']);
        $this->assertSame('hudeconomyunavailable', $result['exception']->errorcode);
    }

    /**
     * Tests that a zero or negative amount is rejected.
     *
     * @return void
     */
    public function test_transfer_rejects_non_positive_amount(): void {
        [, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $this->setUser($this->student);

        $result = $this->call_transfer(['cmid' => $instance->cmid, 'amount' => 0]);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidtransferamount', $result['exception']->errorcode);
    }

    /**
     * Tests that a transfer is isolated per instance and per user.
     *
     * @return void
     */
    public function test_transfer_is_isolated_by_instance_and_user(): void {
        [$biid, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $otherinstance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherstudent->id, $this->course->id, 'student');
        $this->grant_hud_item($biid, $coinitemid, (int) $this->student->id, 50);

        $this->setUser($this->student);
        $this->call_transfer(['cmid' => $instance->cmid, 'amount' => 20]);

        $this->assertSame(
            20,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
        $this->assertSame(
            0,
            user_stock::get_quantity((int) $this->student->id, (int) $otherinstance->id, user_stock::CURRENCY_TYPE)
        );
        $this->assertSame(
            0,
            user_stock::get_quantity((int) $otherstudent->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced.
     *
     * @return void
     */
    public function test_requires_view_capability(): void {
        [, $coinitemid] = $this->make_hud_item();
        $instance = $this->make_instance(['hud_coin_item' => $coinitemid]);
        $modcontext = context_module::instance($instance->cmid);

        $prohibitedrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/playerpuzzle:view', CAP_PROHIBIT, $prohibitedrole, $modcontext);
        role_assign($prohibitedrole, $this->student->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->student);

        $this->expectException(\core\exception\require_login_exception::class);
        transfer_hud_coins::execute($instance->cmid, 10);
    }

    /**
     * Opens the course to guest access and logs in as the guest account, as a visitor to
     * such a course would be.
     *
     * @return void
     */
    private function log_in_as_course_guest(): void {
        global $DB;

        $plugin = enrol_get_plugin('guest');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'guest']);
        if ($instance) {
            $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
        } else {
            $plugin->add_instance($this->course, ['status' => ENROL_INSTANCE_ENABLED]);
        }
        $this->setGuestUser();
    }

    /**
     * Tests that the guest account cannot transfer PlayerHUD coins into PuzzleCoin, for the
     * same reason it cannot buy.
     *
     * @return void
     */
    public function test_transfer_rejects_the_guest_account(): void {
        global $DB;

        // No PlayerHUD item needed: the guest is turned away before the transfer looks at one.
        $instance = $this->make_instance();
        $this->log_in_as_course_guest();

        $result = $this->call_transfer(['cmid' => $instance->cmid, 'amount' => 1]);

        $this->assertTrue($result['error']);
        $this->assertSame('guestdemoonly', $result['exception']->errorcode);
        $this->assertFalse($DB->record_exists('playerpuzzle_user_stock', ['userid' => guest_user()->id]));
    }
}
