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
 * Deterministic seeded PRNG (mulberry32) for PlayerPuzzle's server-side replay — a bit-for-bit
 * mirror of classes/local/engine/prng.php. Given the same seed, both sides must produce the
 * exact same sequence of floats, so a server-side replay of recorded moves reaches the same
 * board/combat outcome the client did.
 *
 * create(seed) returns a plain function with the same shape as Math.random() (no arguments,
 * returns a float in [0, 1)), so it plugs directly into the `rng` parameter every RNG-consuming
 * function in engine/board_rules.js already accepts (generateGrid(), applyGravityToGrid(),
 * pickTypeAvoidingMatch(), shuffleUntilValid()) — no call site there needs to change, only what
 * gets passed in.
 *
 * UMD-wrapped exactly like the other engine modules — the same file loads as an AMD module in
 * the browser and as a plain CommonJS module in Node, so it can run headless (e.g. to generate
 * golden vectors for a PHP port to match against), no Phaser or browser involved.
 *
 * @module     mod_playerpuzzle/engine/prng
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
/* eslint-disable no-bitwise */
// Bitwise operators are this whole file's reason to exist: mulberry32 is defined in terms of
// 32-bit integer XOR/shift/OR, and there is no way to mirror JavaScript's int32 wraparound
// semantics (which the PHP port also has to reproduce bit for bit) without them. The project's
// usual no-bitwise rule guards against accidental misuse of `&`/`|` for logical AND/OR in
// ordinary application code — it does not apply to a PRNG implementation.
(function(define) {
    define([], function() {
        'use strict';

        /**
         * Creates a seeded mulberry32 generator.
         *
         * Relies on JavaScript's native Math.imul() for the 32-bit-wraparound multiplications
         * mulberry32 needs — engines/browsers provide this natively, unlike PHP, where
         * prng.php has to reimplement it by hand (see that file's own docblock for why).
         *
         * @param {number} seed Any integer; coerced to an unsigned 32-bit value via `>>> 0`,
         *  same as PHP's `& 0xFFFFFFFF` on its own side.
         * @return {Function} A Math.random()-shaped function: no arguments, returns a float in
         *  [0, 1), deterministic for a given seed.
         */
        function create(seed) {
            let state = seed >>> 0;

            return function next() {
                state = (state + 0x6D2B79F5) | 0;
                let t = Math.imul(state ^ (state >>> 15), 1 | state);
                t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
                return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
            };
        }

        return {create};
    });
}(typeof define === 'function' && define.amd ? define : function(deps, factory) {
    module.exports = factory();
}));
