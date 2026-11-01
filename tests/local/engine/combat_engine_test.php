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
 * Unit tests for the server-side combat engine, mirroring
 * tests/js/engine/combat_rules.test.js case for case so both sides are known to agree on
 * every rule.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Tests for combat_engine.
 *
 * @covers \mod_playerpuzzle\local\engine\combat_engine
 */
final class combat_engine_test extends \advanced_testcase {
    /** @var array Fixed config shared by every test. */
    private const CONFIG = ['baseDamage' => 10, 'coinGain' => 10, 'coinFactor' => 1];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A fresh combat state with every field at a safe default.
     *
     * @return array The state.
     */
    private function fresh_state(): array {
        return [
            'currentPlayerHp' => 100, 'maxPlayerHp' => 200,
            'currentHp' => 100, 'maxBossHp' => 200,
            'playerMultiplier' => 1, 'bossMultiplier' => 1,
            'playerShieldMeter' => 0, 'playerShieldReady' => false,
            'bossShieldMeter' => 0, 'bossShieldReady' => false,
            'playerPoisonMeter' => 0, 'playerPoisonRounds' => 0,
            'bossPoisonMeter' => 0, 'bossPoisonRounds' => 0,
            'playerMana' => 0, 'bossMana' => 0,
            'playerGold' => 0, 'bossGold' => 0,
        ];
    }

    /**
     * Tests that combo_multiplier is 1.0 at the 3-piece baseline and scales linearly beyond.
     *
     * @return void
     */
    public function test_combo_multiplier_scales_linearly_beyond_baseline(): void {
        $this->assertSame(1.0, combat_engine::combo_multiplier(3));
        $this->assertSame(1.5, combat_engine::combo_multiplier(4));
        $this->assertSame(2.0, combat_engine::combo_multiplier(5));
    }

    /**
     * Tests that resolve_poison_meters arms rounds on the opposite side once a meter reaches
     * 100, preserving overshoot.
     *
     * @return void
     */
    public function test_resolve_poison_meters_arms_rounds_and_preserves_overshoot(): void {
        $state = array_merge($this->fresh_state(), ['playerPoisonMeter' => 110]);
        combat_engine::resolve_poison_meters($state);
        $this->assertSame(10, $state['playerPoisonMeter'], 'overshoot past 100 must be preserved, not discarded');
        $this->assertSame(3, $state['bossPoisonRounds']);
    }

    /**
     * Tests that resolve_poison_meters is a no-op below 100.
     *
     * @return void
     */
    public function test_resolve_poison_meters_is_a_no_op_below_100(): void {
        $state = array_merge($this->fresh_state(), ['bossPoisonMeter' => 99]);
        combat_engine::resolve_poison_meters($state);
        $this->assertSame(99, $state['bossPoisonMeter']);
        $this->assertSame(0, $state['playerPoisonRounds']);
    }

    /**
     * Tests that resolve_shield_meters arms the same side and resets flat, no overshoot.
     *
     * @return void
     */
    public function test_resolve_shield_meters_arms_and_resets_flat(): void {
        $state = array_merge($this->fresh_state(), ['playerShieldMeter' => 130]);
        combat_engine::resolve_shield_meters($state);
        $this->assertSame(0, $state['playerShieldMeter'], 'shield reset is flat to 0, unlike poison');
        $this->assertTrue($state['playerShieldReady']);
    }

    /**
     * Tests that phase_limit returns the fixed limit for a mapped type and INF for hint.
     *
     * @return void
     */
    public function test_phase_limit_returns_fixed_limits_and_inf_for_hint(): void {
        $this->assertSame(1.0, combat_engine::phase_limit('shield'));
        $this->assertSame(3.0, combat_engine::phase_limit('potion'));
        $this->assertInfinite(combat_engine::phase_limit('hint'));
    }

    /**
     * Tests that needs_revive is true only when a minimum is configured and not yet met.
     *
     * @return void
     */
    public function test_needs_revive_only_when_minimum_configured_and_unmet(): void {
        $this->assertTrue(combat_engine::needs_revive(5, 3));
        $this->assertFalse(combat_engine::needs_revive(5, 5), 'met exactly must not revive again');
        $this->assertFalse(combat_engine::needs_revive(0, 0), 'no minimum configured never revives');
    }

