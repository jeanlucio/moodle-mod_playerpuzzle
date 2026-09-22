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
 * Unit tests for the move-log encode/decode/validate helper.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for move_log.
 *
 * @covers \mod_playerpuzzle\local\move_log
 */
final class move_log_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that a normal, in-bounds move log is valid.
     *
     * @return void
     */
    public function test_is_valid_accepts_a_normal_move_log(): void {
        $movelog = [
            ['r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1],
            ['r1' => 7, 'c1' => 7, 'r2' => 6, 'c2' => 7],
        ];

        $this->assertTrue(move_log::is_valid($movelog));
    }

    /**
     * Tests that an empty move log is valid — a checkpoint with nothing new to report.
     *
     * @return void
     */
    public function test_is_valid_accepts_an_empty_move_log(): void {
        $this->assertTrue(move_log::is_valid([]));
    }

    /**
     * Tests that a move log over the per-checkpoint size cap is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_over_the_size_cap(): void {
        $movelog = array_fill(0, move_log::MAX_MOVES_PER_CHECKPOINT + 1, ['r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]);

        $this->assertFalse(move_log::is_valid($movelog));
    }

    /**
     * Tests that a move log exactly at the size cap is still accepted — the cap rejects
     * what is over it, not what is at it.
     *
     * @return void
     */
    public function test_is_valid_accepts_exactly_the_size_cap(): void {
        $movelog = array_fill(0, move_log::MAX_MOVES_PER_CHECKPOINT, ['r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]);

        $this->assertTrue(move_log::is_valid($movelog));
    }

    /**
     * Tests that a negative coordinate is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_a_negative_coordinate(): void {
        $this->assertFalse(move_log::is_valid([['r1' => -1, 'c1' => 0, 'r2' => 0, 'c2' => 1]]));
    }

    /**
     * Tests that a coordinate at or beyond the board's own dimension is rejected — the
     * board is 8x8, so valid rows/columns are 0-7.
     *
     * @return void
     */
    public function test_is_valid_rejects_a_coordinate_at_or_past_the_board_edge(): void {
        $this->assertFalse(move_log::is_valid([['r1' => 0, 'c1' => 0, 'r2' => 8, 'c2' => 0]]));
        $this->assertTrue(move_log::is_valid([['r1' => 0, 'c1' => 0, 'r2' => 7, 'c2' => 0]]));
    }

    /**
     * Tests that encode()/decode() round-trip a move log verbatim.
     *
     * @return void
     */
    public function test_encode_decode_round_trip(): void {
        $movelog = [['r1' => 2, 'c1' => 3, 'r2' => 2, 'c2' => 4]];

        $this->assertSame($movelog, move_log::decode(move_log::encode($movelog)));
    }

    /**
     * Tests that encoding an empty move log stores null rather than an empty JSON array —
     * "nothing pending" and "an empty list" should read the same way from the column.
     *
     * @return void
     */
    public function test_encode_empty_returns_null(): void {
        $this->assertNull(move_log::encode([]));
    }

    /**
     * Tests that decode() treats null/empty/malformed input as an empty list rather than
     * erroring — a broken or absent log should never block anything.
     *
     * @return void
     */
    public function test_decode_handles_missing_or_broken_input(): void {
        $this->assertSame([], move_log::decode(null));
        $this->assertSame([], move_log::decode(''));
        $this->assertSame([], move_log::decode('not json'));
    }
}
