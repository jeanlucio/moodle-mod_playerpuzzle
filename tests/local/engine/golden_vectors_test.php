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
 * Cross-language parity fixtures for board_engine — the mechanism that turns "someone changed
 * the rule on one side only" from a silent bug into a red CI run.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Every expected value below was produced by running the real amd/src/engine/board_rules.js
 * (with amd/src/engine/prng.js as its rng source) headless via Node, then hardcoded here —
 * never by hand-computing an expected board. If a future change to either side's rng-draw
 * order, iteration order, or retry condition ever desyncs the two implementations, one of
 * these tests fails; regenerating the fixtures is the only legitimate way to make them pass
 * again, and doing so should never happen without first understanding why the two sides
 * diverged.
 *
 * @covers \mod_playerpuzzle\local\engine\board_engine
 */
final class golden_vectors_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Reshapes a flat row-major array into a rows x cols grid, the shape board_engine expects.
     *
     * @param array $flat Flat row-major values.
     * @param int $rows Row count.
     * @param int $cols Column count.
     * @return array The rows x cols grid.
     */
    private function unflatten(array $flat, int $rows, int $cols): array {
        $grid = [];
        for ($row = 0; $row < $rows; $row++) {
            $grid[$row] = array_slice($flat, $row * $cols, $cols);
        }
        return $grid;
    }

    /**
     * Flattens a rows x cols grid back into a row-major array, for comparison against a
     * hardcoded flat fixture.
     *
     * @param array $grid The rows x cols grid.
     * @return array Flat row-major values.
     */
    private function flatten(array $grid): array {
        return array_merge(...$grid);
    }

    /**
     * Tests that generate_grid(8, 8, null, prng::create(42)) matches the JS side exactly.
     *
     * @return void
     */
    public function test_generate_grid_matches_js_for_seed_42(): void {
        $grid = board_engine::generate_grid(8, 8, null, prng::create(42));
        $this->assertSame([
            4, 3, 5, 4, 1, 3, 1, 4,
            6, 3, 1, 6, 5, 2, 1, 3,
            4, 4, 0, 3, 5, 0, 4, 0,
            1, 0, 1, 5, 3, 0, 1, 5,
            3, 5, 2, 3, 0, 3, 4, 1,
            4, 1, 2, 5, 6, 3, 1, 1,
            2, 0, 4, 4, 6, 0, 6, 3,
            6, 0, 0, 2, 3, 4, 6, 0,
        ], $this->flatten($grid));
    }

    /**
     * Tests that generate_grid(8, 8, null, prng::create(777)) matches the JS side exactly —
     * a second seed, so this isn't just seed 42 agreeing by coincidence.
     *
     * @return void
     */
    public function test_generate_grid_matches_js_for_seed_777(): void {
        $grid = board_engine::generate_grid(8, 8, null, prng::create(777));
        $this->assertSame([
            4, 0, 1, 0, 4, 2, 1, 5,
            4, 5, 4, 5, 0, 4, 3, 6,
            3, 0, 6, 5, 6, 0, 2, 0,
            5, 4, 4, 0, 3, 0, 1, 4,
            3, 0, 0, 4, 2, 4, 3, 2,
            6, 4, 0, 5, 6, 6, 0, 6,
            4, 1, 5, 2, 4, 5, 1, 1,
            1, 4, 3, 6, 1, 3, 1, 0,
        ], $this->flatten($grid));
    }

    /**
     * Tests that shuffle_until_valid(seed 99), starting from a diagonal-stripe board that is
     * itself a match (forcing at least one retry), matches the JS side's exact final
     * arrangement.
     *
     * @return void
     */
    public function test_shuffle_until_valid_matches_js_for_seed_99(): void {
        $grid = $this->unflatten([
            0, 0, 0, 1, 2, 3, 4, 5,
            1, 2, 3, 4, 5, 6, 0, 1,
            2, 3, 4, 5, 6, 0, 1, 2,
            3, 4, 5, 6, 0, 1, 2, 3,
            4, 5, 6, 0, 1, 2, 3, 4,
            5, 6, 0, 1, 2, 3, 4, 5,
            6, 0, 1, 2, 3, 4, 5, 6,
            0, 1, 2, 3, 4, 5, 6, 0,
        ], 8, 8);

        board_engine::shuffle_until_valid($grid, 8, 8, prng::create(99));

        $this->assertSame([
            4, 5, 4, 5, 0, 0, 5, 3,
            0, 2, 3, 0, 3, 3, 4, 2,
            6, 3, 6, 6, 5, 6, 2, 4,
            1, 4, 3, 4, 2, 1, 2, 1,
            0, 6, 6, 5, 1, 1, 5, 2,
            0, 1, 5, 0, 1, 0, 4, 5,
            4, 6, 3, 0, 5, 2, 0, 1,
            3, 4, 2, 3, 6, 1, 2, 0,
        ], $this->flatten($grid));
    }

    /**
     * Tests that apply_gravity_to_grid(seed 55), starting from a 4x4 board with scattered
     * gaps, matches the JS side's exact resulting grid, fell list, and spawned list.
     *
     * @return void
     */
    public function test_apply_gravity_matches_js_for_seed_55(): void {
        $grid = [
            [null, 1, null, 3],
            [2, null, null, 4],
            [null, 3, 5, null],
            [1, null, 2, 6],
        ];

        $result = board_engine::apply_gravity_to_grid($grid, 4, 4, prng::create(55));

        $this->assertSame([
            3, 4, 0, 3,
            6, 5, 1, 3,
            2, 1, 5, 4,
            1, 3, 2, 6,
        ], $this->flatten($grid));

        $this->assertSame([
            ['fromRow' => 1, 'toRow' => 2, 'col' => 0],
            ['fromRow' => 2, 'toRow' => 3, 'col' => 1],
            ['fromRow' => 0, 'toRow' => 2, 'col' => 1],
            ['fromRow' => 1, 'toRow' => 2, 'col' => 3],
            ['fromRow' => 0, 'toRow' => 1, 'col' => 3],
        ], $result['fell']);

        $this->assertSame([
            ['row' => 0, 'col' => 0, 'type' => 3],
            ['row' => 1, 'col' => 0, 'type' => 6],
            ['row' => 0, 'col' => 1, 'type' => 4],
            ['row' => 1, 'col' => 1, 'type' => 5],
            ['row' => 0, 'col' => 2, 'type' => 0],
            ['row' => 1, 'col' => 2, 'type' => 1],
            ['row' => 0, 'col' => 3, 'type' => 3],
        ], $result['spawned']);
    }

    /**
     * Tests the closest thing to a real (seed + move sequence) -> outcome vector: generates a
     * fresh seed-2026 board, finds and applies the first available move on that exact board,
     * then resolves the resulting cascade (destroy -> gravity -> re-check, repeated until no
     * match remains) — generate_grid, find_move, check_horizontal/check_vertical and
     * apply_gravity_to_grid all sharing the same rng stream, exactly as a real turn would.
     *
     * @return void
     */
    public function test_full_cascade_matches_js_for_seed_2026(): void {
        $rng = prng::create(2026);
        $grid = board_engine::generate_grid(8, 8, null, $rng);

        $move = board_engine::find_move($grid, 8, 8, null);
        $this->assertSame(['r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1], $move);
        board_engine::swap_in_grid($grid, $move['r1'], $move['c1'], $move['r2'], $move['c2']);

        $rounds = 0;
        $totaldestroyed = 0;
        while (true) {
            $todestroy = [];
            $matchgroups = [];
            board_engine::check_horizontal($grid, 8, 8, $todestroy, $matchgroups);
            board_engine::check_vertical($grid, 8, 8, $todestroy, $matchgroups);
            if (count($todestroy) === 0) {
                break;
            }
            $totaldestroyed += count($todestroy);
            foreach ($todestroy as $cell) {
                $grid[$cell['row']][$cell['col']] = null;
            }
            board_engine::apply_gravity_to_grid($grid, 8, 8, $rng);
            $rounds++;
        }

        $this->assertSame(2, $rounds);
        $this->assertSame(6, $totaldestroyed);
        $this->assertSame([
            0, 6, 6, 4, 1, 1, 4, 5,
            2, 3, 4, 4, 2, 0, 1, 2,
            1, 6, 3, 0, 1, 1, 5, 6,
            6, 5, 1, 1, 2, 2, 4, 5,
            2, 6, 0, 0, 2, 5, 5, 2,
            6, 4, 4, 2, 0, 6, 1, 6,
            5, 1, 3, 1, 6, 4, 6, 5,
            4, 3, 3, 5, 5, 4, 4, 6,
        ], $this->flatten($grid));
    }
}
