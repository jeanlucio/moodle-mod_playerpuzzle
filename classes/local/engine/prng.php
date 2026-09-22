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
 * Deterministic seeded PRNG for the anti-cheat replay engine.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Deterministic seeded PRNG (mulberry32), a bit-for-bit mirror of amd/src/engine/prng.js —
 * given the same seed, both sides must produce the exact same sequence of floats, so a
 * server-side replay of recorded moves reaches the same board/combat outcome the client did.
 *
 * Every intermediate value is masked to 0-0xFFFFFFFF after each operation, standing in for
 * JavaScript's native 32-bit integer semantics: a PHP int is 64 bits on this architecture, so
 * without the explicit mask, values would keep growing instead of wrapping around like a real
 * uint32 does, and the two sides would silently diverge.
 */
class prng {
    /**
     * Creates a seeded mulberry32 generator.
     *
     * @param int $seed Any integer; masked to an unsigned 32-bit value, same as
     *  amd/src/engine/prng.js's `>>> 0` coercion on its own side.
     * @return \Closure A no-argument closure returning a float in [0, 1), deterministic for a
     *  given seed — the same shape prng.js::create() returns.
     */
    public static function create(int $seed): \Closure {
        $state = $seed & 0xFFFFFFFF;

        return function () use (&$state): float {
            $state = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
            $a = $state;
            $t = self::imul32($a ^ ($a >> 15), 1 | $a);
            $t = ((($t + self::imul32($t ^ ($t >> 7), 61 | $t)) & 0xFFFFFFFF) ^ $t) & 0xFFFFFFFF;
            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296.0;
        };
    }

    /**
     * 32-bit integer multiplication with wraparound, mirroring JavaScript's Math.imul() bit
     * for bit. Splits each operand into 16-bit halves and combines them (the same technique
     * Math.imul() itself is defined by) rather than multiplying the two 32-bit operands
     * directly: two near-maximum uint32 values (up to 0xFFFFFFFF each) multiplied directly can
     * exceed PHP_INT_MAX on a 64-bit build, silently promoting the result to a float and losing
     * the low-order bits this algorithm depends on. Every intermediate sum here stays small
     * enough to never risk that.
     *
     * @param int $a First operand, already masked to 0-0xFFFFFFFF by the caller.
     * @param int $b Second operand, already masked to 0-0xFFFFFFFF by the caller.
     * @return int The low 32 bits of $a * $b, masked to 0-0xFFFFFFFF.
     */
    private static function imul32(int $a, int $b): int {
        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;

        $result = ($al * $bl) + ((($ah * $bl + $al * $bh) & 0xFFFF) << 16);
        return $result & 0xFFFFFFFF;
    }
}