    /**
     * Tests that revive_hp is half of max, rounded up.
     *
     * @return void
     */
    public function test_revive_hp_is_half_of_max_rounded_up(): void {
        $this->assertSame(501, combat_engine::revive_hp(1001));
        $this->assertSame(500, combat_engine::revive_hp(1000));
    }

    /**
     * Tests that has_next_phase is false outside campaign mode regardless of level/phase.
     *
     * @return void
     */
    public function test_has_next_phase_false_outside_campaign(): void {
        $this->assertFalse(combat_engine::has_next_phase([
            'gamemode' => 'single', 'currentphase' => 1, 'currentlevel' => 1, 'maxlevels' => 5,
        ]));
    }

    /**
     * Tests that has_next_phase is true mid-campaign and false on the very last phase of the
     * last level.
     *
     * @return void
     */
    public function test_has_next_phase_mid_campaign_vs_last_phase(): void {
        $this->assertTrue(combat_engine::has_next_phase([
            'gamemode' => 'campaign', 'currentphase' => 5, 'currentlevel' => 1, 'maxlevels' => 1,
        ]));
        $this->assertFalse(combat_engine::has_next_phase([
            'gamemode' => 'campaign', 'currentphase' => 10, 'currentlevel' => 1, 'maxlevels' => 1,
        ]));
    }

    /**
     * Tests that resolve_damage applies the full amount when no shield is armed.
     *
     * @return void
     */
    public function test_resolve_damage_applies_full_amount_without_shield(): void {
        $result = combat_engine::resolve_damage(100, false, 30);
        $this->assertSame(['newHp' => 70.0, 'shieldConsumed' => false, 'appliedAmount' => 30.0], $result);
    }

    /**
     * Tests that resolve_damage blocks the hit entirely and consumes the shield when armed.
     *
     * @return void
     */
    public function test_resolve_damage_blocks_and_consumes_shield(): void {
        $result = combat_engine::resolve_damage(100, true, 30);
        $this->assertSame(['newHp' => 100.0, 'shieldConsumed' => true, 'appliedAmount' => 0.0], $result);
    }

    /**
     * Tests that resolve_damage clamps HP at 0, never negative.
     *
     * @return void
     */
    public function test_resolve_damage_clamps_at_0(): void {
        $result = combat_engine::resolve_damage(10, false, 999);
        $this->assertSame(0, $result['newHp']);
    }

    /**
     * Tests that resolve_poison_tick is a no-op with no rounds armed.
     *
     * @return void
     */
    public function test_resolve_poison_tick_no_op_without_rounds(): void {
        $result = combat_engine::resolve_poison_tick(100, 0, 10);
        $this->assertSame(['newHp' => 100.0, 'newPoisonRounds' => 0, 'ticked' => false], $result);
    }

    /**
     * Tests that resolve_poison_tick deals one tick and consumes one round.
     *
     * @return void
     */
    public function test_resolve_poison_tick_deals_one_tick(): void {
        $result = combat_engine::resolve_poison_tick(100, 2, 10);
        $this->assertSame(['newHp' => 90.0, 'newPoisonRounds' => 1, 'ticked' => true], $result);
    }

    /**
     * Tests that resolve_poison_tick clamps HP at 0.
     *
     * @return void
     */
    public function test_resolve_poison_tick_clamps_at_0(): void {
        $result = combat_engine::resolve_poison_tick(5, 1, 999);
        $this->assertSame(0, $result['newHp']);
    }

    /**
     * Tests that resolve_match_effects heals the player from a Potion, clamped to max, never
     * the boss.
     *
     * @return void
     */
    public function test_resolve_match_effects_potion_heals_player_only(): void {
        $result = combat_engine::resolve_match_effects(
            'player',
            [5],
            [],
            array_merge($this->fresh_state(), ['currentPlayerHp' => 195, 'maxPlayerHp' => 200]),
            self::CONFIG
        );
        $this->assertSame(197.5, $result['state']['currentPlayerHp']);
        $this->assertSame(2.5, $result['healGained']);
        $this->assertSame(100, $result['state']['currentHp'], 'boss HP must be untouched by a player Potion');
    }

