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
 * @covers \mod_playerpuzzle\local\opponents
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

    /**
     * Tests the difficulty factor lookups, including the fallback for a malformed value.
     *
     * @return void
     */
    public function test_difficulty_factors(): void {
        $this->assertSame(0.5, combat::difficulty_boss_factor('easy'));
        $this->assertSame(1.0, combat::difficulty_boss_factor('normal'));
        $this->assertSame(2.0, combat::difficulty_boss_factor('hard'));
        $this->assertSame(1.0, combat::difficulty_boss_factor('bogus'));

        $this->assertSame(0.5, combat::difficulty_coin_factor('easy'));
        $this->assertSame(1.0, combat::difficulty_coin_factor('normal'));
        $this->assertSame(3.0, combat::difficulty_coin_factor('hard'));
        $this->assertSame(1.0, combat::difficulty_coin_factor('bogus'));
    }

    /**
     * Tests apply_difficulty() scales an already level/phase-scaled value and rounds to
     * an integer, and that Normal is a no-op.
     *
     * @return void
     */
    public function test_apply_difficulty(): void {
        $this->assertSame(150, combat::apply_difficulty(300, 'easy'));
        $this->assertSame(300, combat::apply_difficulty(300, 'normal'));
        $this->assertSame(600, combat::apply_difficulty(300, 'hard'));
        // 25 * 0.5 = 12.5 -> rounds to 13.
        $this->assertSame(13, combat::apply_difficulty(25, 'easy'));
        $this->assertSame(300, combat::apply_difficulty(300, 'bogus'));
    }

    /**
     * Tests the boss guess precision table: every difficulty/qtype cell, plus the fallback
     * to the Normal/multichoice cell for unknown values.
     *
     * @return void
     */
    public function test_boss_guess_probability(): void {
        $this->assertSame(0.33, combat::boss_guess_probability('easy', 'multichoice'));
        $this->assertSame(0.5, combat::boss_guess_probability('easy', 'truefalse'));
        $this->assertSame(0.66, combat::boss_guess_probability('normal', 'multichoice'));
        $this->assertSame(0.75, combat::boss_guess_probability('normal', 'truefalse'));
        $this->assertSame(1.0, combat::boss_guess_probability('hard', 'multichoice'));
        $this->assertSame(1.0, combat::boss_guess_probability('hard', 'truefalse'));

        // Unknown difficulty falls back to the Normal row; unknown qtype to multichoice.
        $this->assertSame(0.66, combat::boss_guess_probability('bogus', 'multichoice'));
        $this->assertSame(0.66, combat::boss_guess_probability('normal', 'shortanswer'));
    }

    /**
     * Tests the fixed shop prices for every consumable type, and the fallback for an
     * unknown type.
     *
     * @return void
     */
    public function test_consumable_price(): void {
        $this->assertSame(8, combat::consumable_price('potion'));
        $this->assertSame(10, combat::consumable_price('shield'));
        $this->assertSame(12, combat::consumable_price('magic'));
        $this->assertSame(10, combat::consumable_price('sword'));
        $this->assertSame(0, combat::consumable_price('bogus'));
    }

    /**
     * Tests the fixed Demo match HP constant — a Demo always fights at this
     * exact value, never derived from any instance config.
     *
     * @return void
     */
    public function test_demo_hp_is_fixed_at_fifty(): void {
        $this->assertSame(50, combat::DEMO_HP);
    }

    /**
     * Builds an attempt row with the fields phase_opponent() reads, overridable per test.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    private function make_attempt(array $overrides = []): \stdClass {
        return (object) array_merge([
            'isdemo' => 0,
            'difficulty' => 'normal',
            'currentlevel' => 1,
            'currentphase' => 1,
            'frozenbasebosshp' => 100,
            'frozenbossdamage' => 10,
            'frozenopponent' => 'goblin',
        ], $overrides);
    }

    /**
     * Tests that phase_opponent() scales the frozen values by level, phase and difficulty, and
     * passes on the attack and armor of the frozen opponent's profile.
     *
     * @return void
     */
    public function test_phase_opponent_scales_the_frozen_values_and_applies_the_profile(): void {
        $instance = (object) ['basebosshp' => 900, 'bossdamage' => 90];

        $brute = combat::phase_opponent($this->make_attempt([
            'currentlevel' => 2, 'currentphase' => 3, 'difficulty' => 'hard',
        ]), $instance);
        $this->assertSame(
            ['maxbosshp' => 340, 'basedamage' => 34, 'bossattack' => 1.25, 'bossarmor' => 0.0, 'bosspowers' => []],
            $brute
        );

        $armored = combat::phase_opponent($this->make_attempt(['frozenopponent' => 'golem']), $instance);
        $this->assertSame(
            ['maxbosshp' => 100, 'basedamage' => 10, 'bossattack' => 1.0, 'bossarmor' => 0.25, 'bosspowers' => ['iceshield']],
            $armored
        );
    }

    /**
     * Tests that a Resistente's extra HP is applied last, rounding halves up.
     *
     * @return void
     */
    public function test_phase_opponent_gives_a_tough_opponent_more_hp(): void {
        $instance = (object) ['basebosshp' => 900, 'bossdamage' => 90];

        $tough = combat::phase_opponent($this->make_attempt([
            'frozenopponent' => 'slime', 'frozenbasebosshp' => 175,
        ]), $instance);
        $this->assertSame(228, $tough['maxbosshp'], '175 x 1.3 = 227.5, rounded up');
        $this->assertSame(10, $tough['basedamage'], 'the profile does not touch the base damage');
    }

    /**
     * Tests that an attempt row from before the frozen columns falls back to the instance, and
     * that one that never had its opponent frozen fights with no profile.
     *
     * @return void
     */
    public function test_phase_opponent_falls_back_to_the_instance_and_to_no_profile(): void {
        $instance = (object) ['basebosshp' => 200, 'bossdamage' => 20];

        $old = combat::phase_opponent($this->make_attempt([
            'frozenbasebosshp' => 0, 'frozenbossdamage' => 0, 'frozenopponent' => '',
        ]), $instance);
        $this->assertSame(
            ['maxbosshp' => 200, 'basedamage' => 20, 'bossattack' => 1.0, 'bossarmor' => 0.0, 'bosspowers' => []],
            $old
        );
    }

    /**
     * Tests that a Demo fights at the fixed HP with no profile, no powers and no difficulty.
     *
     * @return void
     */
    public function test_phase_opponent_of_a_demo_is_fixed_and_has_no_profile(): void {
        $instance = (object) ['basebosshp' => 900, 'bossdamage' => 12];

        $demo = combat::phase_opponent($this->make_attempt([
            'isdemo' => 1, 'difficulty' => 'hard', 'frozenopponent' => 'golem',
        ]), $instance);
        $this->assertSame(
            ['maxbosshp' => combat::DEMO_HP, 'basedamage' => 12, 'bossattack' => 1.0, 'bossarmor' => 0.0, 'bosspowers' => []],
            $demo
        );
    }
}
