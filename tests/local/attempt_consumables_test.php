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
 * Unit tests for per-attempt consumable use counting.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for attempt_consumables.
 *
 * @covers \mod_playerpuzzle\local\attempt_consumables
 */
final class attempt_consumables_test extends \advanced_testcase {
    /**
     * Tests that a type never used returns 0 uses.
     *
     * @return void
     */
    public function test_get_uses_defaults_to_zero(): void {
        $this->resetAfterTest();

        $this->assertSame(0, attempt_consumables::get_uses(5, 'potion'));
    }

    /**
     * Tests that recording a use creates the row on first use and increments it on
     * later uses, tracking each type separately for the same attempt.
     *
     * @return void
     */
    public function test_record_use_creates_and_increments(): void {
        $this->resetAfterTest();

        attempt_consumables::record_use(5, 'potion');
        $this->assertSame(1, attempt_consumables::get_uses(5, 'potion'));

        attempt_consumables::record_use(5, 'potion');
        attempt_consumables::record_use(5, 'potion');
        $this->assertSame(3, attempt_consumables::get_uses(5, 'potion'));

        // A different type on the same attempt is tracked independently.
        attempt_consumables::record_use(5, 'sword');
        $this->assertSame(1, attempt_consumables::get_uses(5, 'sword'));
        $this->assertSame(3, attempt_consumables::get_uses(5, 'potion'));
    }

    /**
     * Tests that uses never bleed between different attempts.
     *
     * @return void
     */
    public function test_uses_are_scoped_per_attempt(): void {
        $this->resetAfterTest();

        attempt_consumables::record_use(5, 'shield');
        attempt_consumables::record_use(9, 'shield');
        attempt_consumables::record_use(9, 'shield');

        $this->assertSame(1, attempt_consumables::get_uses(5, 'shield'));
        $this->assertSame(2, attempt_consumables::get_uses(9, 'shield'));
    }
}
