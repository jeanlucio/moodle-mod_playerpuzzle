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
 * Headless tests for the pure board logic, run via `node --test` — no RequireJS, no Phaser,
 * no browser at all. Loads amd/src/engine/board_rules.js directly through Node's CommonJS
 * require(), the branch its UMD wrapper takes outside a `define()` environment.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const boardRules = require('../../../amd/src/engine/board_rules.js');

/**
 * Builds a rows x cols grid filled with a single type, then overrides cells listed in
 * overrides — keeps each test's board declaration short and focused on what actually matters.
 *
 * @param {number} rows Row count.
 * @param {number} cols Column count.
 * @param {number} fill Default type for every cell.
 * @param {Object<string, number|null>} overrides Map of "row,col" to a type override.
 * @return {Array} The grid.
 */
function buildGrid(rows, cols, fill, overrides = {}) {
    const grid = [];
    for (let r = 0; r < rows; r++) {
        grid[r] = [];
        for (let c = 0; c < cols; c++) {
            const key = `${r},${c}`;
            grid[r][c] = Object.prototype.hasOwnProperty.call(overrides, key) ? overrides[key] : fill;
        }
    }
    return grid;
}

/**
 * A deterministic rng returning a fixed sequence, cycling once exhausted — lets a test force
 * pickTypeAvoidingMatch()/applyGravityToGrid() through a known set of rolls.
 *
 * @param {Array<number>} sequence Values in [0, 1) to return in order.
 * @return {Function} An rng function.
 */
function sequenceRng(sequence) {
    let i = 0;
    return () => {
        const value = sequence[i % sequence.length];
        i++;
        return value;
    };
}

test('checkHorizontal finds a run of exactly 3 and reports its cells', () => {
    const grid = buildGrid(4, 4, null, {'1,0': 2, '1,1': 2, '1,2': 2});
    const toDestroy = [];
    const matchGroups = [];
    boardRules.checkHorizontal(grid, 4, 4, toDestroy, matchGroups);
    assert.equal(toDestroy.length, 3);
    assert.deepEqual(matchGroups, [{type: 2, cells: [{row: 1, col: 0}, {row: 1, col: 1}, {row: 1, col: 2}]}]);
});

test('checkHorizontal finds a run of 5, not just the minimum 3', () => {
    const grid = buildGrid(1, 5, 4);
    const toDestroy = [];
    const matchGroups = [];
    boardRules.checkHorizontal(grid, 1, 5, toDestroy, matchGroups);
    assert.equal(toDestroy.length, 5);
    assert.equal(matchGroups[0].cells.length, 5);
});

test('checkHorizontal ignores a run of only 2', () => {
    const grid = buildGrid(1, 4, null, {'0,0': 1, '0,1': 1});
    const toDestroy = [];
    const matchGroups = [];
    boardRules.checkHorizontal(grid, 1, 4, toDestroy, matchGroups);
    assert.equal(toDestroy.length, 0);
    assert.equal(matchGroups.length, 0);
});

test('checkHorizontal treats type 0 as a real value, never as an empty cell', () => {
    const grid = buildGrid(1, 4, null, {'0,0': 0, '0,1': 0, '0,2': 0});
    const toDestroy = [];
    const matchGroups = [];
    boardRules.checkHorizontal(grid, 1, 4, toDestroy, matchGroups);
    assert.equal(toDestroy.length, 3, 'a run of type 0 must match exactly like any other type');
});

test('checkVertical finds a vertical run', () => {
    const grid = buildGrid(4, 1, null, {'0,0': 3, '1,0': 3, '2,0': 3});
    const toDestroy = [];
    const matchGroups = [];
    boardRules.checkVertical(grid, 4, 1, toDestroy, matchGroups);
    assert.equal(toDestroy.length, 3);
    assert.equal(matchGroups[0].type, 3);
});

