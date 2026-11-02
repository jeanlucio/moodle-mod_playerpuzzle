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
 * Headless tests for the pure combat logic, run via `node --test` — no RequireJS, no Phaser,
 * no browser at all. Loads amd/src/engine/combat_rules.js directly through Node's CommonJS
 * require(), the branch its UMD wrapper takes outside a `define()` environment.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const combatRules = require('../../../amd/src/engine/combat_rules.js');

/**
 * A fresh combat state with every field at a safe default (no meters full, no shields
 * armed, both sides at half of a round max HP) — tests override only what they care about.
 *
 * @return {object} The state.
 */
function freshState() {
    return {
        currentPlayerHp: 100, maxPlayerHp: 200,
        currentHp: 100, maxBossHp: 200,
        playerMultiplier: 1, bossMultiplier: 1,
        playerShieldMeter: 0, playerShieldReady: false,
        bossShieldMeter: 0, bossShieldReady: false,
        playerPoisonMeter: 0, playerPoisonRounds: 0,
        bossPoisonMeter: 0, bossPoisonRounds: 0,
        playerMana: 0, bossMana: 0,
        playerGold: 0, bossGold: 0,
    };
}

const CONFIG = {baseDamage: 10, coinGain: 10, coinFactor: 1};

test('comboMultiplier is 1.0 at the 3-piece baseline and scales linearly beyond it', () => {
    assert.equal(combatRules.comboMultiplier(3), 1);
    assert.equal(combatRules.comboMultiplier(4), 1.5);
    assert.equal(combatRules.comboMultiplier(5), 2);
});

test('resolvePoisonMeters arms rounds on the opposite side once a meter reaches 100', () => {
    const state = Object.assign(freshState(), {playerPoisonMeter: 110});
    combatRules.resolvePoisonMeters(state);
    assert.equal(state.playerPoisonMeter, 10, 'overshoot past 100 must be preserved, not discarded');
    assert.equal(state.bossPoisonRounds, 3);
});

test('resolvePoisonMeters is a no-op below 100', () => {
    const state = Object.assign(freshState(), {bossPoisonMeter: 99});
    combatRules.resolvePoisonMeters(state);
    assert.equal(state.bossPoisonMeter, 99);
    assert.equal(state.playerPoisonRounds, 0);
});

test('resolveShieldMeters arms the same side and resets flat, no overshoot preserved', () => {
    const state = Object.assign(freshState(), {playerShieldMeter: 130});
    combatRules.resolveShieldMeters(state);
    assert.equal(state.playerShieldMeter, 0, 'shield reset is flat to 0, unlike poison');
    assert.equal(state.playerShieldReady, true);
});

test('phaseLimit returns the fixed limit for a mapped type and Infinity for hint', () => {
    assert.equal(combatRules.phaseLimit('shield'), 1);
    assert.equal(combatRules.phaseLimit('potion'), 3);
    assert.equal(combatRules.phaseLimit('hint'), Infinity);
});

test('needsRevive is true only when a minimum is configured and not yet met', () => {
    assert.equal(combatRules.needsRevive(5, 3), true);
    assert.equal(combatRules.needsRevive(5, 5), false, 'met exactly must not revive again');
    assert.equal(combatRules.needsRevive(0, 0), false, 'no minimum configured never revives');
});

test('reviveHp is half of max, rounded up', () => {
    assert.equal(combatRules.reviveHp(1001), 501);
    assert.equal(combatRules.reviveHp(1000), 500);
});

test('hasNextPhase is false outside campaign mode regardless of level/phase', () => {
    assert.equal(
        combatRules.hasNextPhase({gamemode: 'single', currentphase: 1, currentlevel: 1, maxlevels: 5}),
        false
    );
});

test('hasNextPhase is true mid-campaign and false on the very last phase of the last level', () => {
    assert.equal(
        combatRules.hasNextPhase({gamemode: 'campaign', currentphase: 5, currentlevel: 1, maxlevels: 1}),
        true
    );
    assert.equal(
        combatRules.hasNextPhase({gamemode: 'campaign', currentphase: 10, currentlevel: 1, maxlevels: 1}),
        false
    );
});

test('resolveDamage applies the full amount when no shield is armed', () => {
    const result = combatRules.resolveDamage(100, false, 30);
    assert.deepEqual(result, {newHp: 70, shieldConsumed: false, appliedAmount: 30});
});

