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
 * Unit tests for the combat-event log encode/decode/validate/append helper.
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
     * Tests that a normal, in-bounds batch of move events is valid.
     *
     * @return void
     */
    public function test_is_valid_accepts_a_normal_batch_of_moves(): void {
        $events = [
            ['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1],
            ['type' => 'move', 'r1' => 7, 'c1' => 7, 'r2' => 6, 'c2' => 7],
        ];

        $this->assertTrue(move_log::is_valid($events));
    }

    /**
     * Tests that a question event is valid for both sides.
     *
     * @return void
     */
    public function test_is_valid_accepts_a_question_event_for_either_side(): void {
        $this->assertTrue(move_log::is_valid([['type' => 'question', 'side' => 'player', 'outcome' => 'answered']]));
        $this->assertTrue(move_log::is_valid([['type' => 'question', 'side' => 'boss', 'outcome' => 'failed']]));
    }

    /**
     * Tests that a batch mixing move and question events, in order, is valid — the whole
     * point of sharing one log is to preserve their relative turn order.
     *
     * @return void
     */
    public function test_is_valid_accepts_a_mixed_batch(): void {
        $events = [
            ['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1],
            ['type' => 'question', 'side' => 'player', 'outcome' => 'answered'],
            ['type' => 'move', 'r1' => 3, 'c1' => 3, 'r2' => 3, 'c2' => 4],
        ];

        $this->assertTrue(move_log::is_valid($events));
    }

    /**
     * Tests that an empty batch is valid — a checkpoint with nothing new to report.
     *
     * @return void
     */
    public function test_is_valid_accepts_an_empty_batch(): void {
        $this->assertTrue(move_log::is_valid([]));
    }

    /**
     * Tests that an event with an unknown type is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_an_unknown_type(): void {
        $this->assertFalse(move_log::is_valid([['type' => 'teleport', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]]));
    }

    /**
     * Tests that an event missing its type entirely is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_an_event_without_a_type(): void {
        $this->assertFalse(move_log::is_valid([['r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]]));
    }

    /**
     * Tests that a batch over the per-checkpoint size cap is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_over_the_size_cap(): void {
        $events = array_fill(
            0,
            move_log::MAX_EVENTS_PER_CHECKPOINT + 1,
            ['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]
        );

        $this->assertFalse(move_log::is_valid($events));
    }

    /**
     * Tests that a batch exactly at the size cap is still accepted — the cap rejects what is
     * over it, not what is at it.
     *
     * @return void
     */
    public function test_is_valid_accepts_exactly_the_size_cap(): void {
        $events = array_fill(
            0,
            move_log::MAX_EVENTS_PER_CHECKPOINT,
            ['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]
        );

        $this->assertTrue(move_log::is_valid($events));
    }

    /**
     * Tests that a negative coordinate on a move event is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_a_negative_coordinate(): void {
        $this->assertFalse(move_log::is_valid([['type' => 'move', 'r1' => -1, 'c1' => 0, 'r2' => 0, 'c2' => 1]]));
    }

    /**
     * Tests that a coordinate at or beyond the board's own dimension is rejected — the board
     * is 8x8, so valid rows/columns are 0-7.
     *
     * @return void
     */
    public function test_is_valid_rejects_a_coordinate_at_or_past_the_board_edge(): void {
        $this->assertFalse(move_log::is_valid([['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 8, 'c2' => 0]]));
        $this->assertTrue(move_log::is_valid([['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 7, 'c2' => 0]]));
    }

    /**
     * Tests that a move event missing a coordinate is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_a_move_missing_a_coordinate(): void {
        $this->assertFalse(move_log::is_valid([['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0]]));
    }

    /**
     * Tests that a question event with an invalid side is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_a_question_with_an_invalid_side(): void {
        $this->assertFalse(move_log::is_valid([['type' => 'question', 'side' => 'referee', 'outcome' => 'answered']]));
    }

    /**
     * Tests that a question event missing its outcome is rejected.
     *
     * @return void
     */
    public function test_is_valid_rejects_a_question_missing_outcome(): void {
        $this->assertFalse(move_log::is_valid([['type' => 'question', 'side' => 'player']]));
    }

    /**
     * Tests that a question event with an unknown outcome is rejected — including the old
     * shape that told the server whether the answer was right, which the client no longer gets
     * to say.
     *
     * @return void
     */
    public function test_is_valid_rejects_an_unknown_question_outcome(): void {
        $this->assertFalse(move_log::is_valid([['type' => 'question', 'side' => 'player', 'outcome' => 'won']]));
        $this->assertFalse(move_log::is_valid([['type' => 'question', 'side' => 'player', 'correct' => true]]));
    }

    /**
     * Tests that appending preserves order and simply concatenates.
     *
     * @return void
     */
    public function test_append_preserves_order(): void {
        $existing = [['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]];
        $incoming = [['type' => 'question', 'side' => 'player', 'outcome' => 'answered']];

        $this->assertSame(
            [
                ['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1],
                ['type' => 'question', 'side' => 'player', 'outcome' => 'answered'],
            ],
            move_log::append($existing, $incoming)
        );
    }

    /**
     * Tests that the whole-phase budget accepts a total at or under the cap and rejects one
     * over it.
     *
     * @return void
     */
    public function test_is_within_phase_budget(): void {
        $this->assertTrue(move_log::is_within_phase_budget(move_log::MAX_EVENTS_PER_PHASE - 1, 1));
        $this->assertFalse(move_log::is_within_phase_budget(move_log::MAX_EVENTS_PER_PHASE, 1));
    }

    /**
     * Tests that encode()/decode() round-trip a batch of events verbatim.
     *
     * @return void
     */
    public function test_encode_decode_round_trip(): void {
        $events = [
            ['type' => 'move', 'r1' => 2, 'c1' => 3, 'r2' => 2, 'c2' => 4],
            ['type' => 'question', 'side' => 'boss', 'outcome' => 'failed'],
        ];

        $this->assertSame($events, move_log::decode(move_log::encode($events)));
    }

    /**
     * Tests that encoding an empty batch stores null rather than an empty JSON array —
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

    /**
     * Tests that a combat consumable event is valid, while Hint (which only reveals text) and
     * an unknown kind are not.
     *
     * @return void
     */
    public function test_is_valid_accepts_only_combat_consumables(): void {
        foreach (['potion', 'shield', 'magic', 'sword'] as $kind) {
            $this->assertTrue(move_log::is_valid([['type' => 'consumable', 'kind' => $kind]]));
        }
        $this->assertFalse(move_log::is_valid([['type' => 'consumable', 'kind' => 'hint']]));
        $this->assertFalse(move_log::is_valid([['type' => 'consumable', 'kind' => 'bomb']]));
        $this->assertFalse(move_log::is_valid([['type' => 'consumable']]));
    }
}
