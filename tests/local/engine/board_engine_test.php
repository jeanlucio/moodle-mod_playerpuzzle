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
 * Unit tests for the server-side board engine, mirroring tests/js/engine/board_rules.test.js
 * case for case so both sides are known to agree on every rule, not just the rng stream.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Tests for board_engine.
 *
 * @covers \mod_playerpuzzle\local\engine\board_engine
 */
final class board_engine_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds a rows x cols grid filled with a single type, then overrides cells listed in
     * overrides.
     *
     * @param int $rows Row count.
     * @param int $cols Column count.
     * @param int|null $fill Default type for every cell.
     * @param array $overrides Map keyed by "row,col" strings to a type override (int or null).
     * @return array The grid.
     */
    private function build_grid(int $rows, int $cols, ?int $fill, array $overrides = []): array {
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $grid[$r] = [];
            for ($c = 0; $c < $cols; $c++) {
                $key = "$r,$c";
                $grid[$r][$c] = array_key_exists($key, $overrides) ? $overrides[$key] : $fill;
            }
        }
        return $grid;
    }

    /**
     * Builds a deterministic rng returning a fixed sequence, cycling once exhausted.
     *
     * @param array $sequence Values in [0, 1) to return in order.
     * @return \Closure An rng function.
     */
    private function sequence_rng(array $sequence): \Closure {
        $i = 0;
        return function () use (&$i, $sequence) {
            $value = $sequence[$i % count($sequence)];
            $i++;
            return $value;
        };
    }

    /**
     * Tests that check_horizontal finds a run of exactly 3 and reports its cells.
     *
     * @return void
     */
    public function test_check_horizontal_finds_a_run_of_exactly_3(): void {
        $grid = $this->build_grid(4, 4, null, ['1,0' => 2, '1,1' => 2, '1,2' => 2]);
        $todestroy = [];
        $matchgroups = [];
        board_engine::check_horizontal($grid, 4, 4, $todestroy, $matchgroups);
        $this->assertCount(3, $todestroy);
        $this->assertSame([
            ['type' => 2, 'cells' => [['row' => 1, 'col' => 0], ['row' => 1, 'col' => 1], ['row' => 1, 'col' => 2]]],
        ], $matchgroups);
    }

    /**
     * Tests that check_horizontal finds a run of 5, not just the minimum 3.
     *
     * @return void
     */
    public function test_check_horizontal_finds_a_run_of_5(): void {
        $grid = $this->build_grid(1, 5, 4);
        $todestroy = [];
        $matchgroups = [];
        board_engine::check_horizontal($grid, 1, 5, $todestroy, $matchgroups);
        $this->assertCount(5, $todestroy);
        $this->assertCount(5, $matchgroups[0]['cells']);
    }

    /**
     * Tests that check_horizontal ignores a run of only 2.
     *
     * @return void
     */
    public function test_check_horizontal_ignores_a_run_of_2(): void {
        $grid = $this->build_grid(1, 4, null, ['0,0' => 1, '0,1' => 1]);
        $todestroy = [];
        $matchgroups = [];
        board_engine::check_horizontal($grid, 1, 4, $todestroy, $matchgroups);
        $this->assertCount(0, $todestroy);
        $this->assertCount(0, $matchgroups);
    }

    /**
     * Tests that check_horizontal treats type 0 as a real value, never as an empty cell.
     *
     * @return void
     */
    public function test_check_horizontal_treats_type_0_as_real(): void {
        $grid = $this->build_grid(1, 4, null, ['0,0' => 0, '0,1' => 0, '0,2' => 0]);
        $todestroy = [];
        $matchgroups = [];
        board_engine::check_horizontal($grid, 1, 4, $todestroy, $matchgroups);
        $this->assertCount(3, $todestroy, 'a run of type 0 must match exactly like any other type');
    }

    /**
     * Tests that check_vertical finds a vertical run.
     *
     * @return void
     */
    public function test_check_vertical_finds_a_vertical_run(): void {
        $grid = $this->build_grid(4, 1, null, ['0,0' => 3, '1,0' => 3, '2,0' => 3]);
        $todestroy = [];
        $matchgroups = [];
        board_engine::check_vertical($grid, 4, 1, $todestroy, $matchgroups);
        $this->assertCount(3, $todestroy);
        $this->assertSame(3, $matchgroups[0]['type']);
    }

    /**
     * Tests that check_horizontal and check_vertical dedupe a shared intersection cell in
     * todestroy.
     *
     * @return void
     */
    public function test_horizontal_and_vertical_dedupe_shared_intersection(): void {
        // An L shape: three cells across the top row plus two more down the left column,
        // sharing the corner cell where they meet.
        $grid = $this->build_grid(3, 3, null, [
            '0,0' => 5, '0,1' => 5, '0,2' => 5,
            '1,0' => 5, '2,0' => 5,
        ]);
        $todestroy = [];
        $matchgroups = [];
        board_engine::check_horizontal($grid, 3, 3, $todestroy, $matchgroups);
        board_engine::check_vertical($grid, 3, 3, $todestroy, $matchgroups);
        $this->assertCount(5, $todestroy, '(0,0) must appear once, not twice');
        $this->assertCount(2, $matchgroups, 'two separate match groups still exist for combo-size effects');
    }

    /**
     * Tests that is_match_at is false for an empty (null) cell.
     *
     * @return void
     */
    public function test_is_match_at_false_for_empty_cell(): void {
        $grid = $this->build_grid(3, 3, null);
        $this->assertFalse(board_engine::is_match_at($grid, 3, 3, 1, 1, null));
    }

    /**
     * Tests that is_match_at respects the onlytype filter.
     *
     * @return void
     */
    public function test_is_match_at_respects_only_type(): void {
        $grid = $this->build_grid(1, 3, 2);
        $this->assertTrue(board_engine::is_match_at($grid, 1, 3, 0, 1, 2));
        $this->assertFalse(board_engine::is_match_at($grid, 1, 3, 0, 1, 9));
    }

    /**
     * Tests that match_run_length_at returns 0 when there is no match.
     *
     * @return void
     */
    public function test_match_run_length_at_returns_0_without_a_match(): void {
        $grid = $this->build_grid(3, 3, null, ['1,1' => 1]);
        $this->assertSame(0, board_engine::match_run_length_at($grid, 3, 3, 1, 1));
    }

    /**
     * Tests that match_run_length_at returns the real run length, not just true/false.
     *
     * @return void
     */
    public function test_match_run_length_at_returns_real_length(): void {
        $grid = $this->build_grid(1, 4, 6);
        $this->assertSame(4, board_engine::match_run_length_at($grid, 1, 4, 0, 0));
    }

    /**
     * Tests that swap_in_grid exchanges two cells and is its own inverse.
     *
     * @return void
     */
    public function test_swap_in_grid_is_its_own_inverse(): void {
        $grid = $this->build_grid(2, 2, null, ['0,0' => 1, '0,1' => 2]);
        board_engine::swap_in_grid($grid, 0, 0, 0, 1);
        $this->assertSame(2, $grid[0][0]);
        $this->assertSame(1, $grid[0][1]);
        board_engine::swap_in_grid($grid, 0, 0, 0, 1);
        $this->assertSame(1, $grid[0][0]);
        $this->assertSame(2, $grid[0][1]);
    }

    /**
     * Tests that evaluate_swap reports the matched type and leaves the grid unchanged.
     *
     * @return void
     */
    public function test_evaluate_swap_reports_matched_type_and_reverts(): void {
        // Swapping (0,3) into (0,2) completes a horizontal run of type 7 at row 0.
        $grid = $this->build_grid(1, 4, null, ['0,0' => 7, '0,1' => 7, '0,2' => 9, '0,3' => 7]);
        $matched = board_engine::evaluate_swap($grid, 1, 4, 0, 2, 0, 3);
        $this->assertSame(7, $matched);
        $this->assertSame(9, $grid[0][2], 'grid must be reverted after evaluate_swap');
        $this->assertSame(7, $grid[0][3], 'grid must be reverted after evaluate_swap');
    }

    /**
     * Tests that evaluate_swap returns null when the swap produces no match.
     *
     * @return void
     */
    public function test_evaluate_swap_returns_null_without_a_match(): void {
        $grid = $this->build_grid(2, 2, null, ['0,0' => 1, '0,1' => 2, '1,0' => 3, '1,1' => 4]);
        $this->assertNull(board_engine::evaluate_swap($grid, 2, 2, 0, 0, 0, 1));
    }

    /**
     * Tests that find_move finds a swap that produces a match.
     *
     * @return void
     */
    public function test_find_move_finds_a_matching_swap(): void {
        $grid = $this->build_grid(2, 3, null, ['0,0' => 5, '0,1' => 5, '1,2' => 5, '0,2' => 1]);
        $move = board_engine::find_move($grid, 2, 3, null);
        $this->assertNotNull($move);
        board_engine::swap_in_grid($grid, $move['r1'], $move['c1'], $move['r2'], $move['c2']);
        $todestroy = [];
        $matchgroups = [];
        board_engine::check_horizontal($grid, 2, 3, $todestroy, $matchgroups);
        board_engine::check_vertical($grid, 2, 3, $todestroy, $matchgroups);
        $this->assertGreaterThanOrEqual(3, count($todestroy));
    }

    /**
     * Tests that find_move returns null and has_available_move is false on a board with no
     * valid swap — a 2x2 board can never contain a run of 3 in either direction.
     *
     * @return void
     */
    public function test_find_move_returns_null_on_a_board_with_no_move(): void {
        $grid = $this->build_grid(2, 2, null, ['0,0' => 1, '0,1' => 2, '1,0' => 3, '1,1' => 4]);
        $this->assertNull(board_engine::find_move($grid, 2, 2, null));
        $this->assertFalse(board_engine::has_available_move($grid, 2, 2));
    }

    /**
     * Tests that pick_type_avoiding_match never completes a 3-in-a-row above or to the left.
     *
     * @return void
     */
    public function test_pick_type_avoiding_match_never_completes_a_run(): void {
        // Rng sequence forces type 2 first, which WOULD match at (2,0) against the two cells
        // above it (both already 2) if the anti-match re-roll did not kick in.
        $grid = $this->build_grid(3, 1, null, ['0,0' => 2, '1,0' => 2]);
        $rng = $this->sequence_rng([2 / 7, 3 / 7]);
        $picked = board_engine::pick_type_avoiding_match($grid, 2, 0, $rng);
        $this->assertNotSame(2, $picked, 'must not pick the type that would complete the vertical run');
    }

    /**
     * Tests that generate_grid replays a saved grid verbatim instead of rolling new types.
     *
     * @return void
     */
    public function test_generate_grid_replays_a_saved_grid(): void {
        $saved = [1, 2, 3, 4];
        $grid = board_engine::generate_grid(2, 2, $saved, fn() => 0);
        $this->assertSame([[1, 2], [3, 4]], $grid);
    }

    /**
     * Tests that generate_grid never produces an initial 3-in-a-row anywhere on the board.
     *
     * @return void
     */
    public function test_generate_grid_never_starts_with_a_match(): void {
        $seed = 42;
        $rng = function () use (&$seed) {
            $seed = ($seed * 1103515245 + 12345) % 0x7fffffff;
            return abs($seed) / 0x7fffffff;
        };
        $grid = board_engine::generate_grid(8, 8, null, $rng);
        $todestroy = [];
        $matchgroups = [];
        board_engine::check_horizontal($grid, 8, 8, $todestroy, $matchgroups);
        board_engine::check_vertical($grid, 8, 8, $todestroy, $matchgroups);
        $this->assertCount(0, $todestroy, 'a freshly generated board must never start with a match');
    }

    /**
     * Tests that apply_gravity_to_grid drops a floating piece to the bottom of its column.
     *
     * @return void
     */
    public function test_apply_gravity_drops_a_floating_piece(): void {
        $grid = $this->build_grid(3, 1, null, ['0,0' => 5]);
        $result = board_engine::apply_gravity_to_grid($grid, 3, 1, fn() => 0);
        $this->assertSame(5, $grid[2][0], 'the piece must fall to the lowest empty row');
        $this->assertSame(0, $grid[0][0], 'the vacated top cell must be refilled');
        $this->assertSame(0, $grid[1][0], 'the vacated middle cell must be refilled too');
        $this->assertSame([['fromRow' => 0, 'toRow' => 2, 'col' => 0]], $result['fell']);
        $this->assertSame([
            ['row' => 0, 'col' => 0, 'type' => 0],
            ['row' => 1, 'col' => 0, 'type' => 0],
        ], $result['spawned']);
    }

    /**
     * Tests that apply_gravity_to_grid leaves an already-full column untouched.
     *
     * @return void
     */
    public function test_apply_gravity_leaves_a_full_column_untouched(): void {
        $grid = $this->build_grid(2, 1, 3);
        $result = board_engine::apply_gravity_to_grid($grid, 2, 1, fn() => 1);
        $this->assertSame([[3], [3]], $grid);
        $this->assertSame([], $result['fell']);
        $this->assertSame([], $result['spawned']);
    }

    /**
     * Tests that apply_gravity_to_grid spawns new pieces using the injected rng.
     *
     * @return void
     */
    public function test_apply_gravity_spawns_using_the_injected_rng(): void {
        $grid = $this->build_grid(1, 1, null);
        $result = board_engine::apply_gravity_to_grid($grid, 1, 1, fn() => 6 / 7);
        $this->assertSame(6, $grid[0][0]);
        $this->assertSame(6, $result['spawned'][0]['type']);
    }

    /**
     * Tests that has_any_match is true when a run of 3+ exists anywhere, false otherwise.
     *
     * @return void
     */
    public function test_has_any_match(): void {
        $withmatch = $this->build_grid(1, 4, null, ['0,0' => 2, '0,1' => 2, '0,2' => 2, '0,3' => 5]);
        $withoutmatch = $this->build_grid(1, 4, null, ['0,0' => 2, '0,1' => 2, '0,2' => 5, '0,3' => 2]);
        $this->assertTrue(board_engine::has_any_match($withmatch, 1, 4));
        $this->assertFalse(board_engine::has_any_match($withoutmatch, 1, 4));
    }

    /**
     * Tests that shuffle_grid produces the documented Fisher-Yates result for a known rng
     * sequence — locks in the exact algorithm/scan direction as a regression guard shared with
     * the JS side's own equivalent test.
     *
     * @return void
     */
    public function test_shuffle_grid_matches_the_documented_fisher_yates_result(): void {
        $grid = [[0, 1], [2, 3]];
        $sequence = [0.9, 0.1, 0.5];
        $i = 0;
        board_engine::shuffle_grid($grid, 2, 2, function () use (&$i, $sequence) {
            return $sequence[$i++];
        });
        $this->assertSame([[2, 1], [0, 3]], $grid);
    }

    /**
     * Tests that shuffle_grid redistributes the existing types, never invents or drops one.
     *
     * @return void
     */
    public function test_shuffle_grid_redistributes_existing_types(): void {
        $grid = $this->build_grid(2, 3, null, [
            '0,0' => 5, '0,1' => 5, '0,2' => 1,
            '1,0' => 2, '1,1' => 3, '1,2' => 4,
        ]);
        $before = [5, 5, 1, 2, 3, 4];
        sort($before);
        board_engine::shuffle_grid($grid, 2, 3, $this->sequence_rng([0.9, 0.2, 0.7, 0.4, 0.1]));
        $after = array_merge(...$grid);
        sort($after);
        $this->assertSame($before, $after);
    }

    /**
     * Tests that shuffle_until_valid retries a shuffle that lands on an existing match.
     *
     * @return void
     */
    public function test_shuffle_until_valid_retries_an_invalid_shuffle(): void {
        $forcedinvalidfirstattempt = [0.99, 0.99, 0.99, 0.99, 0.99];
        $seed = 7;
        $draws = 0;
        $rng = function () use (&$seed, &$draws, $forcedinvalidfirstattempt) {
            $draws++;
            if ($draws <= count($forcedinvalidfirstattempt)) {
                return $forcedinvalidfirstattempt[$draws - 1];
            }
            $seed = ($seed * 1103515245 + 12345) % 0x7fffffff;
            return abs($seed) / 0x7fffffff;
        };

        $grid = [[0, 0, 0, 1, 2, 3]];
        board_engine::shuffle_until_valid($grid, 1, 6, $rng);

        $this->assertGreaterThan(count($forcedinvalidfirstattempt), $draws, 'must have retried past the forced invalid attempt');
        $this->assertTrue(board_engine::has_available_move($grid, 1, 6));
        $this->assertFalse(board_engine::has_any_match($grid, 1, 6));
    }
}
