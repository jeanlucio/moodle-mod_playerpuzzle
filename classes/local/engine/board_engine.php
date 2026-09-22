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
 * Pure match-3 board logic, ported from amd/src/engine/board_rules.js for the server-side
 * replay engine.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Bit-for-bit mirror of amd/src/engine/board_rules.js. Every function operates on a plain
 * `grid` — a rows x cols array of arrays, each cell either a piece type (an integer 0-6) or
 * null for an empty cell mid-cascade — and returns plain arrays, never an object reference.
 *
 * The iteration order, the rng-draw order, and the retry conditions in every function here
 * must match the JS side exactly: a server-side replay consumes the same rng stream the
 * client did, so any divergence in when or how many times a function calls $rng() would
 * desynchronise the two sides even though both implement "the same rule".
 *
 * Cell values are compared with `=== null`/`!== null`, never truthiness, for the same reason
 * as the JS side: type 0 (the Star piece) is a valid value that PHP's own falsy checks would
 * wrongly treat as empty.
 */
class board_engine {
    /**
     * Swaps two cells' values in place.
     *
     * @param array $grid The board grid (mutated in place).
     * @param int $r1 Row of the first cell.
     * @param int $c1 Column of the first cell.
     * @param int $r2 Row of the second cell.
     * @param int $c2 Column of the second cell.
     * @return void
     */
    public static function swap_in_grid(array &$grid, int $r1, int $c1, int $r2, int $c2): void {
        $temp = $grid[$r1][$c1];
        $grid[$r1][$c1] = $grid[$r2][$c2];
        $grid[$r2][$c2] = $temp;
    }

    /**
     * Picks a type for a freshly-generated cell, re-rolling until it does not complete a
     * 3-in-a-row with the two cells already placed above it or to its left.
     *
     * @param array $grid The board grid, filled so far.
     * @param int $row Row being filled.
     * @param int $col Column being filled.
     * @param \Closure $rng Returns a float in [0, 1) — see prng::create().
     * @return int A piece type, 0-6.
     */
    public static function pick_type_avoiding_match(array $grid, int $row, int $col, \Closure $rng): int {
        do {
            $randomtype = (int) floor($rng() * 7);
            $hasmatch = false;
            if ($row >= 2 && ($grid[$row - 1][$col] ?? null) === $randomtype && ($grid[$row - 2][$col] ?? null) === $randomtype) {
                $hasmatch = true;
            }
            if ($col >= 2 && ($grid[$row][$col - 1] ?? null) === $randomtype && ($grid[$row][$col - 2] ?? null) === $randomtype) {
                $hasmatch = true;
            }
        } while ($hasmatch);
        return $randomtype;
    }

    /**
     * Builds a brand new rows x cols grid of piece types, either replaying a checkpointed
     * board verbatim or rolling fresh types cell by cell (row-major order, matching the order
     * pick_type_avoiding_match() expects its already-filled neighbours in).
     *
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param array|null $savedgrid A flat (row * cols + col) array of types to replay, or null
     *  to roll fresh ones.
     * @param \Closure $rng Returns a float in [0, 1) — see prng::create().
     * @return array A rows x cols array of piece types.
     */
    public static function generate_grid(int $rows, int $cols, ?array $savedgrid, \Closure $rng): array {
        $grid = [];
        for ($row = 0; $row < $rows; $row++) {
            $grid[$row] = [];
            for ($col = 0; $col < $cols; $col++) {
                $grid[$row][$col] = $savedgrid !== null
                    ? $savedgrid[($row * $cols) + $col]
                    : self::pick_type_avoiding_match($grid, $row, $col, $rng);
            }
        }
        return $grid;
    }

