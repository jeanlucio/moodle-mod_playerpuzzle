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
 * Pure match-3 board logic for PlayerPuzzle — decides what happens, never how to render it.
 *
 * Every function here operates on a plain `grid` — a `rows`x`cols` array of arrays, each
 * cell either a piece type (an integer 0-6) or `null` for an empty cell mid-cascade. Never a
 * Phaser object, never anything with methods. Results that identify a cell are always plain
 * `{row, col}` coordinates, never object references — board.js (the rendering layer) is the
 * one place that maps a coordinate back to the real Phaser image it needs to animate/destroy.
 *
 * Cell values are compared with strict equality against `null`, never truthiness: type `0`
 * (the Star piece) is a valid, common value that is falsy in JavaScript, so `!grid[r][c]` or
 * `grid[r][c] || ...` would wrongly treat a Star cell as empty. Every check in this file uses
 * `=== null`/`!== null` for exactly that reason.
 *
 * UMD-wrapped so the same file loads as an AMD module in the browser (via RequireJS, like
 * every other file in amd/src) and as a plain CommonJS module in Node (via `require()`, no
 * RequireJS involved) — the second path is what lets tests/js/engine/board_rules.test.js run
 * this logic headless with `node --test`, with no Phaser and no browser at all.
 *
 * @module     mod_playerpuzzle/engine/board_rules
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
(function(define) {
    define([], function() {
        'use strict';

        /**
         * Swaps two cells' values in place.
         *
         * @param {Array} grid The board grid (mutated in place).
         * @param {number} r1 Row of the first cell.
         * @param {number} c1 Column of the first cell.
         * @param {number} r2 Row of the second cell.
         * @param {number} c2 Column of the second cell.
         * @return {void}
         */
        function swapInGrid(grid, r1, c1, r2, c2) {
            const temp = grid[r1][c1];
            grid[r1][c1] = grid[r2][c2];
            grid[r2][c2] = temp;
        }

        /**
         * Picks a type for a freshly-generated cell, re-rolling until it does not complete a
         * 3-in-a-row with the two cells already placed above it or to its left. Only ever
         * called on cells not yet filled in the same generateGrid() pass, or during
         * applyGravityToGrid()'s refill — never on an already-settled board.
         *
         * @param {Array} grid The board grid, filled so far (cells not yet reached are
         *  ignored — row/col bounds below never read them).
         * @param {number} row Row being filled.
         * @param {number} col Column being filled.
         * @param {Function} rng Returns a float in [0, 1) — Math.random in production, a
         *  seeded generator in a test wanting a deterministic sequence.
         * @return {number} A piece type, 0-6.
         */
        function pickTypeAvoidingMatch(grid, row, col, rng) {
            let randomType;
            let hasMatch;
            do {
                randomType = Math.floor(rng() * 7);
                hasMatch = false;
                if (row >= 2 && grid[row - 1][col] === randomType && grid[row - 2][col] === randomType) {
                    hasMatch = true;
                }
                if (col >= 2 && grid[row][col - 1] === randomType && grid[row][col - 2] === randomType) {
                    hasMatch = true;
                }
            } while (hasMatch);
            return randomType;
        }

        /**
         * Builds a brand new rows x cols grid of piece types, either replaying a checkpointed
         * board verbatim or rolling fresh types cell by cell (row-major order, matching the
         * order pickTypeAvoidingMatch() expects its already-filled neighbours in).
         *
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {Array|null} savedgrid A flat (row * cols + col) array of types to replay, or
         *  null/undefined to roll fresh ones.
         * @param {Function} rng Returns a float in [0, 1) — see pickTypeAvoidingMatch().
         * @return {Array} A rows x cols array of piece types.
         */
        function generateGrid(rows, cols, savedgrid, rng) {
            const grid = [];
            for (let row = 0; row < rows; row++) {
                grid[row] = [];
                for (let col = 0; col < cols; col++) {
                    grid[row][col] = savedgrid
                        ? savedgrid[(row * cols) + col]
                        : pickTypeAvoidingMatch(grid, row, col, rng);
                }
            }
            return grid;
        }

        /**
         * Registers one detected run as a match group, adding its cells to the flat,
         * deduplicated destroy list too (deduplicated by coordinate, since two different runs
         * — one horizontal, one vertical — can share a cell at their intersection). Shared by
         * checkHorizontal()/checkVertical() to keep both scans within the project's max
         * block-nesting depth.
         *
         * @param {Array} cells This run's cells, as {row, col}, in order.
         * @param {number} type The piece type shared by every cell in this run.
         * @param {Array} toDestroy Flat, deduplicated list of {row, col} to destroy (mutated).
         * @param {Array} matchGroups List of {type, cells} match groups (mutated in place).
         * @return {void}
         */
        function registerRun(cells, type, toDestroy, matchGroups) {
            for (const cell of cells) {
                const alreadyListed = toDestroy.some(existing => existing.row === cell.row && existing.col === cell.col);
                if (!alreadyListed) {
                    toDestroy.push(cell);
                }
            }
            matchGroups.push({type, cells});
        }

        /**
         * Scans every row for contiguous same-type runs of 3+ cells, each pushed as its own
         * match group (with the exact run length) alongside the flat, deduplicated destroy
         * list — combo-size-aware effects (Sword/Coin) read group sizes; every other piece
         * effect still reads the flat list.
         *
         * @param {Array} grid The board grid.
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {Array} toDestroy Flat, deduplicated list of {row, col} to destroy (mutated).
         * @param {Array} matchGroups List of {type, cells} match groups (mutated in place).
         * @return {void}
         */
        function checkHorizontal(grid, rows, cols, toDestroy, matchGroups) {
            for (let r = 0; r < rows; r++) {
                let c = 0;
                while (c < cols) {
                    const type = grid[r][c];
                    if (type === null) {
                        c++;
                        continue;
                    }
                    let runEnd = c;
                    while (runEnd + 1 < cols && grid[r][runEnd + 1] === type) {
                        runEnd++;
                    }
                    if (runEnd - c + 1 >= 3) {
                        const cells = [];
                        for (let i = c; i <= runEnd; i++) {
                            cells.push({row: r, col: i});
                        }
                        registerRun(cells, type, toDestroy, matchGroups);
                    }
                    c = runEnd + 1;
                }
            }
        }

        /**
         * Same as checkHorizontal(), scanning columns instead of rows.
         *
         * @param {Array} grid The board grid.
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {Array} toDestroy Flat, deduplicated list of {row, col} to destroy (mutated).
         * @param {Array} matchGroups List of {type, cells} match groups (mutated in place).
         * @return {void}
         */
        function checkVertical(grid, rows, cols, toDestroy, matchGroups) {
            for (let c = 0; c < cols; c++) {
                let r = 0;
                while (r < rows) {
                    const type = grid[r][c];
                    if (type === null) {
                        r++;
                        continue;
                    }
                    let runEnd = r;
                    while (runEnd + 1 < rows && grid[runEnd + 1][c] === type) {
                        runEnd++;
                    }
                    if (runEnd - r + 1 >= 3) {
                        const cells = [];
                        for (let i = r; i <= runEnd; i++) {
                            cells.push({row: i, col: c});
                        }
                        registerRun(cells, type, toDestroy, matchGroups);
                    }
                    r = runEnd + 1;
                }
            }
        }

        /**
         * Checks whether the cell at the given coordinate is part of a match, optionally
         * restricted to a single piece type (used by findMove() to hunt for a specific type of
         * match, e.g. the boss prioritising damage-dealing pieces).
         *
         * @param {Array} grid The board grid.
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {number} rowP Row index.
         * @param {number} colP Column index.
         * @param {number|null} onlyType When set, only counts as a match if the cell's type
         *  equals this value.
         * @return {boolean} Whether a match of at least 3 exists at this cell.
         */
        function isMatchAt(grid, rows, cols, rowP, colP, onlyType) {
            const type = grid[rowP][colP];
            if (type === null) {
                return false;
            }
            if (onlyType !== null && onlyType !== undefined && type !== onlyType) {
                return false;
            }

            let countH = 1;
            let tc = colP - 1;
            while (tc >= 0 && grid[rowP][tc] === type) {
                countH++;
                tc--;
            }
            tc = colP + 1;
            while (tc < cols && grid[rowP][tc] === type) {
                countH++;
                tc++;
            }
            if (countH >= 3) {
                return true;
            }

            let countV = 1;
            let tr = rowP - 1;
            while (tr >= 0 && grid[tr][colP] === type) {
                countV++;
                tr--;
            }
            tr = rowP + 1;
            while (tr < rows && grid[tr][colP] === type) {
                countV++;
                tr++;
            }
            return countV >= 3;
        }

        /**
         * Finds a valid swap that produces a match, optionally restricted to a piece type.
         *
         * @param {Array} grid The board grid.
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {number|null} onlyType When set, only returns a swap whose resulting match
         *  uses this piece type.
         * @return {{r1: number, c1: number, r2: number, c2: number}|null} The two cells to
         *  swap, or null when none exist.
         */
        function findMove(grid, rows, cols, onlyType) {
            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < cols; c++) {
                    if (c < cols - 1) {
                        swapInGrid(grid, r, c, r, c + 1);
                        const matchR = isMatchAt(grid, rows, cols, r, c, onlyType) ||
                            isMatchAt(grid, rows, cols, r, c + 1, onlyType);
                        swapInGrid(grid, r, c, r, c + 1);
                        if (matchR) {
                            return {r1: r, c1: c, r2: r, c2: c + 1};
                        }
                    }
                    if (r < rows - 1) {
                        swapInGrid(grid, r, c, r + 1, c);
                        const matchD = isMatchAt(grid, rows, cols, r, c, onlyType) ||
                            isMatchAt(grid, rows, cols, r + 1, c, onlyType);
                        swapInGrid(grid, r, c, r + 1, c);
                        if (matchD) {
                            return {r1: r, c1: c, r2: r + 1, c2: c};
                        }
                    }
                }
            }
            return null;
        }

        /**
         * Whether any valid match-producing swap exists anywhere on the board.
         *
         * @param {Array} grid The board grid.
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @return {boolean}
         */
        function hasAvailableMove(grid, rows, cols) {
            return findMove(grid, rows, cols, null) !== null;
        }

        /**
         * Whether any match already exists anywhere on the grid, regardless of type or
         * orientation — used to reject a shuffle result that happens to land on a match
         * (shuffleUntilValid()) the same way generateGrid()'s own cell-by-cell placement
         * avoids one from ever forming in the first place.
         *
         * @param {Array} grid The board grid.
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @return {boolean}
         */
        function hasAnyMatch(grid, rows, cols) {
            const toDestroy = [];
            const matchGroups = [];
            checkHorizontal(grid, rows, cols, toDestroy, matchGroups);
            checkVertical(grid, rows, cols, toDestroy, matchGroups);
            return toDestroy.length > 0;
        }

        /**
         * Fisher-Yates shuffle of every cell's type across the whole grid, in place — a
         * position-only shuffle (existing types are redistributed, none are re-rolled), unlike
         * generateGrid()'s per-cell random pick. Scans right to left, swapping each cell with a
         * uniformly-chosen earlier-or-equal one; this exact direction/convention must match
         * whatever a server-side port of this logic uses, or a replay consuming the same rng
         * sequence would land on a different arrangement despite agreeing on every draw.
         *
         * @param {Array} grid The board grid (mutated in place).
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {Function} rng Returns a float in [0, 1) — see pickTypeAvoidingMatch().
         * @return {void}
         */
        function shuffleGrid(grid, rows, cols, rng) {
            const flat = [];
            for (let row = 0; row < rows; row++) {
                for (let col = 0; col < cols; col++) {
                    flat.push(grid[row][col]);
                }
            }

            for (let i = flat.length - 1; i > 0; i--) {
                const j = Math.floor(rng() * (i + 1));
                const temp = flat[i];
                flat[i] = flat[j];
                flat[j] = temp;
            }

            let idx = 0;
            for (let row = 0; row < rows; row++) {
                for (let col = 0; col < cols; col++) {
                    grid[row][col] = flat[idx];
                    idx++;
                }
            }
        }

        /**
         * Re-shuffles the grid (in place) until the result both has at least one available move
         * and contains no match on its own — the same two conditions board.js's own shuffle()
         * always checked, now expressed as a pure retry loop instead of one entangled with
         * texture updates and tween timing.
         *
         * Consumes a variable number of rng draws per call (each failed attempt re-consumes a
         * fresh shuffle's worth) — the same kind of state-dependent consumption
         * pickTypeAvoidingMatch() already has, and for the same reason: a PHP replay must retry
         * with the identical acceptance test, or it will not consume rng draws in the same
         * count and everything after diverges.
         *
         * @param {Array} grid The board grid (mutated in place).
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {Function} rng Returns a float in [0, 1) — see pickTypeAvoidingMatch().
         * @return {void}
         */
        function shuffleUntilValid(grid, rows, cols, rng) {
            do {
                shuffleGrid(grid, rows, cols, rng);
            } while (!hasAvailableMove(grid, rows, cols) || hasAnyMatch(grid, rows, cols));
        }

        /**
         * Computes the length of the match line (horizontal or vertical) passing through the
         * given cell, for whichever piece type currently sits there. Same counting approach as
         * isMatchAt(), but returns the actual run length instead of a boolean, so evaluateSwap()
         * can report which piece type a candidate swap would match.
         *
         * @param {Array} grid The board grid.
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {number} rowP Row index.
         * @param {number} colP Column index.
         * @return {number} Length of the run at this cell, or 0 when no match exists.
         */
        function matchRunLengthAt(grid, rows, cols, rowP, colP) {
            const type = grid[rowP][colP];
            if (type === null) {
                return 0;
            }

            let countH = 1;
            let tc = colP - 1;
            while (tc >= 0 && grid[rowP][tc] === type) {
                countH++;
                tc--;
            }
            tc = colP + 1;
            while (tc < cols && grid[rowP][tc] === type) {
                countH++;
                tc++;
            }
            if (countH >= 3) {
                return countH;
            }

            let countV = 1;
            let tr = rowP - 1;
            while (tr >= 0 && grid[tr][colP] === type) {
                countV++;
                tr--;
            }
            tr = rowP + 1;
            while (tr < rows && grid[tr][colP] === type) {
                countV++;
                tr++;
            }
            return countV >= 3 ? countV : 0;
        }

        /**
         * Swaps two cells, checks whether either resulting cell matches, then reverts — used
         * only for the accessible turn-start announcement (board.js's own announceTurnStart()),
         * which needs the matched piece type, not just whether a match exists.
         *
         * @param {Array} grid The board grid (left unchanged on return).
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {number} r1 Row of the first cell.
         * @param {number} c1 Column of the first cell.
         * @param {number} r2 Row of the second cell.
         * @param {number} c2 Column of the second cell.
         * @return {number|null} The piece type that would match, or null when this swap has no
         *  effect.
         */
        function evaluateSwap(grid, rows, cols, r1, c1, r2, c2) {
            swapInGrid(grid, r1, c1, r2, c2);

            let matchedType = null;
            if (matchRunLengthAt(grid, rows, cols, r1, c1) >= 3) {
                matchedType = grid[r1][c1];
            } else if (matchRunLengthAt(grid, rows, cols, r2, c2) >= 3) {
                matchedType = grid[r2][c2];
            }

            swapInGrid(grid, r1, c1, r2, c2);
            return matchedType;
        }

        /**
         * Compacts each column downward (each empty cell pulls down the nearest non-empty
         * cell above it, if any) and refills whatever stays empty at the top with a freshly
         * rolled type — the two passes applyGravity() always ran, minus the Phaser tween/image
         * creation, which stays in board.js and uses the arrays this returns to know what to
         * animate.
         *
         * @param {Array} grid The board grid (mutated in place).
         * @param {number} rows Board row count.
         * @param {number} cols Board column count.
         * @param {Function} rng Returns a float in [0, 1) — see pickTypeAvoidingMatch().
         * @return {{fell: Array, spawned: Array}} fell: {fromRow, toRow, col} per cell that
         *  moved down. spawned: {row, col, type} per newly-created cell.
         */
        function applyGravityToGrid(grid, rows, cols, rng) {
            const fell = [];
            const spawned = [];

            for (let col = 0; col < cols; col++) {
                for (let row = rows - 1; row >= 0; row--) {
                    if (grid[row][col] !== null) {
                        continue;
                    }
                    for (let r = row - 1; r >= 0; r--) {
                        if (grid[r][col] !== null) {
                            grid[row][col] = grid[r][col];
                            grid[r][col] = null;
                            fell.push({fromRow: r, toRow: row, col});
                            break;
                        }
                    }
                }
            }

            for (let col = 0; col < cols; col++) {
                for (let row = 0; row < rows; row++) {
                    if (grid[row][col] !== null) {
                        continue;
                    }
                    const type = Math.floor(rng() * 7);
                    grid[row][col] = type;
                    spawned.push({row, col, type});
                }
            }

            return {fell, spawned};
        }

        return {
            swapInGrid,
            pickTypeAvoidingMatch,
            generateGrid,
            registerRun,
            checkHorizontal,
            checkVertical,
            isMatchAt,
            findMove,
            hasAvailableMove,
            hasAnyMatch,
            shuffleGrid,
            shuffleUntilValid,
            matchRunLengthAt,
            evaluateSwap,
            applyGravityToGrid,
        };
    });
}(typeof define === 'function' && define.amd ? define : function(deps, factory) {
    module.exports = factory();
}));