test('checkHorizontal and checkVertical dedupe a shared intersection cell in toDestroy', () => {
    // An L-shape: (0,0)-(0,1)-(0,2) horizontal, (0,0)-(1,0)-(2,0) vertical, sharing (0,0).
    const grid = buildGrid(3, 3, null, {
        '0,0': 5, '0,1': 5, '0,2': 5,
        '1,0': 5, '2,0': 5,
    });
    const toDestroy = [];
    const matchGroups = [];
    boardRules.checkHorizontal(grid, 3, 3, toDestroy, matchGroups);
    boardRules.checkVertical(grid, 3, 3, toDestroy, matchGroups);
    assert.equal(toDestroy.length, 5, '(0,0) must appear once, not twice');
    assert.equal(matchGroups.length, 2, 'two separate match groups still exist for combo-size effects');
});

test('isMatchAt is false for an empty (null) cell', () => {
    const grid = buildGrid(3, 3, null);
    assert.equal(boardRules.isMatchAt(grid, 3, 3, 1, 1, null), false);
});

test('isMatchAt respects the onlyType filter', () => {
    const grid = buildGrid(1, 3, 2);
    assert.equal(boardRules.isMatchAt(grid, 1, 3, 0, 1, 2), true);
    assert.equal(boardRules.isMatchAt(grid, 1, 3, 0, 1, 9), false);
});

test('matchRunLengthAt returns 0 when there is no match', () => {
    const grid = buildGrid(3, 3, null, {'1,1': 1});
    assert.equal(boardRules.matchRunLengthAt(grid, 3, 3, 1, 1), 0);
});

test('matchRunLengthAt returns the real run length, not just true/false', () => {
    const grid = buildGrid(1, 4, 6);
    assert.equal(boardRules.matchRunLengthAt(grid, 1, 4, 0, 0), 4);
});

test('swapInGrid exchanges two cells and is its own inverse', () => {
    const grid = buildGrid(2, 2, null, {'0,0': 1, '0,1': 2});
    boardRules.swapInGrid(grid, 0, 0, 0, 1);
    assert.equal(grid[0][0], 2);
    assert.equal(grid[0][1], 1);
    boardRules.swapInGrid(grid, 0, 0, 0, 1);
    assert.equal(grid[0][0], 1);
    assert.equal(grid[0][1], 2);
});

test('evaluateSwap reports the matched type and leaves the grid unchanged', () => {
    // Swapping (0,3) into (0,2) completes a horizontal run of type 7 at row 0.
    const grid = buildGrid(1, 4, null, {'0,0': 7, '0,1': 7, '0,2': 9, '0,3': 7});
    const matched = boardRules.evaluateSwap(grid, 1, 4, 0, 2, 0, 3);
    assert.equal(matched, 7);
    assert.equal(grid[0][2], 9, 'grid must be reverted after evaluateSwap');
    assert.equal(grid[0][3], 7, 'grid must be reverted after evaluateSwap');
});

test('evaluateSwap returns null when the swap produces no match', () => {
    const grid = buildGrid(2, 2, null, {'0,0': 1, '0,1': 2, '1,0': 3, '1,1': 4});
    assert.equal(boardRules.evaluateSwap(grid, 2, 2, 0, 0, 0, 1), null);
});

test('findMove finds a swap that produces a match', () => {
    // (0,0)=1,(0,1)=1 and (1,0)=2 — swapping (0,1) and (1,1)... simpler: craft an obvious one.
    const grid = buildGrid(2, 3, null, {'0,0': 5, '0,1': 5, '1,2': 5, '0,2': 1});
    const move = boardRules.findMove(grid, 2, 3, null);
    assert.notEqual(move, null);
    // Confirm the reported move genuinely produces a match once actually applied.
    boardRules.swapInGrid(grid, move.r1, move.c1, move.r2, move.c2);
    const toDestroy = [];
    boardRules.checkHorizontal(grid, 2, 3, toDestroy, []);
    boardRules.checkVertical(grid, 2, 3, toDestroy, []);
    assert.ok(toDestroy.length >= 3);
});

