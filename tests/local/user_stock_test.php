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
 * Unit tests for per-user loadout stock.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for user_stock.
 *
 * @covers \mod_playerpuzzle\local\user_stock
 */
final class user_stock_test extends \advanced_testcase {
    /**
     * Tests that a type never bought returns 0 quantity.
     *
     * @return void
     */
    public function test_get_quantity_defaults_to_zero(): void {
        $this->resetAfterTest();

        $this->assertSame(0, user_stock::get_quantity(5, 9, 'potion'));
    }

    /**
     * Tests that credit() creates the row on first credit and adds to it on later credits.
     *
     * @return void
     */
    public function test_credit_creates_and_adds(): void {
        $this->resetAfterTest();

        user_stock::credit(5, 9, 'potion', 3);
        $this->assertSame(3, user_stock::get_quantity(5, 9, 'potion'));

        user_stock::credit(5, 9, 'potion', 2);
        $this->assertSame(5, user_stock::get_quantity(5, 9, 'potion'));
    }

    /**
     * Tests that credit() is a no-op for a non-positive quantity.
     *
     * @return void
     */
    public function test_credit_ignores_non_positive_qty(): void {
        $this->resetAfterTest();

        user_stock::credit(5, 9, 'potion', 0);
        user_stock::credit(5, 9, 'potion', -1);

        $this->assertSame(0, user_stock::get_quantity(5, 9, 'potion'));
    }

    /**
     * Tests that debit() removes units when enough are owned.
     *
     * @return void
     */
    public function test_debit_succeeds_when_enough_owned(): void {
        $this->resetAfterTest();

        user_stock::credit(5, 9, 'sword', 3);

        $this->assertTrue(user_stock::debit(5, 9, 'sword', 1));
        $this->assertSame(2, user_stock::get_quantity(5, 9, 'sword'));
    }

    /**
     * Tests that debit() refuses and changes nothing when not enough is owned.
     *
     * @return void
     */
    public function test_debit_refuses_when_insufficient(): void {
        $this->resetAfterTest();

        user_stock::credit(5, 9, 'sword', 1);

        $this->assertFalse(user_stock::debit(5, 9, 'sword', 2));
        $this->assertSame(1, user_stock::get_quantity(5, 9, 'sword'));
    }

    /**
     * Tests that debit() refuses when the user owns nothing of that type at all.
     *
     * @return void
     */
    public function test_debit_refuses_when_never_owned(): void {
        $this->resetAfterTest();

        $this->assertFalse(user_stock::debit(5, 9, 'sword', 1));
    }

    /**
     * Tests that debit() is a no-op for a non-positive quantity.
     *
     * @return void
     */
    public function test_debit_ignores_non_positive_qty(): void {
        $this->resetAfterTest();

        user_stock::credit(5, 9, 'sword', 3);

        $this->assertFalse(user_stock::debit(5, 9, 'sword', 0));
        $this->assertSame(3, user_stock::get_quantity(5, 9, 'sword'));
    }

    /**
     * Tests that stock is isolated per user and per instance, never bleeding across either.
     *
     * @return void
     */
    public function test_stock_is_scoped_per_user_and_instance(): void {
        $this->resetAfterTest();

        user_stock::credit(5, 9, 'potion', 2);
        user_stock::credit(6, 9, 'potion', 7);
        user_stock::credit(5, 10, 'potion', 4);

        $this->assertSame(2, user_stock::get_quantity(5, 9, 'potion'));
        $this->assertSame(7, user_stock::get_quantity(6, 9, 'potion'));
        $this->assertSame(4, user_stock::get_quantity(5, 10, 'potion'));
    }

    /**
     * Tests that get_all() returns one key per attempt_consumables::TYPES, 0 for a type never
     * bought, the real quantity for one that was, and never bleeds in another user/instance's
     * stock.
     *
     * @return void
     */
    public function test_get_all_returns_all_types_scoped_correctly(): void {
        $this->resetAfterTest();

        user_stock::credit(5, 9, 'potion', 2);
        user_stock::credit(5, 9, 'hint', 5);
        user_stock::credit(6, 9, 'potion', 9);
        user_stock::credit(5, 10, 'potion', 3);

        $stock = user_stock::get_all(5, 9);

        $this->assertSame(
            ['potion' => 2, 'shield' => 0, 'magic' => 0, 'sword' => 0, 'hint' => 5],
            $stock
        );
    }

    /**
     * Tests that delete_for_instance() removes every row for that instance, keeping other
     * instances' stock untouched.
     *
     * @return void
     */
    public function test_delete_for_instance_removes_only_that_instance(): void {
        $this->resetAfterTest();

        user_stock::credit(5, 9, 'potion', 2);
        user_stock::credit(6, 9, 'sword', 1);
        user_stock::credit(5, 10, 'potion', 4);

        user_stock::delete_for_instance(9);

        $this->assertSame(0, user_stock::get_quantity(5, 9, 'potion'));
        $this->assertSame(0, user_stock::get_quantity(6, 9, 'sword'));
        $this->assertSame(4, user_stock::get_quantity(5, 10, 'potion'));
    }
}
