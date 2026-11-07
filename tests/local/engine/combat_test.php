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
     * Tests that Level 1, Phase 1 (Single Match mode's fixed currentlevel/currentphase)
     * always returns the base HP unchanged, for any base value — the property that lets
     * callers use these formulas unconditionally without branching on gamemode.
     *
     * @return void
     */
    public function test_level_one_phase_one_returns_base_hp_unchanged(): void {
        $this->assertSame(1000, combat::calculate_boss_hp(1000, 1, 1));
    }

    /**
     * Tests that a zero base HP scales to zero regardless of level/phase — a
     * multiplicative formula has no other sane behaviour for this edge case.
     *
     * @return void
     */
    public function test_zero_base_hp_scales_to_zero(): void {
        $this->assertSame(0, combat::calculate_boss_hp(0, 10, 10));
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
     * Tests the factors that differ by side: the opponent's HP, and the unit of the student's own amounts, which
     * Easy leaves alone (so halving the opponent's HP shortens the match) and Hard doubles with the opponent's.
     *
     * @return void
     */
    public function test_difficulty_factors_by_side(): void {
        $this->assertSame([0.5, 1.0, 2.0, 1.0], [
            combat::difficulty_hp_factor('easy'),
            combat::difficulty_hp_factor('normal'),
            combat::difficulty_hp_factor('hard'),
            combat::difficulty_hp_factor('bogus'),
        ]);
        $this->assertSame([1.0, 1.0, 2.0, 1.0], [
            combat::difficulty_player_factor('easy'),
            combat::difficulty_player_factor('normal'),
            combat::difficulty_player_factor('hard'),
            combat::difficulty_player_factor('bogus'),
        ]);
    }

    /**
     * Tests that on Easy the opponent has half the HP and hits half as hard while the student's unit is the
     * unscaled one, and that Normal and Hard keep one unit for both sides.
     *
     * @return void
     */
    public function test_the_student_unit_is_not_weakened_on_easy(): void {
        $instance = (object) ['basebosshp' => 100, 'bossdamage' => 10];
        $attempt = fn(string $difficulty): \stdClass => (object) [
            'isdemo' => 0, 'difficulty' => $difficulty, 'currentlevel' => 1, 'currentphase' => 1,
            'frozenbasebosshp' => 100, 'frozenbossdamage' => 10, 'frozenopponent' => '', 'frozenhero' => '',
        ];

        $easy = combat::phase_opponent($attempt('easy'), $instance);
        $this->assertSame([50, 5, 10], [$easy['maxbosshp'], $easy['basedamage'], $easy['playerbasedamage']]);
        $normal = combat::phase_opponent($attempt('normal'), $instance);
        $this->assertSame([100, 10, 10], [$normal['maxbosshp'], $normal['basedamage'], $normal['playerbasedamage']]);
        $hard = combat::phase_opponent($attempt('hard'), $instance);
        $this->assertSame([200, 20, 20], [$hard['maxbosshp'], $hard['basedamage'], $hard['playerbasedamage']]);
    }

    /**
     * Tests that the final boss has 15% more HP on Normal only, and that no other opponent does: a Normal
     * phase against anything else keeps the HP the level and phase give.
     *
     * @return void
     */
    public function test_the_final_boss_has_more_hp_on_normal_only(): void {
        $instance = (object) ['basebosshp' => 100, 'bossdamage' => 10];
        $attempt = fn(string $difficulty, string $opponent): \stdClass => (object) [
            'isdemo' => 0, 'difficulty' => $difficulty, 'currentlevel' => 1, 'currentphase' => 10,
            'frozenbasebosshp' => 100, 'frozenbossdamage' => 10, 'frozenopponent' => $opponent, 'frozenhero' => '',
        ];

        // 100 * 1.9 for the tenth phase, then the final boss's 1.2 and, on Normal only, the 1.15.
        $this->assertSame(262, combat::phase_opponent($attempt('normal', 'vampire'), $instance)['maxbosshp']);
        $this->assertSame(114, combat::phase_opponent($attempt('easy', 'vampire'), $instance)['maxbosshp']);
        $this->assertSame(456, combat::phase_opponent($attempt('hard', 'vampire'), $instance)['maxbosshp']);
        $this->assertSame(190, combat::phase_opponent($attempt('normal', 'dragon'), $instance)['maxbosshp']);
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
        $this->assertSame(100, combat::DEMO_HP);
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
            [
                'maxbosshp' => 340, 'basedamage' => 34, 'playerbasedamage' => 34, 'bossattack' => 1.15, 'bossarmor' => 0.0,
                'bosspowers' => ['ambush'],
                'bossbreathevery' => 0, 'bosskit' => ['potion' => 1, 'sword' => 1],
            ],
            $brute
        );

        $armored = combat::phase_opponent($this->make_attempt(['frozenopponent' => 'golem']), $instance);
        $this->assertSame(
            [
                'maxbosshp' => 100, 'basedamage' => 10, 'playerbasedamage' => 10, 'bossattack' => 1.0, 'bossarmor' => 0.15,
                'bosspowers' => ['iceshield'],
                'bossbreathevery' => 0, 'bosskit' => ['potion' => 0, 'sword' => 0],
            ],
            $armored
        );
    }

    /**
     * Tests that a Normal opponent hits harder the further into the Campaign the phase is, from nothing at the
     * first phase to 60% more at the last, and that the other difficulties are left alone. The same cases are
     * pinned in replay_mirror.test.js against the JavaScript mirror.
     *
     * @return void
     */
    public function test_scale_attack_grows_over_the_campaign_on_normal_only(): void {
        $this->assertSame(1.0, combat::scale_attack(1.0, 'normal', 1, 1, 1));
        $this->assertSame(1.267, combat::scale_attack(1.0, 'normal', 1, 5, 1));
        $this->assertSame(1.6, combat::scale_attack(1.0, 'normal', 1, 10, 1));
        $this->assertSame(1.483, combat::scale_attack(1.15, 'normal', 2, 5, 3));
        $this->assertSame(1.6, combat::scale_attack(1.0, 'normal', 10, 10, 10));
        // A Campaign of no levels counts as one, and the progress never passes the end.
        $this->assertSame(1.0, combat::scale_attack(1.0, 'normal', 1, 1, 0));
        $this->assertSame(1.6, combat::scale_attack(1.0, 'normal', 3, 10, 1));
        $this->assertSame(1.15, combat::scale_attack(1.15, 'hard', 1, 10, 1));
        $this->assertSame(1.0, combat::scale_attack(1.0, 'easy', 1, 10, 1));
    }

    /**
     * Tests that phase_opponent() applies the growing attack from the instance's level count, and not in a Demo.
     *
     * @return void
     */
    public function test_phase_opponent_applies_the_growing_attack(): void {
        $instance = (object) ['basebosshp' => 100, 'bossdamage' => 10, 'maxlevels' => 1];

        $last = combat::phase_opponent($this->make_attempt(['currentphase' => 10, 'frozenopponent' => 'slime']), $instance);
        $this->assertSame(1.6, $last['bossattack']);

        $demo = combat::phase_opponent($this->make_attempt(['currentphase' => 10, 'isdemo' => 1]), $instance);
        $this->assertSame(1.0, $demo['bossattack']);
    }

    /**
     * Tests that a tough opponent's extra HP is applied last, rounding halves up.
     *
     * @return void
     */
    public function test_phase_opponent_gives_a_tough_opponent_more_hp(): void {
        $instance = (object) ['basebosshp' => 900, 'bossdamage' => 90];

        $tough = combat::phase_opponent($this->make_attempt([
            'frozenopponent' => 'spider', 'frozenbasebosshp' => 150,
        ]), $instance);
        $this->assertSame(173, $tough['maxbosshp'], '150 x 1.15 = 172.5, rounded up');
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
            [
                'maxbosshp' => 200, 'basedamage' => 20, 'playerbasedamage' => 20, 'bossattack' => 1.0, 'bossarmor' => 0.0,
                'bosspowers' => [],
                'bossbreathevery' => 0,
                'bosskit' => ['potion' => 0, 'sword' => 0],
            ],
            $old
        );
    }

    /**
     * Tests that phase_student() reads what the phase froze, and that an attempt from before the
     * student's side was frozen falls back to the instance's base HP with no attack or armor.
     *
     * @return void
     */
    public function test_phase_student_reads_the_frozen_side_and_falls_back_to_the_instance(): void {
        $instance = (object) ['basestudenthp' => 100];

        $frozen = combat::phase_student($this->make_attempt([
            'frozenplayerhp' => 240, 'frozenplayerattack' => '1.150', 'frozenplayerarmor' => '0.250',
            'currentlevel' => 3, 'currentphase' => 4,
        ]), $instance);
        $this->assertSame(['maxhp' => 240, 'attack' => 1.15, 'armor' => 0.25], $frozen);

        $legacy = combat::phase_student($this->make_attempt([
            'frozenplayerhp' => 0, 'frozenplayerattack' => '3.000', 'frozenplayerarmor' => '0.500',
            'currentlevel' => 2, 'currentphase' => 6,
        ]), $instance);
        $this->assertSame(['maxhp' => 100, 'attack' => 1.0, 'armor' => 0.0], $legacy, 'no growth with the level');
    }

    /**
     * Tests that phase_opponent() gives the final boss the second power its student's hero calls for, on top of
     * its drain and its extra HP, and gives every other opponent nothing of the kind.
     *
     * @return void
     */
    public function test_phase_opponent_gives_the_final_boss_a_power_against_the_hero(): void {
        $instance = (object) ['basebosshp' => 100, 'bossdamage' => 10];
        $boss = fn(string $hero) => combat::phase_opponent(
            $this->make_attempt(['frozenopponent' => 'vampire', 'frozenhero' => $hero]),
            $instance
        );

        $warrior = $boss('warriorf3');
        $this->assertSame(
            [0.15, [combat_engine::POWER_DRAIN], 0],
            [$warrior['bossarmor'], $warrior['bosspowers'], $warrior['bossbreathevery']]
        );
        $archer = $boss('archerm1');
        $this->assertSame(
            [0.0, [combat_engine::POWER_DRAIN, combat_engine::POWER_FASTGRIMOIRE], 0],
            [$archer['bossarmor'], $archer['bosspowers'], $archer['bossbreathevery']]
        );
        $mage = $boss('magef4');
        $this->assertSame(
            [0.0, [combat_engine::POWER_DRAIN, combat_engine::POWER_BREATH], 12],
            [$mage['bossarmor'], $mage['bosspowers'], $mage['bossbreathevery']]
        );
        $this->assertSame($warrior['bossarmor'], $boss('')['bossarmor'], 'no hero: fought as the warrior');

        $dragon = combat::phase_opponent(
            $this->make_attempt(['frozenopponent' => 'dragon', 'frozenhero' => 'magef4']),
            $instance
        );
        $this->assertSame([[combat_engine::POWER_BREATH], 0], [$dragon['bosspowers'], $dragon['bossbreathevery']]);
    }

    /**
     * Tests that a Demo fights at the fixed HP with no profile, no powers, no items and no difficulty.
     *
     * @return void
     */
    public function test_phase_opponent_of_a_demo_is_fixed_and_has_no_profile(): void {
        $instance = (object) ['basebosshp' => 900, 'bossdamage' => 12];

        $demo = combat::phase_opponent($this->make_attempt([
            'isdemo' => 1, 'difficulty' => 'hard', 'frozenopponent' => 'golem',
        ]), $instance);
        $this->assertSame(
            [
                'maxbosshp' => combat::DEMO_HP, 'basedamage' => 12, 'playerbasedamage' => 12, 'bossattack' => 1.0,
                'bossarmor' => 0.0,
                'bosspowers' => [], 'bossbreathevery' => 0,
                'bosskit' => ['potion' => 0, 'sword' => 0],
            ],
            $demo
        );
    }
}
