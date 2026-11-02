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
 * Tests for the seen_opponents service.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests that each user's met opponents are remembered once, and only for that user.
 *
 * @package    mod_playerpuzzle
 * @covers     \mod_playerpuzzle\local\seen_opponents
 */
final class seen_opponents_test extends \advanced_testcase {
    /**
     * Tests that the first meeting with an opponent is reported once and remembered.
     *
     * @return void
     */
    public function test_first_meeting_is_reported_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame([], seen_opponents::seen((int) $user->id));
        $this->assertTrue(seen_opponents::first_meeting('goblin', (int) $user->id));
        $this->assertFalse(seen_opponents::first_meeting('goblin', (int) $user->id));
        $this->assertTrue(seen_opponents::first_meeting('golem', (int) $user->id));
        $this->assertSame(['goblin', 'golem'], seen_opponents::seen((int) $user->id));
        $this->assertSame('goblin,golem', get_user_preferences(seen_opponents::PREFERENCE, null, $user->id));
    }

    /**
     * Tests that one user's met opponents never count for another.
     *
     * @return void
     */
    public function test_each_user_has_their_own_list(): void {
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        seen_opponents::first_meeting('slime', (int) $first->id);

        $this->assertTrue(seen_opponents::first_meeting('slime', (int) $second->id));
        $this->assertSame(['slime'], seen_opponents::seen((int) $first->id));
    }
}
