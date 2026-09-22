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
 * Unit tests for the deterministic seeded PRNG.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Tests for prng.
 *
 * @covers \mod_playerpuzzle\local\engine\prng
 */
final class prng_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that the same seed always produces the same sequence.
     *
     * @return void
     */
    public function test_same_seed_produces_same_sequence(): void {
        $first = prng::create(42);
        $second = prng::create(42);
        $firstvalues = array_map(fn() => $first(), range(1, 5));
        $secondvalues = array_map(fn() => $second(), range(1, 5));
        $this->assertSame($firstvalues, $secondvalues);
    }

    /**
     * Tests that a known seed produces the exact sequence documented (and cross-checked
     * against the JS side) in tests/js/engine/prng.test.js — the whole point of this class is
     * that both sides agree bit for bit on the same seed.
     *
     * @return void
     */
    public function test_known_seed_matches_the_js_side_exactly(): void {
        $next = prng::create(42);
        $values = array_map(fn() => $next(), range(1, 5));
        $this->assertSame([
            0.6011037519201636,
            0.44829055899754167,
            0.8524657934904099,
            0.6697340414393693,
            0.17481389874592423,
        ], $values);
    }

    /**
     * Tests that different seeds produce different sequences.
     *
     * @return void
     */
    public function test_different_seeds_produce_different_sequences(): void {
        $a = (prng::create(1))();
        $b = (prng::create(2))();
        $this->assertNotEquals($a, $b);
    }

    /**
     * Tests that every draw is a float in [0, 1).
     *
     * @return void
     */
    public function test_every_draw_is_in_unit_range(): void {
        $next = prng::create(123456789);
        for ($i = 0; $i < 200; $i++) {
            $value = $next();
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThan(1, $value);
        }
    }

    /**
     * Tests that a seed outside the 32-bit range is masked the same way the JS side's
     * `>>> 0` coercion wraps it.
     *
     * @return void
     */
    public function test_seed_outside_32_bit_range_wraps_like_the_js_side(): void {
        $wrapped = (prng::create(4294967296))();
        $zero = (prng::create(0))();
        $this->assertSame($zero, $wrapped);
    }
}
