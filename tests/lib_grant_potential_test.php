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
 * Tests for the playerhud_grant_potential callback in lib.php.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

use mod_playerpuzzle\local\constants;

/**
 * Tests for playerpuzzle_playerhud_grant_potential(), which feeds block_playerhud's economy health panel with the XP
 * the win-grant items can give.
 *
 * @covers ::playerpuzzle_playerhud_grant_potential
 * @covers \mod_playerpuzzle\local\hud_service
 */
final class lib_grant_potential_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a PlayerHUD block in a course.
     *
     * @param \stdClass $course Course.
     * @return int Block instance ID.
     */
    private function make_block_instance(\stdClass $course): int {
        global $DB;

        return $DB->insert_record('block_instances', (object) [
            'blockname' => 'playerhud', 'parentcontextid' => \context_course::instance($course->id)->id,
            'showinsubcontexts' => 0, 'pagetypepattern' => 'course-view-*', 'subpagepattern' => null,
            'defaultregion' => 'side-pre', 'defaultweight' => 0,
            'configdata' => base64_encode(serialize(new \stdClass())), 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Inserts a PlayerHUD item.
     *
     * @param int $blockinstanceid Block instance ID.
     * @param int $xp XP of one unit.
     * @param int $enabled Whether the item is enabled.
     * @return int Item ID.
     */
    private function make_item(int $blockinstanceid, int $xp, int $enabled = 1): int {
        global $DB;

        return $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $blockinstanceid, 'name' => 'Gold Key', 'xp' => $xp, 'image' => '', 'description' => '',
            'enabled' => $enabled, 'secret' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Creates an activity.
     *
     * @param \stdClass $course Course.
     * @param array $record Fields.
     * @return \stdClass
     */
    private function make_activity(\stdClass $course, array $record): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance($record + ['course' => $course->id, 'minquestions' => 0]);
    }

    /**
     * Tests that nothing comes of an unknown block instance, of an activity with no win-grant item and of one whose
     * limit is unlimited, since the real grant withholds the XP then.
     *
     * @return void
     */
    public function test_nothing_without_a_grant_or_a_limit(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 50);

        $this->assertSame([], playerpuzzle_playerhud_grant_potential(999999));

        $this->make_activity($course, [
            'gamemode' => constants::GAMEMODE_CAMPAIGN, 'hud_win_grant_item' => 0, 'maxcampaigns' => 2,
        ]);
        $this->make_activity($course, [
            'gamemode' => constants::GAMEMODE_CAMPAIGN, 'hud_win_grant_item' => $itemid, 'maxcampaigns' => 0,
        ]);
        $this->make_activity($course, [
            'gamemode' => constants::GAMEMODE_SINGLE, 'hud_win_grant_item' => $itemid, 'max_single_matches' => 0,
        ]);

        $this->assertSame([], playerpuzzle_playerhud_grant_potential($biid));
    }

    /**
     * Tests that a Campaign is worth the item on every phase it wins, in each Campaign the activity allows: with
     * two levels of ten phases, two Campaigns and 50 XP an item, 2 x 10 x 2 x 50 XP.
     *
     * @return void
     */
    public function test_a_campaign_counts_every_phase_of_every_campaign(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 50);
        $this->make_activity($course, [
            'gamemode' => constants::GAMEMODE_CAMPAIGN, 'maxlevels' => 2, 'maxcampaigns' => 2, 'hud_win_grant_item' => $itemid,
            'hud_win_grant_qty' => 1,
        ]);

        $rows = playerpuzzle_playerhud_grant_potential($biid);

        $this->assertCount(1, $rows);
        $this->assertSame(50, $rows[0]['xp_each']);
        $this->assertSame(40, $rows[0]['total_uses']);
        $this->assertSame(2000, $rows[0]['xp_total']);
        $this->assertFalse($rows[0]['is_quest']);
        $this->assertFalse($rows[0]['infinite']);
    }

    /**
     * Tests that a Single Match is worth one grant for each match it allows, the quantity included.
     *
     * @return void
     */
    public function test_a_single_match_counts_the_matches_it_allows(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 100);
        $this->make_activity($course, [
            'gamemode' => constants::GAMEMODE_SINGLE, 'max_single_matches' => 3, 'hud_win_grant_item' => $itemid,
            'hud_win_grant_qty' => 2,
        ]);

        $rows = playerpuzzle_playerhud_grant_potential($biid);

        $this->assertSame([200, 3, 600], [$rows[0]['xp_each'], $rows[0]['total_uses'], $rows[0]['xp_total']]);
    }

    /**
     * Tests that an item of another course, a disabled one and one worth no XP add nothing, and that each activity
     * that does count gets its own row.
     *
     * @return void
     */
    public function test_only_items_that_really_grant_xp_count(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $other = $this->getDataGenerator()->create_course();
        $otheritem = $this->make_item($this->make_block_instance($other), 100);
        $disabled = $this->make_item($biid, 100, 0);
        $free = $this->make_item($biid, 0);
        $good = $this->make_item($biid, 10);
        foreach ([$otheritem, $disabled, $free, $good, $good] as $itemid) {
            $this->make_activity($course, [
                'gamemode' => constants::GAMEMODE_SINGLE, 'max_single_matches' => 1, 'hud_win_grant_item' => $itemid,
            ]);
        }

        $rows = playerpuzzle_playerhud_grant_potential($biid);

        $this->assertCount(2, $rows);
        $this->assertSame([10, 10], array_column($rows, 'xp_total'));
    }

    /**
     * Tests that block_playerhud finds the callback on its own and adds it to the XP of the game, through the real
     * discovery.
     *
     * @return void
     */
    public function test_the_block_adds_it_to_the_economy(): void {
        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid, 50);
        $this->make_activity($course, [
            'name' => 'Puzzle Campaign', 'gamemode' => constants::GAMEMODE_CAMPAIGN, 'maxlevels' => 1, 'maxcampaigns' => 1,
            'hud_win_grant_item' => $itemid,
        ]);
        \core_component::reset();
        \cache::make('core', 'plugin_functions')->purge();

        $totals = \block_playerhud\local\analytics::game_xp_totals($biid);

        $this->assertSame(500, $totals['total_xp']);
        $this->assertContains('Puzzle Campaign', array_column($totals['breakdown'], 'name'));
    }
}