    /**
     * Registers one detected run as a match group, adding its cells to the flat, deduplicated
     * destroy list too (deduplicated by coordinate, since two different runs can share a cell
     * at their intersection).
     *
     * @param array $cells This run's cells, as ['row' => int, 'col' => int], in order.
     * @param int $type The piece type shared by every cell in this run.
     * @param array $todestroy Flat, deduplicated list of ['row', 'col'] to destroy (mutated).
     * @param array $matchgroups List of ['type', 'cells'] match groups (mutated in place).
     * @return void
     */
    public static function register_run(array $cells, int $type, array &$todestroy, array &$matchgroups): void {
        foreach ($cells as $cell) {
            $alreadylisted = false;
            foreach ($todestroy as $existing) {
                if ($existing['row'] === $cell['row'] && $existing['col'] === $cell['col']) {
                    $alreadylisted = true;
                    break;
                }
            }
            if (!$alreadylisted) {
                $todestroy[] = $cell;
            }
        }
        $matchgroups[] = ['type' => $type, 'cells' => $cells];
    }

    /**
     * Scans every row for contiguous same-type runs of 3+ cells, each pushed as its own match
     * group alongside the flat, deduplicated destroy list.
     *
     * @param array $grid The board grid.
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param array $todestroy Flat, deduplicated list of ['row', 'col'] to destroy (mutated).
     * @param array $matchgroups List of ['type', 'cells'] match groups (mutated in place).
     * @return void
     */
    public static function check_horizontal(array $grid, int $rows, int $cols, array &$todestroy, array &$matchgroups): void {
        for ($r = 0; $r < $rows; $r++) {
            $c = 0;
            while ($c < $cols) {
                $type = $grid[$r][$c];
                if ($type === null) {
                    $c++;
                    continue;
                }
                $runend = $c;
                while ($runend + 1 < $cols && $grid[$r][$runend + 1] === $type) {
                    $runend++;
                }
                if ($runend - $c + 1 >= 3) {
                    $cells = [];
                    for ($i = $c; $i <= $runend; $i++) {
                        $cells[] = ['row' => $r, 'col' => $i];
                    }
                    self::register_run($cells, $type, $todestroy, $matchgroups);
                }
                $c = $runend + 1;
            }
        }
    }

    /**
     * Same as check_horizontal(), scanning columns instead of rows.
     *
     * @param array $grid The board grid.
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param array $todestroy Flat, deduplicated list of ['row', 'col'] to destroy (mutated).
     * @param array $matchgroups List of ['type', 'cells'] match groups (mutated in place).
     * @return void
     */
    public static function check_vertical(array $grid, int $rows, int $cols, array &$todestroy, array &$matchgroups): void {
        for ($c = 0; $c < $cols; $c++) {
            $r = 0;
            while ($r < $rows) {
                $type = $grid[$r][$c];
                if ($type === null) {
                    $r++;
                    continue;
                }
                $runend = $r;
                while ($runend + 1 < $rows && $grid[$runend + 1][$c] === $type) {
                    $runend++;
                }
                if ($runend - $r + 1 >= 3) {
                    $cells = [];
                    for ($i = $r; $i <= $runend; $i++) {
                        $cells[] = ['row' => $i, 'col' => $c];
                    }
                    self::register_run($cells, $type, $todestroy, $matchgroups);
                }
                $r = $runend + 1;
            }
        }
    }

    /**
     * Checks whether the cell at the given coordinate is part of a match, optionally
     * restricted to a single piece type.
     *
     * @param array $grid The board grid.
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param int $rowp Row index.
     * @param int $colp Column index.
     * @param int|null $onlytype When set, only counts as a match if the cell's type equals
     *  this value.
     * @return bool Whether a match of at least 3 exists at this cell.
     */
    public static function is_match_at(array $grid, int $rows, int $cols, int $rowp, int $colp, ?int $onlytype): bool {
        $type = $grid[$rowp][$colp];
        if ($type === null) {
            return false;
        }
        if ($onlytype !== null && $type !== $onlytype) {
            return false;
        }

        $counth = 1;
        $tc = $colp - 1;
        while ($tc >= 0 && $grid[$rowp][$tc] === $type) {
            $counth++;
            $tc--;
        }
        $tc = $colp + 1;
        while ($tc < $cols && $grid[$rowp][$tc] === $type) {
            $counth++;
            $tc++;
        }
        if ($counth >= 3) {
            return true;
        }

        $countv = 1;
        $tr = $rowp - 1;
        while ($tr >= 0 && $grid[$tr][$colp] === $type) {
            $countv++;
            $tr--;
        }
        $tr = $rowp + 1;
        while ($tr < $rows && $grid[$tr][$colp] === $type) {
            $countv++;
            $tr++;
        }
        return $countv >= 3;
    }

