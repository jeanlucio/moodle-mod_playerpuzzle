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
 * Unit tests for the combat HP scaling engine.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Tests for combat.
 *
 * @covers \mod_playerpuzzle\local\engine\combat
 */
final class combat_test extends \basic_testcase {
    /**
     * Tests calculate_boss_hp() against every value in the documented worked-example
     * table (base_boss_hp = 100), a direct regression guard against the formula ever
     * silently drifting.
     *
     * @return void
     */
    public function test_calculate_boss_hp_matches_worked_examples(): void {
        $this->assertSame(100, combat::calculate_boss_hp(100, 1, 1));
        $this->assertSame(140, combat::calculate_boss_hp(100, 1, 5));
        $this->assertSame(190, combat::calculate_boss_hp(100, 1, 10));
        $this->assertSame(300, combat::calculate_boss_hp(100, 5, 1));
        $this->assertSame(340, combat::calculate_boss_hp(100, 5, 5));
        $this->assertSame(390, combat::calculate_boss_hp(100, 5, 10));
        $this->assertSame(550, combat::calculate_boss_hp(100, 10, 1));
        $this->assertSame(590, combat::calculate_boss_hp(100, 10, 5));
        $this->assertSame(640, combat::calculate_boss_hp(100, 10, 10));
    }

    /**
     * Tests calculate_student_hp() against every value in the same worked example table.
     *
     * @return void
     */
    public function test_calculate_student_hp_matches_worked_examples(): void {
        $this->assertSame(100, combat::calculate_student_hp(100, 1, 1));
        $this->assertSame(120, combat::calculate_student_hp(100, 1, 5));
        $this->assertSame(145, combat::calculate_student_hp(100, 1, 10));
        $this->assertSame(220, combat::calculate_student_hp(100, 5, 1));
        $this->assertSame(240, combat::calculate_student_hp(100, 5, 5));
        $this->assertSame(265, combat::calculate_student_hp(100, 5, 10));
        $this->assertSame(370, combat::calculate_student_hp(100, 10, 1));
        $this->assertSame(390, combat::calculate_student_hp(100, 10, 5));
        $this->assertSame(415, combat::calculate_student_hp(100, 10, 10));
    }

    /**
     * Tests that Level 1, Phase 1 (Single Match mode's fixed currentlevel/currentphase)
     * always returns the base HP unchanged, for any base value — the property that lets
     * callers use these formulas unconditionally without branching on gamemode.
     *
     * @return void
     */
    public function test_level_one_phase_one_returns_base_hp_unchanged(): void {
        $this->assertSame(1000, combat::calculate_boss_hp(1000, 1, 1));
        $this->assertSame(250, combat::calculate_student_hp(250, 1, 1));
    }

    /**
     * Tests that a zero base HP scales to zero regardless of level/phase — a
     * multiplicative formula has no other sane behaviour for this edge case.
     *
     * @return void
     */
    public function test_zero_base_hp_scales_to_zero(): void {
        $this->assertSame(0, combat::calculate_boss_hp(0, 10, 10));
        $this->assertSame(0, combat::calculate_student_hp(0, 10, 10));
    }
}
