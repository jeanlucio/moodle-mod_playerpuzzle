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
 * Unit tests for the combat state snapshot helper.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for combat_state.
 *
 * @covers \mod_playerpuzzle\local\combat_state
 */
final class combat_state_test extends \advanced_testcase {
    /**
     * Tests that a board grid of exactly BOARD_CELLS entries is valid.
     *
     * @return void
     */
    public function test_is_valid_board_accepts_the_right_size(): void {
        $this->assertTrue(combat_state::is_valid_board(array_fill(0, combat_state::BOARD_CELLS, 0)));
    }

    /**
     * Tests that a board grid of the wrong size, or not an array at all, is invalid.
     *
     * @return void
     */
    public function test_is_valid_board_rejects_the_wrong_shape(): void {
        $this->assertFalse(combat_state::is_valid_board(array_fill(0, combat_state::BOARD_CELLS - 1, 0)));
        $this->assertFalse(combat_state::is_valid_board(array_fill(0, combat_state::BOARD_CELLS + 1, 0)));
        $this->assertFalse(combat_state::is_valid_board('not-an-array'));
        $this->assertFalse(combat_state::is_valid_board(null));
    }

    /**
     * Tests that encode()/decode() round-trip the board grid and the meters untouched.
     *
     * @return void
     */
    public function test_encode_and_decode_round_trip(): void {
        $boardgrid = array_fill(0, combat_state::BOARD_CELLS, 2);
        $meters = ['currentplayerhp' => 120, 'currentturn' => 'boss'];

        $decoded = combat_state::decode(combat_state::encode($boardgrid, $meters));

        $this->assertSame($boardgrid, $decoded['boardgrid']);
        $this->assertSame(120, $decoded['currentplayerhp']);
        $this->assertSame('boss', $decoded['currentturn']);
    }

    /**
     * Tests that decode() returns null for a missing or empty snapshot, without erroring —
     * the normal case for a phase that never had one checkpointed yet.
     *
     * @return void
     */
    public function test_decode_returns_null_when_there_is_no_snapshot(): void {
        $this->assertNull(combat_state::decode(null));
        $this->assertNull(combat_state::decode(''));
    }

    /**
     * Tests that decode() degrades to null on malformed JSON instead of throwing — a broken
     * snapshot must never block the page from loading.
     *
     * @return void
     */
    public function test_decode_returns_null_on_malformed_json(): void {
        $this->assertNull(combat_state::decode('{not valid json'));
        $this->assertNull(combat_state::decode('"just a string"'));
    }
}