test('findMove returns null and hasAvailableMove is false on a board with no valid swap', () => {
    // A 2x2 board can never contain a run of 3 in either direction, regardless of which
    // types sit where or which adjacent pair gets swapped — geometrically impossible, not
    // just unlikely, so this is a reliable "definitely no move" fixture.
    const grid = buildGrid(2, 2, null, {'0,0': 1, '0,1': 2, '1,0': 3, '1,1': 4});
    assert.equal(boardRules.findMove(grid, 2, 2, null), null);
    assert.equal(boardRules.hasAvailableMove(grid, 2, 2), false);
});

test('pickTypeAvoidingMatch never completes a 3-in-a-row above or to the left', () => {
    // Rng sequence forces types 2,2,2,... at first, which WOULD match at (2,0) against the two
    // cells above it (both already 2) if the anti-match re-roll did not kick in — the next
    // value in the sequence (a different type) must be the one actually used instead.
    const grid = buildGrid(3, 1, null, {'0,0': 2, '1,0': 2});
    const rng = sequenceRng([2 / 7, 3 / 7]);
    const picked = boardRules.pickTypeAvoidingMatch(grid, 2, 0, rng);
    assert.notEqual(picked, 2, 'must not pick the type that would complete the vertical run');
});

test('generateGrid replays a saved grid verbatim instead of rolling new types', () => {
    const saved = [1, 2, 3, 4];
    const grid = boardRules.generateGrid(2, 2, saved, () => 0);
    assert.deepEqual(grid, [[1, 2], [3, 4]]);
});

test('generateGrid never produces an initial 3-in-a-row anywhere on the board', () => {
    // A real-looking pseudo-random sequence, long enough to cover an 8x8 board with re-rolls.
    let seed = 42;
    const rng = () => {
        seed = (seed * 1103515245 + 12345) % 0x7fffffff;
        return Math.abs(seed) / 0x7fffffff;
    };
    const grid = boardRules.generateGrid(8, 8, null, rng);
    const toDestroy = [];
    boardRules.checkHorizontal(grid, 8, 8, toDestroy, []);
    boardRules.checkVertical(grid, 8, 8, toDestroy, []);
    assert.equal(toDestroy.length, 0, 'a freshly generated board must never start with a match');
});

test('applyGravityToGrid drops a floating piece to the bottom of its column', () => {
    // Only one real piece in a 3-cell column: it falls to the very bottom, and BOTH cells
    // above it end up empty afterwards — not just the one it started in.
    const grid = buildGrid(3, 1, null, {'0,0': 5});
    const result = boardRules.applyGravityToGrid(grid, 3, 1, () => 0);
    assert.equal(grid[2][0], 5, 'the piece must fall to the lowest empty row');
    assert.equal(grid[0][0], 0, 'the vacated top cell must be refilled');
    assert.equal(grid[1][0], 0, 'the vacated middle cell must be refilled too');
    assert.deepEqual(result.fell, [{fromRow: 0, toRow: 2, col: 0}]);
    assert.deepEqual(result.spawned, [{row: 0, col: 0, type: 0}, {row: 1, col: 0, type: 0}]);
});

test('applyGravityToGrid leaves an already-full column untouched', () => {
    const grid = buildGrid(2, 1, 3);
    const result = boardRules.applyGravityToGrid(grid, 2, 1, () => 1);
    assert.deepEqual(grid, [[3], [3]]);
    assert.deepEqual(result.fell, []);
    assert.deepEqual(result.spawned, []);
});

test('applyGravityToGrid spawns new pieces using the injected rng, never Math.random directly', () => {
    const grid = buildGrid(1, 1, null);
    const result = boardRules.applyGravityToGrid(grid, 1, 1, () => 6 / 7);
    assert.equal(grid[0][0], 6);
    assert.equal(result.spawned[0].type, 6);
});