    /**
     * Tests that a boss-turn Potion heals the boss instead.
     *
     * @return void
     */
    public function test_resolve_match_effects_boss_turn_potion_heals_boss(): void {
        $result = combat_engine::resolve_match_effects('boss', [5], [], $this->fresh_state(), self::CONFIG);
        $this->assertSame(102.5, $result['state']['currentHp']);
        $this->assertSame(100, $result['state']['currentPlayerHp'], 'player HP must be untouched by a boss Potion');
    }

    /**
     * Tests that Star raises the acting side's multiplier by 0.1, rounded to 1 decimal.
     *
     * @return void
     */
    public function test_resolve_match_effects_star_raises_multiplier(): void {
        $result = combat_engine::resolve_match_effects('player', [0, 0, 0], [], $this->fresh_state(), self::CONFIG);
        $this->assertSame(1.3, $result['state']['playerMultiplier']);
        $this->assertEqualsWithDelta(0.3, $result['multiplierGained'], 1e-9);
    }

    /**
     * Tests that Shield/Poison/Mana pieces add to the acting side's own meter.
     *
     * @return void
     */
    public function test_resolve_match_effects_meter_pieces(): void {
        $result = combat_engine::resolve_match_effects('player', [4, 1, 2], [], $this->fresh_state(), self::CONFIG);
        $this->assertSame(10, $result['state']['playerShieldMeter']);
        $this->assertSame(10, $result['state']['playerPoisonMeter']);
        $this->assertSame(20, $result['state']['playerMana']);
        $this->assertSame(10, $result['shieldGained']);
        $this->assertSame(10, $result['poisonGained']);
        $this->assertSame(20, $result['manaGained']);
    }

    /**
     * Tests that Sword damage scales by combo size via a match group, not per piece.
     *
     * @return void
     */
    public function test_resolve_match_effects_sword_scales_by_combo(): void {
        $result3 = combat_engine::resolve_match_effects(
            'player',
            [],
            [['type' => 3, 'size' => 3]],
            $this->fresh_state(),
            self::CONFIG
        );
        $this->assertSame(10.0, $result3['damageDealt'], '3-piece Sword combo must be exactly baseDamage');

        $result4 = combat_engine::resolve_match_effects(
            'player',
            [],
            [['type' => 3, 'size' => 4]],
            $this->fresh_state(),
            self::CONFIG
        );
        $this->assertSame(15.0, $result4['damageDealt'], '4-piece combo is baseDamage * 1.5');
    }

    /**
     * Tests that Coin gain credits the acting side's own gold, boss included.
     *
     * @return void
     */
    public function test_resolve_match_effects_coin_credits_acting_side(): void {
        $playerresult = combat_engine::resolve_match_effects(
            'player',
            [],
            [['type' => 6, 'size' => 3]],
            $this->fresh_state(),
            self::CONFIG
        );
        $this->assertSame(10.0, $playerresult['state']['playerGold']);
        $this->assertSame(0, $playerresult['state']['bossGold']);
        $this->assertSame(10.0, $playerresult['coinsGained']);

        $bossresult = combat_engine::resolve_match_effects(
            'boss',
            [],
            [['type' => 6, 'size' => 3]],
            $this->fresh_state(),
            self::CONFIG
        );
        $this->assertSame(10.0, $bossresult['state']['bossGold']);
        $this->assertSame(0, $bossresult['state']['playerGold']);
    }

    /**
     * Tests that player mana crossing 100 triggers a player question, overshoot preserved.
     *
     * @return void
     */
    public function test_resolve_match_effects_player_mana_triggers_question(): void {
        $result = combat_engine::resolve_match_effects(
            'player',
            [2, 2, 2, 2, 2, 2],
            [],
            $this->fresh_state(),
            self::CONFIG
        );
        $this->assertTrue($result['questionTriggered']);
        $this->assertSame('player', $result['triggeredBy']);
        $this->assertSame(20, $result['state']['playerMana'], 'overshoot past 100 must be preserved');
    }