test('resolveDamage blocks the hit entirely and consumes the shield when one is armed', () => {
    const result = combatRules.resolveDamage(100, true, 30);
    assert.deepEqual(result, {newHp: 100, shieldConsumed: true, appliedAmount: 0});
});

test('resolveDamage clamps HP at 0, never negative', () => {
    const result = combatRules.resolveDamage(10, false, 999);
    assert.equal(result.newHp, 0);
});

test('resolvePoisonTick is a no-op with no rounds armed', () => {
    const result = combatRules.resolvePoisonTick(100, 0, 10);
    assert.deepEqual(result, {newHp: 100, newPoisonRounds: 0, ticked: false});
});

test('resolvePoisonTick deals one tick and consumes one round', () => {
    const result = combatRules.resolvePoisonTick(100, 2, 10);
    assert.deepEqual(result, {newHp: 90, newPoisonRounds: 1, ticked: true});
});

test('resolvePoisonTick clamps HP at 0', () => {
    const result = combatRules.resolvePoisonTick(5, 1, 999);
    assert.equal(result.newHp, 0);
});

test('resolveMatchEffects: Potion heals the player, clamped to max, never the boss', () => {
    const result = combatRules.resolveMatchEffects({
        currentTurn: 'player',
        destroyedTypes: [5],
        matchGroups: [],
        state: Object.assign(freshState(), {currentPlayerHp: 195, maxPlayerHp: 200}),
        config: CONFIG,
    });
    assert.equal(result.state.currentPlayerHp, 197.5);
    assert.equal(result.healGained, 2.5);
    assert.equal(result.state.currentHp, 100, 'boss HP must be untouched by a player Potion');
});

test('resolveMatchEffects: a boss-turn Potion heals the boss instead', () => {
    const result = combatRules.resolveMatchEffects({
        currentTurn: 'boss',
        destroyedTypes: [5],
        matchGroups: [],
        state: freshState(),
        config: CONFIG,
    });
    assert.equal(result.state.currentHp, 102.5);
    assert.equal(result.state.currentPlayerHp, 100, 'player HP must be untouched by a boss Potion');
});

test('resolveMatchEffects: Star raises the acting side\'s multiplier by 0.1, rounded to 1 decimal', () => {
    const result = combatRules.resolveMatchEffects({
        currentTurn: 'player',
        destroyedTypes: [0, 0, 0],
        matchGroups: [],
        state: freshState(),
        config: CONFIG,
    });
    assert.equal(result.state.playerMultiplier, 1.3);
    // Raw accumulation before the rounding pass (0.1 three times) is a float, not exactly
    // 0.3 — asserted with a tolerance rather than a brittle exact float comparison.
    assert.ok(Math.abs(result.multiplierGained - 0.3) < 1e-9);
});

test('resolveMatchEffects: Shield/Poison/Mana pieces add to the acting side\'s own meter', () => {
    const result = combatRules.resolveMatchEffects({
        currentTurn: 'player',
        destroyedTypes: [4, 1, 2],
        matchGroups: [],
        state: freshState(),
        config: CONFIG,
    });
    assert.equal(result.state.playerShieldMeter, 10);
    assert.equal(result.state.playerPoisonMeter, 10);
    assert.equal(result.state.playerMana, 20);
    assert.equal(result.shieldGained, 10);
    assert.equal(result.poisonGained, 10);
    assert.equal(result.manaGained, 20);
});

test('resolveMatchEffects: Sword damage scales by combo size via a match group, not per piece', () => {
    const result3 = combatRules.resolveMatchEffects({
        currentTurn: 'player', destroyedTypes: [], matchGroups: [{type: 3, size: 3}],
        state: freshState(), config: CONFIG,
    });
    assert.equal(result3.damageDealt, 10, '3-piece Sword combo must be exactly baseDamage');

    const result4 = combatRules.resolveMatchEffects({
        currentTurn: 'player', destroyedTypes: [], matchGroups: [{type: 3, size: 4}],
        state: freshState(), config: CONFIG,
    });
    assert.equal(result4.damageDealt, 15, '4-piece combo is baseDamage * 1.5');
});

