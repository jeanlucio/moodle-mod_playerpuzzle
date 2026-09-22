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
 * Headless tests for the deterministic seeded PRNG, run via `node --test` — no RequireJS, no
 * Phaser, no browser at all. Loads amd/src/engine/prng.js directly through Node's CommonJS
 * require(), the branch its UMD wrapper takes outside a `define()` environment.
 *
 * The expected sequences below were cross-checked by hand against classes/local/engine/prng.php
 * for the same seeds (both sides produced bit-for-bit identical output) before being hardcoded
 * here — this file only proves the JS side stays put; a PHPUnit test asserting the two sides
 * agree belongs alongside whatever server-side code first consumes prng.php for real.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const prng = require('../../../amd/src/engine/prng.js');

test('the same seed always produces the same sequence', () => {
    const first = prng.create(42);
    const second = prng.create(42);
    const firstValues = Array.from({length: 5}, () => first());
    const secondValues = Array.from({length: 5}, () => second());
    assert.deepEqual(firstValues, secondValues);
});

test('a known seed produces the exact documented sequence', () => {
    // Locks in the algorithm bit for bit — any future change to create() (a different
    // constant, a different shift amount) would silently desynchronise every live attempt's
    // saved seed from what the server replays, so this failing is the intended trip wire.
    const next = prng.create(42);
    const values = Array.from({length: 5}, () => next());
    assert.deepEqual(values, [
        0.6011037519201636,
        0.44829055899754167,
        0.8524657934904099,
        0.6697340414393693,
        0.17481389874592423,
    ]);
});

test('different seeds produce different sequences', () => {
    const a = prng.create(1)();
    const b = prng.create(2)();
    assert.notEqual(a, b);
});

test('every draw is a float in [0, 1)', () => {
    const next = prng.create(123456789);
    for (let i = 0; i < 200; i++) {
        const value = next();
        assert.ok(value >= 0 && value < 1, `draw ${i} (${value}) must be in [0, 1)`);
    }
});

test('a seed outside the 32-bit range is coerced the same way `>>> 0` would', () => {
    // 4294967296 (2^32) wraps to 0 under `>>> 0`, same as JavaScript's own ToUint32 coercion —
    // and the same wraparound classes/local/engine/prng.php performs via `& 0xFFFFFFFF`.
    const wrapped = prng.create(4294967296)();
    const zero = prng.create(0)();
    assert.equal(wrapped, zero);
});