    /**
     * Tests that boss mana crossing 100 triggers a boss question.
     *
     * @return void
     */
    public function test_resolve_match_effects_boss_mana_triggers_question(): void {
        $result = combat_engine::resolve_match_effects(
            'boss',
            [2, 2, 2, 2, 2],
            [],
            $this->fresh_state(),
            self::CONFIG
        );
        $this->assertTrue($result['questionTriggered']);
        $this->assertSame('boss', $result['triggeredBy']);
    }

    /**
     * Tests that no mana threshold crossed triggers no question.
     *
     * @return void
     */
    public function test_resolve_match_effects_no_threshold_no_question(): void {
        $result = combat_engine::resolve_match_effects('player', [2], [], $this->fresh_state(), self::CONFIG);
        $this->assertFalse($result['questionTriggered']);
        $this->assertNull($result['triggeredBy']);
    }

    /**
     * Tests that resolve_match_effects does not mutate the state array it was given.
     *
     * @return void
     */
    public function test_resolve_match_effects_does_not_mutate_input_state(): void {
        $state = $this->fresh_state();
        $frozencopy = $state;
        combat_engine::resolve_match_effects('player', [0], [], $state, self::CONFIG);
        $this->assertSame($frozencopy, $state, 'the input state array itself must be left untouched');
    }

    /**
     * Tests that resolve_consumable_effect potion heals 1.25x baseDamage, clamped to max.
     *
     * @return void
     */
    public function test_resolve_consumable_effect_potion_heals_and_clamps(): void {
        $result = combat_engine::resolve_consumable_effect(
            'potion',
            array_merge($this->fresh_state(), ['currentPlayerHp' => 199, 'maxPlayerHp' => 200]),
            self::CONFIG
        );
        $this->assertSame(12.5, $result['healAmount']);
        $this->assertSame(200, $result['state']['currentPlayerHp'], 'must clamp to max, not overshoot');
    }

    /**
     * Tests that resolve_consumable_effect shield fills the meter to 100 and arms it
     * immediately.
     *
     * @return void
     */
    public function test_resolve_consumable_effect_shield_fills_and_arms(): void {
        $result = combat_engine::resolve_consumable_effect('shield', $this->fresh_state(), self::CONFIG);
        $this->assertSame(0, $result['state']['playerShieldMeter']);
        $this->assertTrue($result['state']['playerShieldReady']);
    }

    /**
     * Tests that resolve_consumable_effect magic fills the poison meter to 100 and arms the
     * tick.
     *
     * @return void
     */
    public function test_resolve_consumable_effect_magic_fills_and_arms_tick(): void {
        $result = combat_engine::resolve_consumable_effect('magic', $this->fresh_state(), self::CONFIG);
        $this->assertSame(0, $result['state']['playerPoisonMeter']);
        $this->assertSame(3, $result['state']['bossPoisonRounds']);
    }

    /**
     * Tests that resolve_consumable_effect sword reports baseDamage without applying it.
     *
     * @return void
     */
    public function test_resolve_consumable_effect_sword_reports_without_applying(): void {
        $state = $this->fresh_state();
        $result = combat_engine::resolve_consumable_effect('sword', $state, self::CONFIG);
        $this->assertSame(10.0, $result['damageAmount']);
        $this->assertSame($state['currentHp'], $result['state']['currentHp'], 'applying the damage is the caller\'s job');
    }

    /**
     * Tests that initial_state starts both sides at full HP with everything else empty, keyed by
     * exactly STATE_FIELDS.
     *
     * @return void
     */
    public function test_initial_state_is_full_hp_and_empty_meters(): void {
        $state = combat_engine::initial_state(120, 900);

        $this->assertSame(combat_engine::STATE_FIELDS, array_keys($state));
        $this->assertSame(120.0, $state['currentPlayerHp']);
        $this->assertSame(900.0, $state['currentHp']);
        $this->assertSame(1.0, $state['playerMultiplier']);
        $this->assertFalse($state['bossShieldReady']);
        $this->assertSame(0.0, $state['playerGold']);
    }

