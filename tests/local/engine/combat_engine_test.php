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
            'bossTurnCount' => 0, 'bossPotions' => 0, 'bossSwords' => 0,
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
     * wrong one resets the answering side's multiplier and costs no HP.
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
            ['hitBoss' => null, 'hitPlayer' => null, 'resetPlayerMultiplier' => true, 'resetBossMultiplier' => false],
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
     * Tests that armor softens a critical answer, the board Swords and the Sword consumable, but
     * never a poison tick.
     *
     * @return void
     */
    public function test_armor_softens_hits_but_not_poison(): void {
        $config = array_merge(self::CONFIG, ['bossArmor' => 0.25, 'playerArmor' => 0.25]);
        $state = $this->fresh_state();

        $this->assertSame(23.0, combat_engine::question_outcome('player', true, $state, $config)['hitBoss']);
        $this->assertSame(23.0, combat_engine::question_outcome('boss', true, $state, $config)['hitPlayer']);
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
     * Tests that an opponent with the longpoison power starts with part of its poison meter
     * filled, and that the state is otherwise the plain initial one.
     *
     * @return void
     */
    public function test_initial_state_fills_part_of_the_poison_meter_of_an_opponent_with_longpoison(): void {
        $plain = combat_engine::initial_state(100, 200);
        $cursed = combat_engine::initial_state(100, 200, ['bossPowers' => [combat_engine::POWER_LONGPOISON]]);

        $this->assertSame(array_merge($plain, ['bossPoisonMeter' => combat_engine::LONG_POISON_HEAD_START]), $cursed);
        $this->assertSame(20, combat_engine::LONG_POISON_HEAD_START);
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

    /**
     * Tests that an opponent with manaboost gets more mana per Orb, and the student the usual.
     *
     * @return void
     */
    public function test_manaboost_gives_the_opponent_more_mana_per_orb(): void {
        $config = array_merge(self::CONFIG, ['bossPowers' => [combat_engine::POWER_MANABOOST]]);

        $this->assertSame(combat_engine::BOOSTED_ORB_MANA, combat_engine::piece_gains('boss', $config)['mana']);
        $this->assertSame(combat_engine::ORB_MANA, combat_engine::piece_gains('player', $config)['mana']);
        $this->assertSame(combat_engine::ORB_MANA, combat_engine::piece_gains('boss', self::CONFIG)['mana']);
    }

    /**
     * Tests that an opponent with regen recovers REGEN_PERCENT of its max HP as its turn begins,
     * never past the max and never after its own poison tick knocked it out, and that the student
     * and an opponent without the power recover nothing.
     *
     * @return void
     */
    public function test_regen_heals_the_opponent_as_its_turn_begins(): void {
        $config = array_merge(self::CONFIG, ['bossPowers' => [combat_engine::POWER_REGEN]]);
        $state = array_merge($this->fresh_state(), ['currentHp' => 150.0, 'maxBossHp' => 230.0]);

        $healed = combat_engine::start_turn('boss', $state, $config);
        $this->assertSame(1, combat_engine::REGEN_PERCENT);
        $this->assertSame(2.3, $healed['regenerated']);
        $this->assertSame(152.3, $healed['state']['currentHp']);

        $nearlyfull = combat_engine::start_turn('boss', array_merge($state, ['currentHp' => 229.0]), $config);
        $this->assertSame(1.0, $nearlyfull['regenerated']);
        $this->assertSame(230.0, $nearlyfull['state']['currentHp']);

        $knockedout = array_merge($state, ['currentHp' => 5.0, 'bossPoisonRounds' => 1]);
        $killed = combat_engine::start_turn('boss', $knockedout, $config);
        $this->assertEquals(0, $killed['state']['currentHp']);
        $this->assertSame(0.0, $killed['regenerated']);

        $this->assertSame(0.0, combat_engine::start_turn('player', $state, $config)['regenerated']);
        $this->assertSame(0.0, combat_engine::start_turn('boss', $state, self::CONFIG)['regenerated']);
    }

    /**
     * Tests that the opponent counts its turns as they begin, its first turn being 1, and that
     * the student's turns never move the count.
     *
     * @return void
     */
    public function test_start_turn_counts_the_opponents_turns(): void {
        $state = $this->fresh_state();
        $this->assertSame(0, $state['bossTurnCount']);

        $first = combat_engine::start_turn('boss', $state, self::CONFIG)['state'];
        $this->assertSame(1, $first['bossTurnCount']);
        $this->assertSame(2, combat_engine::start_turn('boss', $first, self::CONFIG)['state']['bossTurnCount']);
        $this->assertSame(1, combat_engine::start_turn('player', $first, self::CONFIG)['state']['bossTurnCount']);
    }

    /**
     * Tests that an opponent with intangible arms its Shield every INTANGIBLE_EVERY of its turns,
     * reporting it only when the Shield was not armed yet, and that the student's turns and an
     * opponent without the power never arm it.
     *
     * @return void
     */
    public function test_intangible_arms_the_shield_every_few_turns(): void {
        $config = array_merge(self::CONFIG, ['bossPowers' => [combat_engine::POWER_INTANGIBLE]]);
        $before = fn(int $turn): array => array_merge($this->fresh_state(), ['bossTurnCount' => $turn - 1]);

        $this->assertSame(10, combat_engine::INTANGIBLE_EVERY);
        $tenth = combat_engine::start_turn('boss', $before(10), $config);
        $this->assertTrue($tenth['state']['bossShieldReady']);
        $this->assertTrue($tenth['shieldArmed']);

        $ninth = combat_engine::start_turn('boss', $before(9), $config);
        $this->assertFalse($ninth['state']['bossShieldReady']);
        $this->assertFalse($ninth['shieldArmed']);

        $already = combat_engine::start_turn('boss', array_merge($before(20), ['bossShieldReady' => true]), $config);
        $this->assertTrue($already['state']['bossShieldReady']);
        $this->assertFalse($already['shieldArmed']);

        $this->assertFalse(combat_engine::start_turn('boss', $before(10), self::CONFIG)['state']['bossShieldReady']);
        $this->assertFalse(combat_engine::start_turn('player', $before(11), $config)['state']['bossShieldReady']);
    }

    /**
     * Tests that an opponent with breath hits the student for BREATH_MULTIPLIER times the base
     * damage every BREATH_EVERY of its turns, through its attack, the student's Shield and its own
     * drain, and never once its own poison tick knocked it out.
     *
     * @return void
     */
    public function test_breath_hits_the_student_every_few_turns(): void {
        $config = array_merge(self::CONFIG, ['bossPowers' => [combat_engine::POWER_BREATH], 'bossAttack' => 1.15]);
        $before = fn(int $turn): array => array_merge($this->fresh_state(), ['bossTurnCount' => $turn - 1]);

        $this->assertSame([10, 1.5], [combat_engine::BREATH_EVERY, combat_engine::BREATH_MULTIPLIER]);
        $tenth = combat_engine::start_turn('boss', $before(10), $config);
        $this->assertEquals(['hit' => 17, 'appliedAmount' => 17, 'shieldConsumed' => false, 'drained' => 0], $tenth['breath']);
        $this->assertEquals(83, $tenth['state']['currentPlayerHp']);
        $this->assertNull(combat_engine::start_turn('boss', $before(9), $config)['breath']);

        $shielded = combat_engine::start_turn('boss', array_merge($before(10), ['playerShieldReady' => true]), $config);
        $this->assertEquals(100, $shielded['state']['currentPlayerHp']);
        $this->assertFalse($shielded['state']['playerShieldReady']);
        $this->assertTrue($shielded['breath']['shieldConsumed']);

        $draining = array_merge($config, ['bossPowers' => [combat_engine::POWER_BREATH, combat_engine::POWER_DRAIN]]);
        $this->assertEqualsWithDelta(5.1, combat_engine::start_turn('boss', $before(10), $draining)['breath']['drained'], 1e-9);

        $poisoned = array_merge($before(10), ['currentHp' => 5, 'bossPoisonRounds' => 1]);
        $this->assertNull(combat_engine::start_turn('boss', $poisoned, $config)['breath']);
    }

    /**
     * Tests that an opponent with loot takes LOOT_PERCENT of the student's coins for itself every
     * LOOT_EVERY of its turns, and nothing on other turns or without the power.
     *
     * @return void
     */
    public function test_loot_takes_part_of_the_students_coins_every_few_turns(): void {
        $config = array_merge(self::CONFIG, ['bossPowers' => [combat_engine::POWER_LOOT]]);
        $before = fn(int $turn): array => array_merge(
            $this->fresh_state(),
            ['bossTurnCount' => $turn - 1, 'playerGold' => 45.0, 'bossGold' => 20.0]
        );

        $this->assertSame([4, 10], [combat_engine::LOOT_EVERY, combat_engine::LOOT_PERCENT]);
        $fourth = combat_engine::start_turn('boss', $before(4), $config);
        $this->assertSame(4.5, $fourth['looted']);
        $this->assertSame(40.5, $fourth['state']['playerGold']);
        $this->assertSame(24.5, $fourth['state']['bossGold']);
        // 4.5 coins are 0.45 of a 10-coin match: a hit of 2 x 0.45 x the base damage of 10.
        $this->assertEquals(['hit' => 9, 'appliedAmount' => 9, 'shieldConsumed' => false, 'drained' => 0], $fourth['raid']);
        $this->assertEquals(91, $fourth['state']['currentPlayerHp']);
        $shielded = combat_engine::start_turn('boss', array_merge($before(4), ['playerShieldReady' => true]), $config);
        $this->assertTrue($shielded['raid']['shieldConsumed']);
        $this->assertEquals(100, $shielded['state']['currentPlayerHp']);
        $this->assertNull(combat_engine::start_turn('boss', array_merge($before(4), ['playerGold' => 0.0]), $config)['raid']);
        $nocoins = array_merge($config, ['coinGain' => 0]);
        $this->assertNull(combat_engine::start_turn('boss', $before(4), $nocoins)['raid']);
        $this->assertSame(0.0, combat_engine::start_turn('boss', $before(5), $config)['looted']);
        $broke = array_merge($before(8), ['playerGold' => 0.0]);
        $this->assertSame(0.0, combat_engine::start_turn('boss', $broke, $config)['looted']);
        $this->assertSame(0.0, combat_engine::start_turn('boss', $before(4), self::CONFIG)['looted']);
    }

    /**
     * Tests that an opponent with ambush doubles its Swords on its first turn, before its attack,
     * and on no other turn, and that the student's Swords never double.
     *
     * @return void
     */
    public function test_ambush_doubles_the_opponents_swords_on_its_first_turn(): void {
        $config = array_merge(self::CONFIG, ['bossPowers' => [combat_engine::POWER_AMBUSH], 'bossAttack' => 1.15]);
        $onturn = fn(int $count): array => array_merge($this->fresh_state(), ['bossTurnCount' => $count]);

        $this->assertSame(23.0, combat_engine::match_hit_amount('boss', 10, $onturn(1), $config));
        $this->assertSame(12.0, combat_engine::match_hit_amount('boss', 10, $onturn(2), $config));
        $plain = array_merge(self::CONFIG, ['bossAttack' => 1.15]);
        $this->assertSame(12.0, combat_engine::match_hit_amount('boss', 10, $onturn(1), $plain));
        $this->assertSame(10.0, combat_engine::match_hit_amount('player', 10, $onturn(1), $config));
        $this->assertTrue(combat_engine::is_ambush('boss', $onturn(1), $config));
        $this->assertFalse(combat_engine::is_ambush('boss', $onturn(0), $config));
    }

    /**
     * Tests the opponent's move weights: none on Easy, the base on Normal (and without a
     * difficulty), with the Potion scaled by how hurt it is and less for an armed Shield, and the
     * personality of its powers on Hard only.
     *
     * @return void
     */
    public function test_opponent_move_weights_follow_the_difficulty(): void {
        $state = array_merge($this->fresh_state(), ['currentHp' => 100.0, 'maxBossHp' => 200.0]);
        $base = [1.5, 1.5, 1.5, 3, 1.5, 1.5, 1];

        $this->assertNull(combat_engine::opponent_move_weights($state, ['difficulty' => 'easy']));
        $this->assertEquals($base, combat_engine::opponent_move_weights($state, ['difficulty' => 'normal']));
        $this->assertEquals($base, combat_engine::opponent_move_weights($state, []));

        $full = array_merge($state, ['currentHp' => 200.0, 'bossShieldReady' => true]);
        $this->assertEquals([1.5, 1.5, 1.5, 3, 0.5, 0, 1], combat_engine::opponent_move_weights($full, ['difficulty' => 'normal']));

        $plant = ['difficulty' => 'hard', 'bossPowers' => [combat_engine::POWER_ROOTHEAL]];
        $this->assertEquals([1.5, 1.5, 1.5, 3, 1.5, 3, 1], combat_engine::opponent_move_weights($state, $plant));
        $this->assertEquals($base, combat_engine::opponent_move_weights($state, array_merge($plant, ['difficulty' => 'normal'])));
    }

    /**
     * Tests the opponent's starting items: one per level after the first, plus Hard and the final
     * boss, Potions first and alternating, capped at the phase limits, none on Easy.
     *
     * @return void
     */
    public function test_opponent_kit_grows_with_level_difficulty_and_the_final_boss(): void {
        $this->assertSame(['potion' => 0, 'sword' => 0], combat_engine::opponent_kit(1, 'normal', false));
        $this->assertSame(['potion' => 1, 'sword' => 0], combat_engine::opponent_kit(2, 'normal', false));
        $this->assertSame(['potion' => 1, 'sword' => 1], combat_engine::opponent_kit(3, 'normal', false));
        $this->assertSame(['potion' => 1, 'sword' => 0], combat_engine::opponent_kit(1, 'hard', false));
        $this->assertSame(['potion' => 1, 'sword' => 0], combat_engine::opponent_kit(1, 'normal', true));
        $this->assertSame(['potion' => 0, 'sword' => 0], combat_engine::opponent_kit(3, 'easy', true));
        $this->assertSame(['potion' => 3, 'sword' => 3], combat_engine::opponent_kit(10, 'hard', true));
        $this->assertSame(2, combat_engine::initial_state(100, 200, ['bossKit' => ['potion' => 2, 'sword' => 1]])['bossPotions']);
    }

    /**
     * Tests when the opponent uses an item: a Sword that wins the fight or hits a student below
     * ITEM_SWORD_BELOW, never against an armed Shield; otherwise a Potion below ITEM_POTION_BELOW.
     *
     * @return void
     */
    public function test_opponent_item_use_follows_the_hp_thresholds(): void {
        $config = array_merge(self::CONFIG, ['bossAttack' => 1.15]);
        $kit = array_merge($this->fresh_state(), ['bossPotions' => 1, 'bossSwords' => 1, 'maxPlayerHp' => 200]);

        $this->assertSame('sword', combat_engine::opponent_item_use(array_merge($kit, ['currentPlayerHp' => 12]), $config));
        $this->assertSame('sword', combat_engine::opponent_item_use(array_merge($kit, ['currentPlayerHp' => 59]), $config));
        $this->assertNull(combat_engine::opponent_item_use(array_merge($kit, ['currentPlayerHp' => 60]), $config));
        $shielded = array_merge($kit, ['currentPlayerHp' => 12, 'playerShieldReady' => true]);
        $this->assertNull(combat_engine::opponent_item_use($shielded, $config));

        $hurt = array_merge($kit, ['currentPlayerHp' => 150, 'currentHp' => 79]);
        $this->assertSame('potion', combat_engine::opponent_item_use($hurt, $config));
        $this->assertNull(combat_engine::opponent_item_use(array_merge($hurt, ['currentHp' => 80]), $config));
        $this->assertNull(combat_engine::opponent_item_use(array_merge($hurt, ['bossPotions' => 0]), $config));
    }

    /**
     * Tests that start_turn uses the opponent's item last: a Sword through the Shield rules and its
     * drain, a Potion healing like the student's, and nothing on the student's turn.
     *
     * @return void
     */
    public function test_start_turn_uses_the_opponents_item(): void {
        $config = array_merge(self::CONFIG, ['bossAttack' => 1.15, 'bossPowers' => [combat_engine::POWER_DRAIN]]);
        $low = array_merge($this->fresh_state(), ['bossSwords' => 1, 'currentPlayerHp' => 12, 'maxPlayerHp' => 200]);
        $sword = combat_engine::start_turn('boss', $low, $config);
        $this->assertEquals(
            ['kind' => 'sword', 'amount' => 12, 'appliedAmount' => 12, 'shieldConsumed' => false, 'drained' => 3.6],
            $sword['item']
        );
        $this->assertEquals(0, $sword['state']['currentPlayerHp']);
        $this->assertSame(0, $sword['state']['bossSwords']);

        $hurt = array_merge($this->fresh_state(), ['bossPotions' => 2, 'currentHp' => 50.0, 'maxBossHp' => 200.0]);
        $potion = combat_engine::start_turn('boss', $hurt, self::CONFIG);
        $this->assertEquals(
            ['kind' => 'potion', 'amount' => 12.5, 'appliedAmount' => 0, 'shieldConsumed' => false, 'drained' => 0],
            $potion['item']
        );
        $this->assertSame(62.5, $potion['state']['currentHp']);
        $this->assertSame(1, $potion['state']['bossPotions']);
        $this->assertNull(combat_engine::start_turn('player', $hurt, self::CONFIG)['item']);
    }

    /**
     * Tests that an opponent with drain recovers DRAIN_PERCENT of what its hit dealt, up to its
     * max HP, and that an opponent without the power recovers nothing.
     *
     * @return void
     */
    public function test_drain_heals_the_opponent_by_part_of_its_hit(): void {
        $config = ['bossPowers' => [combat_engine::POWER_DRAIN]];
        $state = array_merge($this->fresh_state(), ['currentHp' => 100.0, 'maxBossHp' => 240.0]);

        $this->assertSame(30, combat_engine::DRAIN_PERCENT);
        $this->assertSame(6.0, combat_engine::drain_amount(20, $state, $config));
        $this->assertSame(0.0, combat_engine::drain_amount(0, $state, $config));
        $this->assertSame(2.0, combat_engine::drain_amount(20, array_merge($state, ['currentHp' => 238.0]), $config));
        $this->assertSame(0.0, combat_engine::drain_amount(20, $state, []));
    }

    /**
     * Tests that an opponent with rootheal heals half as much again per Potion, and the student
     * the usual.
     *
     * @return void
     */
    public function test_rootheal_raises_the_opponents_potion_heal_by_half(): void {
        $config = array_merge(self::CONFIG, ['bossPowers' => [combat_engine::POWER_ROOTHEAL]]);

        $this->assertSame(3.75, combat_engine::piece_gains('boss', $config)['heal']);
        $this->assertSame(6, combat_engine::piece_gains('boss', array_merge($config, ['baseDamage' => 16]))['heal']);
        $this->assertSame(2.5, combat_engine::piece_gains('player', $config)['heal']);
        $this->assertSame(2.5, combat_engine::piece_gains('boss', self::CONFIG)['heal']);
    }
}
