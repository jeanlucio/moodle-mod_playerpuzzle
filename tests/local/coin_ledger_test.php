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
 * Unit tests for the server-side coin ledger.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for coin_ledger.
 *
 * @covers \mod_playerpuzzle\local\coin_ledger
 */
final class coin_ledger_test extends \basic_testcase {
    /**
     * Builds a fresh attempt stub with the ledger columns at 0.
     *
     * @return \stdClass
     */
    private function fresh_attempt(): \stdClass {
        return (object) ['coins_earned' => 0, 'boss_coins_earned' => 0];
    }

    /**
     * Tests that sync() ratchets coins_earned/boss_coins_earned up to a reported value
     * under the ceiling.
     *
     * @return void
     */
    public function test_sync_ratchets_up_to_the_reported_value(): void {
        $attempt = $this->fresh_attempt();

        coin_ledger::sync($attempt, 30, 5, 100);

        $this->assertSame(30, $attempt->coins_earned);
        $this->assertSame(5, $attempt->boss_coins_earned);
    }

    /**
     * Tests that sync() clamps a reported value above the ceiling down to the ceiling —
     * the plausibility check actually taking effect.
     *
     * @return void
     */
    public function test_sync_clamps_above_the_ceiling(): void {
        $attempt = $this->fresh_attempt();

        coin_ledger::sync($attempt, 9999, 9999, 50);

        $this->assertSame(50, $attempt->coins_earned);
        $this->assertSame(50, $attempt->boss_coins_earned);
    }

    /**
     * Tests that sync() never decreases an already-stored value, even if a later call
     * reports a smaller (or negative) amount — the monotonic ratchet.
     *
     * @return void
     */
    public function test_sync_never_decreases_stored_value(): void {
        $attempt = $this->fresh_attempt();

        coin_ledger::sync($attempt, 40, 10, 100);
        coin_ledger::sync($attempt, 5, -20, 100);

        $this->assertSame(40, $attempt->coins_earned);
        $this->assertSame(10, $attempt->boss_coins_earned);
    }

    /**
     * Tests available() nets the boss's share against the player's earned total, never
     * going negative.
     *
     * @return void
     */
    public function test_available_nets_the_boss_share(): void {
        $attempt = (object) ['coins_earned' => 50, 'boss_coins_earned' => 20];
        $this->assertSame(30, coin_ledger::available($attempt));

        $attempt = (object) ['coins_earned' => 10, 'boss_coins_earned' => 30];
        $this->assertSame(0, coin_ledger::available($attempt));
    }

    /**
     * Tests reset() clears both ledger columns to 0.
     *
     * @return void
     */
    public function test_reset_clears_the_ledger(): void {
        $attempt = (object) ['coins_earned' => 50, 'boss_coins_earned' => 20];

        coin_ledger::reset($attempt);

        $this->assertSame(0, $attempt->coins_earned);
        $this->assertSame(0, $attempt->boss_coins_earned);
    }
}
