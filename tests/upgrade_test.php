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
 * Tests for the upgrade steps.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for xmldb_playerpuzzle_upgrade().
 *
 * @covers ::xmldb_playerpuzzle_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/playerpuzzle/db/upgrade.php');
        require_once($CFG->libdir . '/upgradelib.php');
    }

    /**
     * Tests that the upgrade numbers each student's existing Campaigns: every attempt after a won one
     * belongs to the next Campaign, per student and instance, while Demos and Single Matches stay on
     * the first.
     *
     * @return void
     */
    public function test_upgrade_numbers_the_campaigns_already_played(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $campaign = $generator->create_instance(['course' => $course->id]);
        $single = $generator->create_instance(['course' => $course->id, 'gamemode' => 'single']);
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();

        $insert = function (\stdClass $instance, \stdClass $user, string $status, int $isdemo = 0) use ($DB): int {
            static $time = 1000;
            $time++;
            return $DB->insert_record('playerpuzzle_attempts', (object) [
                'playerpuzzleid' => $instance->id,
                'userid' => $user->id,
                'token' => bin2hex(random_bytes(32)),
                'status' => $status,
                'isdemo' => $isdemo,
                'timecreated' => $time,
            ]);
        };
        $expected = [];
        $expected[$insert($campaign, $alice, 'lost')] = 1;
        $expected[$insert($campaign, $alice, 'won')] = 1;
        $expected[$insert($campaign, $alice, 'won', 1)] = 1;
        $expected[$insert($campaign, $alice, 'lost')] = 2;
        $expected[$insert($campaign, $alice, 'won')] = 2;
        $expected[$insert($campaign, $alice, 'inprogress')] = 3;
        $expected[$insert($campaign, $bob, 'lost')] = 1;
        $expected[$insert($single, $alice, 'won')] = 1;
        $expected[$insert($single, $alice, 'won')] = 1;

        set_config('version', 2026110301, 'mod_playerpuzzle');
        xmldb_playerpuzzle_upgrade(2026110301);

        $this->assertSame(
            $expected,
            array_map('intval', $DB->get_records_menu('playerpuzzle_attempts', null, 'id', 'id, campaign'))
        );
    }
}