    /**
     * Tests that match_hit_amount scales only the player's hits by their multiplier and rounds
     * halves up.
     *
     * @return void
     */
    public function test_match_hit_amount_scales_the_player_only_and_rounds_halves_up(): void {
        $state = array_merge($this->fresh_state(), ['playerMultiplier' => 1.5, 'bossMultiplier' => 2.0]);

        $this->assertSame(23.0, combat_engine::match_hit_amount('player', 15, $state, []));
        $this->assertSame(15.0, combat_engine::match_hit_amount('boss', 15, $state, []));
        $this->assertSame(17.0, combat_engine::match_hit_amount('boss', 16.5, $state, []));
    }

    /**
     * Tests every question_outcome branch: a right answer is a critical hit on the other side, a
     * wrong one resets the multiplier, and only the player pays WRONG_ANSWER_DAMAGE.
     *
     * @return void
     */
    public function test_question_outcome_for_each_side_and_answer(): void {
        $state = array_merge($this->fresh_state(), ['playerMultiplier' => 1.2, 'bossMultiplier' => 1.5]);

        $this->assertSame(
            ['hitBoss' => 36.0, 'hitPlayer' => null, 'resetPlayerMultiplier' => false, 'resetBossMultiplier' => false],
            combat_engine::question_outcome('player', true, $state, self::CONFIG)
        );
        $this->assertSame(
            ['hitBoss' => null, 'hitPlayer' => 30.0, 'resetPlayerMultiplier' => true, 'resetBossMultiplier' => false],
            combat_engine::question_outcome('player', false, $state, self::CONFIG)
        );
        $this->assertSame(
            ['hitBoss' => null, 'hitPlayer' => 45.0, 'resetPlayerMultiplier' => false, 'resetBossMultiplier' => false],
            combat_engine::question_outcome('boss', true, $state, self::CONFIG)
        );
        $this->assertSame(
            ['hitBoss' => null, 'hitPlayer' => null, 'resetPlayerMultiplier' => false, 'resetBossMultiplier' => true],
            combat_engine::question_outcome('boss', false, $state, self::CONFIG)
        );
    }

    /**
     * Tests that start_turn ticks only the poison of the side whose turn begins.
     *
     * @return void
     */
    public function test_start_turn_ticks_only_the_starting_sides_poison(): void {
        $state = array_merge($this->fresh_state(), ['playerPoisonRounds' => 2, 'bossPoisonRounds' => 3]);

        $boss = combat_engine::start_turn('boss', $state, self::CONFIG);
        $this->assertTrue($boss['poisonTicked']);
        $this->assertSame(90.0, $boss['state']['currentHp']);
        $this->assertSame(2, $boss['state']['bossPoisonRounds']);
        $this->assertSame(100, $boss['state']['currentPlayerHp']);
        $this->assertSame(2, $boss['state']['playerPoisonRounds']);

        $quiet = combat_engine::start_turn('player', $this->fresh_state(), self::CONFIG);
        $this->assertFalse($quiet['poisonTicked']);
        // HP comes back as a float; the values themselves are unchanged.
        $this->assertEquals($this->fresh_state(), $quiet['state']);
    }

    /**
     * Tests that hit_amount() applies the attacker's attack and the defender's armor, caps the
     * armor and rounds halves up, and keeps a hit with neither exactly as it was.
     *
     * @return void
     */
    public function test_hit_amount_applies_attack_and_capped_armor(): void {
        $this->assertSame(10.0, combat_engine::hit_amount(13, 'player', ['bossArmor' => 0.25]));
        $this->assertSame(13.0, combat_engine::hit_amount(10, 'boss', ['bossAttack' => 1.25]));
        $this->assertSame(10.0, combat_engine::hit_amount(10, 'player', ['bossAttack' => 1.25]));
        $this->assertSame(40.0, combat_engine::hit_amount(100, 'player', ['bossArmor' => 0.95]));
        $this->assertSame(33.3, combat_engine::hit_amount(33.3, 'player', []));
    }

