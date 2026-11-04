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
 * Tests for the hero a user chose last.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for last_hero.
 *
 * @covers \mod_playerpuzzle\local\last_hero
 */
final class last_hero_test extends \advanced_testcase {
    /**
     * Tests that nothing is remembered at first, that a hero chosen is remembered for that user only, and
     * that choosing another replaces it.
     *
     * @return void
     */
    public function test_a_hero_is_remembered_for_its_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->assertSame('', last_hero::get((int) $user->id));

        last_hero::set((int) $user->id, 'magem');
        $this->assertSame('magem', last_hero::get((int) $user->id));
        $this->assertSame('', last_hero::get((int) $other->id));

        last_hero::set((int) $user->id, 'warriorf');
        $this->assertSame('warriorf', last_hero::get((int) $user->id));
    }

    /**
     * Tests that a name outside the catalog is never stored, and that a stored name that is no longer a hero
     * is read as none.
     *
     * @return void
     */
    public function test_a_name_outside_the_catalog_is_not_a_hero(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        last_hero::set((int) $user->id, 'magem');

        last_hero::set((int) $user->id, 'dragon');
        $this->assertSame('magem', last_hero::get((int) $user->id));

        set_user_preference(last_hero::PREFERENCE, 'retired', (int) $user->id);
        $this->assertSame('', last_hero::get((int) $user->id));
    }
}
