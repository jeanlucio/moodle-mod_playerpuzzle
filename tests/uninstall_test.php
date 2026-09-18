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
 * Tests for the mod_playerpuzzle pre-uninstallation hook.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for xmldb_playerpuzzle_uninstall(). Every table in db/install.xml is dropped
 * automatically by core, so the only thing worth exercising here is the one piece of
 * cleanup core does not do for us: user_preferences rows.
 *
 * @covers ::xmldb_playerpuzzle_uninstall
 */
final class uninstall_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/db/uninstall.php');
    }

    /**
     * Tests that the uninstall hook deletes only mod_playerpuzzle-prefixed preferences,
     * leaving unrelated preferences (including those of other plugins) untouched.
     *
     * @return void
     */
    public function test_uninstall_deletes_only_own_prefixed_preferences(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        set_user_preference('mod_playerpuzzle_music', 0, $user);
        set_user_preference('mod_playerpuzzle_sfx', 0, $user);
        set_user_preference('mod_playerland_introseen', 1, $user);
        set_user_preference('unrelated_pref', 'keep', $user);

        $result = xmldb_playerpuzzle_uninstall();

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('user_preferences', [
            'userid' => $user->id,
            'name' => 'mod_playerpuzzle_music',
        ]));
        $this->assertFalse($DB->record_exists('user_preferences', [
            'userid' => $user->id,
            'name' => 'mod_playerpuzzle_sfx',
        ]));
        $this->assertTrue($DB->record_exists('user_preferences', [
            'userid' => $user->id,
            'name' => 'mod_playerland_introseen',
        ]));
        $this->assertTrue($DB->record_exists('user_preferences', [
            'userid' => $user->id,
            'name' => 'unrelated_pref',
        ]));
    }

    /**
     * Tests that running the hook with no matching preferences at all is a harmless
     * no-op.
     *
     * @return void
     */
    public function test_uninstall_with_no_matching_preferences_is_a_noop(): void {
        $this->assertTrue(xmldb_playerpuzzle_uninstall());
    }
}