    /**
     * Finds a valid swap that produces a match, optionally restricted to a piece type.
     *
     * @param array $grid The board grid.
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param int|null $onlytype When set, only returns a swap whose resulting match uses this
     *  piece type.
     * @return array|null ['r1', 'c1', 'r2', 'c2'] the two cells to swap, or null when none
     *  exist.
     */
    public static function find_move(array $grid, int $rows, int $cols, ?int $onlytype): ?array {
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                if ($c < $cols - 1) {
                    self::swap_in_grid($grid, $r, $c, $r, $c + 1);
                    $matchr = self::is_match_at($grid, $rows, $cols, $r, $c, $onlytype) ||
                        self::is_match_at($grid, $rows, $cols, $r, $c + 1, $onlytype);
                    self::swap_in_grid($grid, $r, $c, $r, $c + 1);
                    if ($matchr) {
                        return ['r1' => $r, 'c1' => $c, 'r2' => $r, 'c2' => $c + 1];
                    }
                }
                if ($r < $rows - 1) {
                    self::swap_in_grid($grid, $r, $c, $r + 1, $c);
                    $matchd = self::is_match_at($grid, $rows, $cols, $r, $c, $onlytype) ||
                        self::is_match_at($grid, $rows, $cols, $r + 1, $c, $onlytype);
                    self::swap_in_grid($grid, $r, $c, $r + 1, $c);
                    if ($matchd) {
                        return ['r1' => $r, 'c1' => $c, 'r2' => $r + 1, 'c2' => $c];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Whether any valid match-producing swap exists anywhere on the board.
     *
     * @param array $grid The board grid.
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @return bool
     */
    public static function has_available_move(array $grid, int $rows, int $cols): bool {
        return self::find_move($grid, $rows, $cols, null) !== null;
    }

    /**
     * Whether any match already exists anywhere on the grid, regardless of type or
     * orientation.
     *
     * @param array $grid The board grid.
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @return bool
     */
    public static function has_any_match(array $grid, int $rows, int $cols): bool {
        $todestroy = [];
        $matchgroups = [];
        self::check_horizontal($grid, $rows, $cols, $todestroy, $matchgroups);
        self::check_vertical($grid, $rows, $cols, $todestroy, $matchgroups);
        return count($todestroy) > 0;
    }

    /**
     * Fisher-Yates shuffle of every cell's type across the whole grid, in place — a
     * position-only shuffle. Scans right to left, swapping each cell with a uniformly-chosen
     * earlier-or-equal one; this exact direction must match the JS side, or a replay consuming
     * the same rng sequence would land on a different arrangement despite agreeing on every
     * individual draw.
     *
     * @param array $grid The board grid (mutated in place).
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param \Closure $rng Returns a float in [0, 1) — see prng::create().
     * @return void
     */
    public static function shuffle_grid(array &$grid, int $rows, int $cols, \Closure $rng): void {
        $flat = [];
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $flat[] = $grid[$row][$col];
            }
        }

        for ($i = count($flat) - 1; $i > 0; $i--) {
            $j = (int) floor($rng() * ($i + 1));
            $temp = $flat[$i];
            $flat[$i] = $flat[$j];
            $flat[$j] = $temp;
        }

        $idx = 0;
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $grid[$row][$col] = $flat[$idx];
                $idx++;
            }
        }
    }

    /**
     * Re-shuffles the grid (in place) until the result both has at least one available move
     * and contains no match on its own. Consumes a variable number of rng draws per call — a
     * PHP replay must retry with the identical acceptance test, or it will not consume rng
     * draws in the same count and everything after diverges.
     *
     * @param array $grid The board grid (mutated in place).
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param \Closure $rng Returns a float in [0, 1) — see prng::create().
     * @return void
     */
    public static function shuffle_until_valid(array &$grid, int $rows, int $cols, \Closure $rng): void {
        do {
            self::shuffle_grid($grid, $rows, $cols, $rng);
        } while (!self::has_available_move($grid, $rows, $cols) || self::has_any_match($grid, $rows, $cols));
    }

    /**
     * Computes the length of the match line (horizontal or vertical) passing through the given
     * cell, for whichever piece type currently sits there.
     *
     * @param array $grid The board grid.
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param int $rowp Row index.
     * @param int $colp Column index.
     * @return int Length of the run at this cell, or 0 when no match exists.
     */
    public static function match_run_length_at(array $grid, int $rows, int $cols, int $rowp, int $colp): int {
        $type = $grid[$rowp][$colp];
        if ($type === null) {
            return 0;
        }

        $counth = 1;
        $tc = $colp - 1;
        while ($tc >= 0 && $grid[$rowp][$tc] === $type) {
            $counth++;
            $tc--;
        }
        $tc = $colp + 1;
        while ($tc < $cols && $grid[$rowp][$tc] === $type) {
            $counth++;
            $tc++;
        }
        if ($counth >= 3) {
            return $counth;
        }

        $countv = 1;
        $tr = $rowp - 1;
        while ($tr >= 0 && $grid[$tr][$colp] === $type) {
            $countv++;
            $tr--;
        }
        $tr = $rowp + 1;
        while ($tr < $rows && $grid[$tr][$colp] === $type) {
            $countv++;
            $tr++;
        }
        return $countv >= 3 ? $countv : 0;
    }

    /**
     * Swaps two cells, checks whether either resulting cell matches, then reverts.
     *
     * @param array $grid The board grid (left unchanged on return).
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param int $r1 Row of the first cell.
     * @param int $c1 Column of the first cell.
     * @param int $r2 Row of the second cell.
     * @param int $c2 Column of the second cell.
     * @return int|null The piece type that would match, or null when this swap has no effect.
     */
    public static function evaluate_swap(array &$grid, int $rows, int $cols, int $r1, int $c1, int $r2, int $c2): ?int {
        self::swap_in_grid($grid, $r1, $c1, $r2, $c2);

        $matchedtype = null;
        if (self::match_run_length_at($grid, $rows, $cols, $r1, $c1) >= 3) {
            $matchedtype = $grid[$r1][$c1];
        } else if (self::match_run_length_at($grid, $rows, $cols, $r2, $c2) >= 3) {
            $matchedtype = $grid[$r2][$c2];
        }

        self::swap_in_grid($grid, $r1, $c1, $r2, $c2);
        return $matchedtype;
    }

    /**
     * Compacts each column downward and refills whatever stays empty at the top with a
     * freshly rolled type.
     *
     * @param array $grid The board grid (mutated in place).
     * @param int $rows Board row count.
     * @param int $cols Board column count.
     * @param \Closure $rng Returns a float in [0, 1) — see prng::create().
     * @return array ['fell' => array, 'spawned' => array] — fell: ['fromRow', 'toRow', 'col']
     *  per cell that moved down. spawned: ['row', 'col', 'type'] per newly-created cell.
     */
    public static function apply_gravity_to_grid(array &$grid, int $rows, int $cols, \Closure $rng): array {
        $fell = [];
        $spawned = [];

        for ($col = 0; $col < $cols; $col++) {
            for ($row = $rows - 1; $row >= 0; $row--) {
                if ($grid[$row][$col] !== null) {
                    continue;
                }
                for ($r = $row - 1; $r >= 0; $r--) {
                    if ($grid[$r][$col] !== null) {
                        $grid[$row][$col] = $grid[$r][$col];
                        $grid[$r][$col] = null;
                        $fell[] = ['fromRow' => $r, 'toRow' => $row, 'col' => $col];
                        break;
                    }
                }
            }
        }

        for ($col = 0; $col < $cols; $col++) {
            for ($row = 0; $row < $rows; $row++) {
                if ($grid[$row][$col] !== null) {
                    continue;
                }
                $type = (int) floor($rng() * 7);
                $grid[$row][$col] = $type;
                $spawned[] = ['row' => $row, 'col' => $col, 'type' => $type];
            }
        }

        return ['fell' => $fell, 'spawned' => $spawned];
    }
}