    /**
     * Tests that armor softens a critical answer, the board Swords and the Sword consumable,
     * but never the wrong-answer penalty or a poison tick.
     *
     * @return void
     */
    public function test_armor_softens_hits_but_not_the_wrong_answer_penalty_or_poison(): void {
        $config = array_merge(self::CONFIG, ['bossArmor' => 0.25, 'playerArmor' => 0.25]);
        $state = $this->fresh_state();

        $this->assertSame(23.0, combat_engine::question_outcome('player', true, $state, $config)['hitBoss']);
        $this->assertSame(23.0, combat_engine::question_outcome('boss', true, $state, $config)['hitPlayer']);
        $this->assertSame(
            (float) combat_engine::WRONG_ANSWER_DAMAGE,
            combat_engine::question_outcome('player', false, $state, $config)['hitPlayer']
        );
        $this->assertSame(11.0, combat_engine::match_hit_amount('player', 15, $state, $config));
        $this->assertSame(8.0, combat_engine::resolve_consumable_effect('sword', $state, $config)['damageAmount']);

        $poisoned = array_merge($state, ['bossPoisonRounds' => 2]);
        $this->assertSame(
            (float) ($state['currentHp'] - self::CONFIG['baseDamage']),
            combat_engine::start_turn('boss', $poisoned, $config)['state']['currentHp']
        );
    }

    /**
     * Tests that an opponent with the iceshield power starts with its Shield armed, and that the
     * state is otherwise the plain initial one.
     *
     * @return void
     */
    public function test_initial_state_arms_the_shield_of_an_opponent_with_iceshield(): void {
        $plain = combat_engine::initial_state(100, 200);
        $shielded = combat_engine::initial_state(100, 200, ['bossPowers' => [combat_engine::POWER_ICESHIELD]]);

        $this->assertFalse($plain['bossShieldReady']);
        $this->assertTrue($shielded['bossShieldReady']);
        $this->assertSame(array_merge($plain, ['bossShieldReady' => true]), $shielded);
        $this->assertSame($plain, combat_engine::initial_state(100, 200, ['bossPowers' => []]));
    }

    /**
     * Tests that an opponent with longpoison arms one more round of poison against the student,
     * and that the student's own Grimoires still arm the usual number against it.
     *
     * @return void
     */
    public function test_longpoison_arms_one_more_round_against_the_student_only(): void {
        $config = ['bossPowers' => [combat_engine::POWER_LONGPOISON]];
        $state = array_merge($this->fresh_state(), ['playerPoisonMeter' => 100, 'bossPoisonMeter' => 110]);

        combat_engine::resolve_poison_meters($state, $config);

        $this->assertSame(combat_engine::LONG_POISON_ROUNDS, $state['playerPoisonRounds']);
        $this->assertSame(combat_engine::POISON_ROUNDS, $state['bossPoisonRounds']);
        $this->assertSame(10, $state['bossPoisonMeter']);
    }

    /**
     * Tests that an opponent with fastgrimoire fills more of its poison meter per Grimoire, and
     * that the student's Grimoires fill the usual amount.
     *
     * @return void
     */
    public function test_fastgrimoire_fills_more_of_the_opponents_poison_meter_only(): void {
        $config = array_merge(self::CONFIG, ['bossPowers' => [combat_engine::POWER_FASTGRIMOIRE]]);

        $groups = [['type' => 1, 'size' => 3]];
        $boss = combat_engine::resolve_match_effects('boss', [1, 1, 1], $groups, $this->fresh_state(), $config);
        $player = combat_engine::resolve_match_effects('player', [1, 1, 1], $groups, $this->fresh_state(), $config);

        $this->assertSame(3 * combat_engine::FAST_GRIMOIRE_POISON, $boss['state']['bossPoisonMeter']);
        $this->assertSame(3 * combat_engine::GRIMOIRE_POISON, $player['state']['playerPoisonMeter']);
    }
}
