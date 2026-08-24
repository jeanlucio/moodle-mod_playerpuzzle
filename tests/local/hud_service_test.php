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
 * Unit tests for the PlayerHUD integration helper.
 *
 * Tests for get_block_instance_id/is_installed always run. Tests that touch item data
 * are skipped when block_playerhud is not installed on this site.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for hud_service.
 *
 * @covers \mod_playerpuzzle\local\hud_service
 */
final class hud_service_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Skips the current test when block_playerhud is not installed.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a block_instances record for block_playerhud in the given course context.
     *
     * @param \stdClass $course Course object.
     * @return int Block instance ID.
     */
    private function make_block_instance(\stdClass $course): int {
        global $DB;
        $ctx = \context_course::instance($course->id);
        return $DB->insert_record('block_instances', (object) [
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
    }

    /**
     * Inserts a block_playerhud_items record for the given block instance.
     *
     * @param int $blockinstanceid Block instance ID.
     * @param string $name Item display name.
     * @param bool $enabled Whether the item is enabled.
     * @return int Item ID.
     */
    private function make_item(int $blockinstanceid, string $name = 'Gold Coin', bool $enabled = true): int {
        global $DB;
        return $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $blockinstanceid,
            'name'            => $name,
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => $enabled ? 1 : 0,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * Tests that null is returned when no playerhud block exists in the course.
     *
     * @return void
     */
    public function test_get_block_instance_id_returns_null_when_absent(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->assertNull(hud_service::get_block_instance_id($course->id));
    }

    /**
     * Tests that the correct block instance ID is returned when one exists.
     *
     * @return void
     */
    public function test_get_block_instance_id_finds_block(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $this->assertSame($biid, hud_service::get_block_instance_id($course->id));
    }

    /**
     * Tests that a block instance in a different course is not returned — cross-course
     * isolation.
     *
     * @return void
     */
    public function test_get_block_instance_id_ignores_other_course(): void {
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $this->make_block_instance($course1);
        $this->assertNull(hud_service::get_block_instance_id($course2->id));
    }

    /**
     * Tests that is_installed reflects whether block_playerhud is present on this site.
     *
     * @return void
     */
    public function test_is_installed_matches_class_presence(): void {
        $this->assertSame(class_exists('\block_playerhud\local\external_items'), hud_service::is_installed());
    }

    /**
     * Tests that is_available_for_course is true once a block instance exists.
     *
     * @return void
     */
    public function test_is_available_for_course_true_with_block_instance(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $this->make_block_instance($course);
        $this->assertTrue(hud_service::is_available_for_course($course->id));
    }

    /**
     * Tests that is_available_for_course is false when the course has no block
     * instance, even though the block plugin itself is installed.
     *
     * @return void
     */
    public function test_is_available_for_course_false_without_block_instance(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse(hud_service::is_available_for_course($course->id));
    }

    /**
     * Tests that get_items_for_block returns only enabled items, sorted by name.
     *
     * @return void
     */
    public function test_get_items_for_block_returns_only_enabled_sorted(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $this->make_item($biid, 'Zinc Coin');
        $this->make_item($biid, 'Alpha Coin');
        $this->make_item($biid, 'Hidden Coin', false);

        $items = hud_service::get_items_for_block($biid);

        $this->assertCount(2, $items);
        $this->assertSame('Alpha Coin', $items[0]->name);
        $this->assertSame('Zinc Coin', $items[1]->name);
    }

    /**
     * Tests that get_item_name returns the formatted display name for an item that
     * belongs to the given block instance.
     *
     * @return void
     */
    public function test_get_item_name_returns_name_for_own_item(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 'Gold Coin');

        $this->assertSame('Gold Coin', hud_service::get_item_name($biid, $itemid));
    }

    /**
     * Tests that get_item_name returns an empty string for an item belonging to a
     * different block instance — the cross-course leak this delegation prevents.
     *
     * @return void
     */
    public function test_get_item_name_empty_for_other_instance_item(): void {
        $this->skip_if_no_playerhud();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $otherbiid = $this->make_block_instance($othercourse);
        $itemid = $this->make_item($otherbiid, 'Gold Coin');

        $this->assertSame('', hud_service::get_item_name($biid, $itemid));
    }

    /**
     * Tests that get_upgrade_level is 0 when the item id is unconfigured (0).
     *
     * @return void
     */
    public function test_get_upgrade_level_zero_when_unconfigured(): void {
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame(0, hud_service::get_upgrade_level(1, $user->id, 0));
    }

    /**
     * Tests that get_upgrade_level reflects the quantity credited via credit_coins,
     * proving the two methods read/write the same underlying balance.
     *
     * @return void
     */
    public function test_get_upgrade_level_reflects_credited_quantity(): void {
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 'Sword Level');

        hud_service::credit_coins($biid, $user->id, $itemid, 3);

        $this->assertSame(3, hud_service::get_upgrade_level($biid, $user->id, $itemid));
    }

    /**
     * Tests that credit_coins grants the exact quantity requested and returns true.
     *
     * @return void
     */
    public function test_credit_coins_success(): void {
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);

        $result = hud_service::credit_coins($biid, $user->id, $itemid, 5);

        $this->assertTrue($result);
        $this->assertSame(5, hud_service::get_upgrade_level($biid, $user->id, $itemid));
    }

    /**
     * Tests that credit_coins is a no-op returning false when the item is
     * unconfigured (id 0).
     *
     * @return void
     */
    public function test_credit_coins_false_when_item_unconfigured(): void {
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(hud_service::credit_coins(1, $user->id, 0, 5));
    }

    /**
     * Tests that credit_coins is a no-op returning false when the quantity is not
     * positive.
     *
     * @return void
     */
    public function test_credit_coins_false_when_qty_not_positive(): void {
        $this->skip_if_no_playerhud();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);

        $this->assertFalse(hud_service::credit_coins($biid, $user->id, $itemid, 0));
    }
}