test('resolveMatchEffects: Coin gain credits the acting side\'s own gold, boss included', () => {
    const playerResult = combatRules.resolveMatchEffects({
        currentTurn: 'player', destroyedTypes: [], matchGroups: [{type: 6, size: 3}],
        state: freshState(), config: CONFIG,
    });
    assert.equal(playerResult.state.playerGold, 10);
    assert.equal(playerResult.state.bossGold, 0);
    assert.equal(playerResult.coinsGained, 10);

    const bossResult = combatRules.resolveMatchEffects({
        currentTurn: 'boss', destroyedTypes: [], matchGroups: [{type: 6, size: 3}],
        state: freshState(), config: CONFIG,
    });
    assert.equal(bossResult.state.bossGold, 10);
    assert.equal(bossResult.state.playerGold, 0);
});

test('resolveMatchEffects: player mana crossing 100 triggers a player question, overshoot preserved', () => {
    const result = combatRules.resolveMatchEffects({
        currentTurn: 'player',
        destroyedTypes: [2, 2, 2, 2, 2, 2], // 6 x Orb = 120 mana.
        matchGroups: [],
        state: freshState(),
        config: CONFIG,
    });
    assert.equal(result.questionTriggered, true);
    assert.equal(result.triggeredBy, 'player');
    assert.equal(result.state.playerMana, 20, 'overshoot past 100 must be preserved');
});

test('resolveMatchEffects: boss mana crossing 100 triggers a boss question', () => {
    const result = combatRules.resolveMatchEffects({
        currentTurn: 'boss',
        destroyedTypes: [2, 2, 2, 2, 2],
        matchGroups: [],
        state: freshState(),
        config: CONFIG,
    });
    assert.equal(result.questionTriggered, true);
    assert.equal(result.triggeredBy, 'boss');
});

test('resolveMatchEffects: no mana threshold crossed triggers no question', () => {
    const result = combatRules.resolveMatchEffects({
        currentTurn: 'player',
        destroyedTypes: [2],
        matchGroups: [],
        state: freshState(),
        config: CONFIG,
    });
    assert.equal(result.questionTriggered, false);
    assert.equal(result.triggeredBy, null);
});

test('resolveMatchEffects does not mutate the state object it was given', () => {
    const state = freshState();
    const frozenCopy = Object.assign({}, state);
    combatRules.resolveMatchEffects({
        currentTurn: 'player', destroyedTypes: [0], matchGroups: [],
        state, config: CONFIG,
    });
    assert.deepEqual(state, frozenCopy, 'the input state object itself must be left untouched');
});

test('resolveConsumableEffect: potion heals 1.25x baseDamage, clamped to max', () => {
    const result = combatRules.resolveConsumableEffect(
        'potion',
        Object.assign(freshState(), {currentPlayerHp: 199, maxPlayerHp: 200}),
        CONFIG
    );
    assert.equal(result.healAmount, 12.5);
    assert.equal(result.state.currentPlayerHp, 200, 'must clamp to max, not overshoot');
});

test('resolveConsumableEffect: shield fills the meter to 100 and arms it immediately', () => {
    const result = combatRules.resolveConsumableEffect('shield', freshState(), CONFIG);
    assert.equal(result.state.playerShieldMeter, 0);
    assert.equal(result.state.playerShieldReady, true);
});

test('resolveConsumableEffect: magic fills the poison meter to 100 and arms the tick', () => {
    const result = combatRules.resolveConsumableEffect('magic', freshState(), CONFIG);
    assert.equal(result.state.playerPoisonMeter, 0);
    assert.equal(result.state.bossPoisonRounds, 3);
});

test('resolveConsumableEffect: sword reports baseDamage without applying it itself', () => {
    const state = freshState();
    const result = combatRules.resolveConsumableEffect('sword', state, CONFIG);
    assert.equal(result.damageAmount, 10);
    assert.equal(result.state.currentHp, state.currentHp, 'applying the damage is the caller\'s job');
});

test('hitAmount: armor takes its share of the hit, rounded to a whole number', () => {
    assert.equal(combatRules.hitAmount(13, 'player', {bossArmor: 0.25}), 10);
    assert.equal(combatRules.hitAmount(30, 'boss', {playerArmor: 0.25}), 23);
});

test('hitAmount: attack multiplies the hit of the side that has it', () => {
    assert.equal(combatRules.hitAmount(10, 'boss', {bossAttack: 1.25}), 13);
    assert.equal(combatRules.hitAmount(10, 'player', {bossAttack: 1.25}), 10);
});

test('hitAmount: armor never absorbs more than ARMOR_CAP', () => {
    assert.equal(combatRules.hitAmount(100, 'player', {bossArmor: 0.95}), 100 * (1 - combatRules.ARMOR_CAP));
});

test('hitAmount: a hit with no attack or armor keeps its exact value', () => {
    assert.equal(combatRules.hitAmount(33.3, 'player', {}), 33.3);
    assert.equal(combatRules.hitAmount(33.3, 'boss', {bossArmor: 0.25}), 33.3);
});

test('armor softens a critical answer but never the wrong-answer penalty', () => {
    const state = freshState();
    const config = Object.assign({}, CONFIG, {bossArmor: 0.25, playerArmor: 0.25});
    assert.equal(combatRules.questionOutcome('player', true, state, config).hitBoss, 23);
    assert.equal(combatRules.questionOutcome('boss', true, state, config).hitPlayer, 23);
    assert.equal(combatRules.questionOutcome('player', false, state, config).hitPlayer, combatRules.WRONG_ANSWER_DAMAGE);
});

test('armor softens the board Swords and the Sword consumable', () => {
    const config = Object.assign({}, CONFIG, {bossArmor: 0.25});
    assert.equal(combatRules.matchHitAmount('player', 15, freshState(), config), 11);
    assert.equal(combatRules.resolveConsumableEffect('sword', freshState(), config).damageAmount, 8);
});

test('armor does not soften a poison tick', () => {
    const state = Object.assign(freshState(), {bossPoisonRounds: 2});
    const config = Object.assign({}, CONFIG, {bossArmor: 0.25});
    assert.equal(combatRules.startTurn('boss', state, config).state.currentHp, state.currentHp - CONFIG.baseDamage);
});

test('initialState: an opponent with iceshield starts with its Shield armed', () => {
    const plain = combatRules.initialState(100, 200);
    const shielded = combatRules.initialState(100, 200, {bossPowers: [combatRules.POWERS.ICESHIELD]});
    assert.equal(plain.bossShieldReady, false);
    assert.deepEqual(shielded, Object.assign({}, plain, {bossShieldReady: true}));
});

test('initialState: an opponent with longpoison starts with its poison meter 20% full', () => {
    const plain = combatRules.initialState(100, 200);
    const cursed = combatRules.initialState(100, 200, {bossPowers: [combatRules.POWERS.LONGPOISON]});
    assert.deepEqual(cursed, Object.assign({}, plain, {bossPoisonMeter: 20}));
});

test('resolvePoisonMeters: an opponent with longpoison arms one more round against the student only', () => {
    const state = Object.assign(freshState(), {playerPoisonMeter: 100, bossPoisonMeter: 110});
    combatRules.resolvePoisonMeters(state, {bossPowers: [combatRules.POWERS.LONGPOISON]});
    assert.equal(state.playerPoisonRounds, 4);
    assert.equal(state.bossPoisonRounds, 3);
    assert.equal(state.bossPoisonMeter, 10);
});

test('resolveMatchEffects: an opponent with fastgrimoire fills more of its poison meter per Grimoire', () => {
    const config = Object.assign({}, CONFIG, {bossPowers: [combatRules.POWERS.FASTGRIMOIRE]});
    const groups = [{type: 1, size: 3}];
    const boss = combatRules.resolveMatchEffects({currentTurn: 'boss', destroyedTypes: [1, 1, 1], matchGroups: groups,
        state: freshState(), config});
    const player = combatRules.resolveMatchEffects({currentTurn: 'player', destroyedTypes: [1, 1, 1], matchGroups: groups,
        state: freshState(), config});
    assert.equal(boss.state.bossPoisonMeter, 45);
    assert.equal(player.state.playerPoisonMeter, 30);
});

test('pieceGains: an opponent with manaboost gets more mana per Orb, the student the usual', () => {
    const config = Object.assign({}, CONFIG, {bossPowers: [combatRules.POWERS.MANABOOST]});
    assert.equal(combatRules.pieceGains('boss', config).mana, 30);
    assert.equal(combatRules.pieceGains('player', config).mana, 20);
    assert.equal(combatRules.pieceGains('boss', CONFIG).mana, 20);
});

test('pieceGains: an opponent with rootheal heals half as much again per Potion, the student the usual', () => {
    const config = Object.assign({}, CONFIG, {bossPowers: [combatRules.POWERS.ROOTHEAL]});
    assert.equal(combatRules.pieceGains('boss', config).heal, 3.75);
    assert.equal(combatRules.pieceGains('player', config).heal, 2.5);
});
