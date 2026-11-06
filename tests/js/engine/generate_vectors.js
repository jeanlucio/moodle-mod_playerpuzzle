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
 * Builds the cross-language parity fixtures from the JavaScript engine.
 *
 * Run from the plugin root after any change to amd/src/engine/ or to the replay mirror:
 *
 *     node tests/js/engine/generate_vectors.js
 *
 * It rewrites tests/fixtures/combat_vectors.json (calls to combat_rules.js and what they
 * returned) and tests/fixtures/replay_vectors.json (whole recorded phases). Never edit either
 * by hand: tests/js/engine/vectors.test.js fails when they no longer match what this script
 * builds, and tests/local/engine/combat_vectors_test.php and replay_vectors_test.php fail when
 * the PHP engine disagrees with them. Regenerate only after understanding why the expected
 * values moved.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module, process, __dirname */

'use strict';

const fs = require('fs');
const path = require('path');
const BoardRules = require('../../../amd/src/engine/board_rules.js');
const CombatRules = require('../../../amd/src/engine/combat_rules.js');
const Prng = require('../../../amd/src/engine/prng.js');
const ReplayMirror = require('./replay_mirror.js');

const COMBAT_FIXTURE = path.join(__dirname, '..', '..', 'fixtures', 'combat_vectors.json');
const REPLAY_FIXTURE = path.join(__dirname, '..', '..', 'fixtures', 'replay_vectors.json');

// Piece types the recording policy prefers in turn (Sword, Coin, Orb, Star, Grimoire,
// Shield, Potion), so different matches exercise different effects.
const PREFERRED_TYPES = [3, 6, 2, 0, 1, 4, 5];

/**
 * The player's own turn number (0, 1, 2...) from runPhase()'s turn counter, which counts the
 * boss's turns too: the player always moves first, so their turns are the even ones.
 *
 * @param {number} turnCount runPhase()'s turn counter.
 * @return {number}
 */
function playerTurn(turnCount) {
    return Math.floor(turnCount / 2);
}

/**
 * A deterministic recording policy: which swap the player makes, which consumable they use at
 * the start of a turn, and how each question ends.
 *
 * @param {object} variant Policy knobs: typeOffset, consumables (player turn => kind),
 *  playerAnswers and bossAnswers (patterns of 'right', 'wrong', 'skipped', 'failed',
 *  'unavailable', cycled per question).
 * @return {object} The policy runPhase() calls.
 */
function makePolicy(variant) {
    const answer = mark => {
        if (mark === 'right') {
            return {outcome: 'answered', correct: true};
        }
        if (mark === 'wrong') {
            return {outcome: 'answered', correct: false};
        }
        return {outcome: mark};
    };

    return {
        move(grid, turnCount) {
            const type = PREFERRED_TYPES[(playerTurn(turnCount) + variant.typeOffset) % PREFERRED_TYPES.length];
            return BoardRules.findMove(grid, ReplayMirror.ROWS, ReplayMirror.COLS, type)
                || BoardRules.findMove(grid, ReplayMirror.ROWS, ReplayMirror.COLS, null);
        },
        consumable(turnCount, state, loggedUses) {
            const kind = (variant.consumables || {})[playerTurn(turnCount)];
            if (!kind || loggedUses[kind] >= CombatRules.phaseLimit(kind)) {
                return null;
            }
            // The live client refuses a second Shield while one is still armed.
            if (kind === 'shield' && state.playerShieldReady) {
                return null;
            }
            return kind;
        },
        question(side, index) {
            const pattern = side === 'player' ? variant.playerAnswers : variant.bossAnswers;
            return answer(pattern[index % pattern.length]);
        },
    };
}

// Each entry is one recorded phase. Together they cover every difficulty, scaled levels and
// phases, the revive rule, every consumable, every way a question can end, and both a won and
// a lost match.
const REPLAY_CASES = [
    {name: 'normal, plain', seed: 101, boss: 200, damage: 10, coin: 10, student: 200,
        typeOffset: 0, playerAnswers: ['right', 'wrong'], bossAnswers: ['right', 'wrong']},
    {name: 'opening missed, the opponent moves first', seed: 191, boss: 150, damage: 10, coin: 10, student: 240,
        typeOffset: 2, playerAnswers: ['wrong', 'right', 'wrong'], bossAnswers: ['right', 'wrong']},
    {name: 'opening skipped, the opponent moves first', seed: 192, boss: 150, damage: 10, coin: 10, student: 240,
        typeOffset: 4, playerAnswers: ['skipped', 'right'], bossAnswers: ['wrong']},
    {name: 'easy', seed: 102, boss: 240, damage: 12, coin: 10, student: 150, difficulty: 'easy',
        typeOffset: 1, playerAnswers: ['right'], bossAnswers: ['wrong']},
    {name: 'hard', seed: 103, boss: 120, damage: 8, coin: 5, student: 300, difficulty: 'hard',
        typeOffset: 2, playerAnswers: ['right', 'right', 'wrong'], bossAnswers: ['right']},
    {name: 'level 2 phase 5', seed: 104, boss: 150, damage: 10, coin: 10, student: 200, level: 2, phase: 5,
        maxlevels: 3, typeOffset: 3, playerAnswers: ['wrong', 'right'], bossAnswers: ['wrong', 'right']},
    {name: 'level 3 phase 10', seed: 105, boss: 100, damage: 9, coin: 15, student: 180, level: 3, phase: 10,
        maxlevels: 3, typeOffset: 4, playerAnswers: ['right'], bossAnswers: ['right', 'wrong', 'wrong']},
    {name: 'revive with minquestions', seed: 106, boss: 80, damage: 15, coin: 10, student: 400, minquestions: 4,
        typeOffset: 0, playerAnswers: ['right', 'wrong'], bossAnswers: ['wrong']},
    {name: 'every consumable', seed: 107, boss: 260, damage: 11, coin: 10, student: 220,
        typeOffset: 5, playerAnswers: ['right', 'wrong'], bossAnswers: ['right', 'wrong'],
        consumables: {'0': 'magic', '1': 'shield', '3': 'potion', '5': 'sword', '7': 'potion', '9': 'sword'}},
    {name: 'skipped and failed answers', seed: 108, boss: 220, damage: 10, coin: 10, student: 250,
        typeOffset: 6, playerAnswers: ['skipped', 'right', 'failed', 'wrong'], bossAnswers: ['right', 'wrong']},
    {name: 'unavailable questions', seed: 109, boss: 180, damage: 10, coin: 10, student: 250,
        typeOffset: 1, playerAnswers: ['unavailable'], bossAnswers: ['unavailable']},
    {name: 'a lost match', seed: 110, boss: 2000, damage: 25, coin: 10, student: 120,
        typeOffset: 2, playerAnswers: ['wrong'], bossAnswers: ['right']},
    {name: 'coin focus', seed: 111, boss: 300, damage: 10, coin: 20, student: 300,
        typeOffset: 1, playerAnswers: ['right', 'wrong', 'wrong'], bossAnswers: ['wrong', 'right']},
    {name: 'odd base damage', seed: 112, boss: 175, damage: 13, coin: 7, student: 210,
        typeOffset: 3, playerAnswers: ['right', 'wrong'], bossAnswers: ['right', 'wrong'],
        consumables: {'2': 'magic', '4': 'potion'}},
    {name: 'hard, minquestions, consumables', seed: 113, boss: 90, damage: 7, coin: 10, student: 350,
        difficulty: 'hard', minquestions: 3, typeOffset: 4, playerAnswers: ['right', 'wrong', 'right'],
        bossAnswers: ['wrong'], consumables: {'1': 'shield', '6': 'sword'}},
    {name: 'easy, level 4', seed: 114, boss: 140, damage: 10, coin: 10, student: 160, difficulty: 'easy',
        level: 4, phase: 3, typeOffset: 5, playerAnswers: ['right'], bossAnswers: ['right', 'wrong']},
    {name: 'brute opponent', opponent: 'goblin', seed: 115, boss: 200, damage: 12, coin: 10, student: 220,
        typeOffset: 2, playerAnswers: ['right', 'wrong'], bossAnswers: ['right', 'wrong']},
    {name: 'brute opponent, growing attack late in a Campaign', opponent: 'goblin', seed: 190, boss: 150, damage: 10,
        coin: 10, student: 260, level: 2, phase: 7, maxlevels: 3, typeOffset: 1, playerAnswers: ['right', 'wrong'],
        bossAnswers: ['right', 'wrong']},
    {name: 'armored opponent, Sword consumables', opponent: 'golem', seed: 116, boss: 180, damage: 11, coin: 10,
        student: 240, typeOffset: 0, playerAnswers: ['right', 'wrong'], bossAnswers: ['right', 'wrong'],
        consumables: {'1': 'sword', '3': 'sword', '5': 'shield'}},
    {name: 'regenerating opponent', opponent: 'slime', seed: 117, boss: 175, damage: 10, coin: 10, student: 230,
        typeOffset: 3, playerAnswers: ['right', 'right', 'wrong'], bossAnswers: ['wrong', 'right']},
    {name: 'armored opponent, hard, level 2', opponent: 'plant', seed: 118, boss: 110, damage: 9, coin: 10,
        student: 320, difficulty: 'hard', level: 2, phase: 5, typeOffset: 4, playerAnswers: ['right', 'wrong'],
        bossAnswers: ['right']},
    {name: 'brute opponent, minquestions', opponent: 'dragon', seed: 119, boss: 90, damage: 13, coin: 10,
        student: 400, minquestions: 4, phase: 10, typeOffset: 1, playerAnswers: ['right', 'skipped', 'wrong'],
        bossAnswers: ['right', 'wrong']},
    {name: 'shield armed from the start, a lost match', opponent: 'golem', seed: 121, boss: 2000, damage: 20, coin: 10,
        student: 150, typeOffset: 0, playerAnswers: ['right', 'wrong'], bossAnswers: ['right']},
    {name: 'longer poison, a lost match', opponent: 'mummy', seed: 122, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 4, playerAnswers: ['wrong'], bossAnswers: ['wrong']},
    {name: 'fast Grimoire, a lost match', opponent: 'spider', seed: 123, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 2, playerAnswers: ['wrong'], bossAnswers: ['wrong']},
    {name: 'more mana, a lost match', opponent: 'wolf', seed: 124, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 1, playerAnswers: ['wrong'], bossAnswers: ['right', 'wrong']},
    {name: 'Potions heal half as much again, a lost match', opponent: 'plant', seed: 125, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 3, playerAnswers: ['wrong'], bossAnswers: ['wrong']},
    {name: 'regeneration, a lost match', opponent: 'slime', seed: 126, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 0, playerAnswers: ['wrong'], bossAnswers: ['wrong']},
    {name: 'ambush, a lost match', opponent: 'goblin', seed: 128, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 1, playerAnswers: ['wrong'], bossAnswers: ['wrong']},
    {name: 'intangible, a lost match', opponent: 'ghost', seed: 129, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 0, playerAnswers: ['right', 'wrong'], bossAnswers: ['wrong']},
    {name: 'breath, a lost match', opponent: 'dragon', seed: 130, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 2, playerAnswers: ['wrong'], bossAnswers: ['wrong']},
    {name: 'loot, a lost match', opponent: 'pirate', seed: 131, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 1, playerAnswers: ['wrong'], bossAnswers: ['wrong']},
    {name: 'drain, a lost match', opponent: 'vampire', seed: 127, boss: 3000, damage: 12, coin: 10,
        student: 400, typeOffset: 4, playerAnswers: ['wrong'], bossAnswers: ['right', 'wrong']},
    {name: 'final boss, drain and extra HP', opponent: 'vampire', seed: 120, boss: 100, damage: 9, coin: 15, student: 180,
        level: 3, phase: 10, typeOffset: 5, playerAnswers: ['right'], bossAnswers: ['right', 'wrong']},
    // The final boss fights the student's hero with a second power of its own: the armor against the strong
    // in attack, the fast Grimoire against the strong in armor, the breath at its own rhythm against the strong in HP.
    {name: 'final boss against a warrior: armor', opponent: 'vampire', hero: 'warriorf4', seed: 143, boss: 400,
        damage: 12, coin: 10, student: 300, typeOffset: 0, playerAnswers: ['right', 'wrong'], bossAnswers: ['right', 'wrong']},
    {name: 'final boss against an archer: fast Grimoire', opponent: 'vampire', hero: 'archerm3', seed: 144, boss: 400,
        damage: 12, coin: 10, student: 300, typeOffset: 3, playerAnswers: ['right', 'wrong'], bossAnswers: ['right', 'wrong']},
    {name: 'final boss against a mage: breath', opponent: 'vampire', hero: 'magem2', seed: 145, boss: 2500,
        damage: 14, coin: 10, student: 300, typeOffset: 1, playerAnswers: ['wrong'], bossAnswers: ['right', 'wrong']},
    // The instance's base HP is deliberately far from the frozen one: the phase fights with what it froze.
    {name: 'frozen student side: more HP and attack', seed: 140, boss: 300, damage: 12, coin: 10, student: 40,
        frozenStudent: {hp: 260, attack: 1.3, armor: 0}, typeOffset: 0, playerAnswers: ['right', 'wrong'],
        bossAnswers: ['wrong', 'right']},
    {name: 'frozen student side: armor against a goblin', opponent: 'goblin', seed: 141, boss: 250, damage: 14, coin: 10,
        student: 40, frozenStudent: {hp: 180, attack: 1, armor: 0.3}, typeOffset: 2, playerAnswers: ['right', 'wrong'],
        bossAnswers: ['right', 'right']},
    {name: 'frozen student side: attack and armor, a lost match', opponent: 'dragon', seed: 142, boss: 3000, damage: 14,
        coin: 10, student: 500, frozenStudent: {hp: 120, attack: 1.15, armor: 0.15}, typeOffset: 1,
        playerAnswers: ['wrong'], bossAnswers: ['right']},
];

// The profile modifiers of the opponents the replay cases fight. The table itself lives only on
// the server (opponents::modifiers()); replay_vectors_test.php checks each case's copy against it,
// and the same for their powers below.
const OPPONENT_MODIFIERS = {
    goblin: {hp: 1, attack: 1.15, armor: 0},
    golem: {hp: 1, attack: 1, armor: 0.15},
    slime: {hp: 1, attack: 1, armor: 0},
    plant: {hp: 1, attack: 1, armor: 0.15},
    dragon: {hp: 1, attack: 1.15, armor: 0},
    mummy: {hp: 1, attack: 1.15, armor: 0},
    spider: {hp: 1.15, attack: 1, armor: 0},
    wolf: {hp: 1, attack: 1.15, armor: 0},
    vampire: {hp: 1.2, attack: 1, armor: 0},
    ghost: {hp: 1, attack: 1, armor: 0},
    pirate: {hp: 1, attack: 1.15, armor: 0},
};

// What the final boss adds by the specialty of the student's hero, and the specialty of each hero, as
// opponents::counter() and heroes::CATALOG have them: replay_vectors_test.php checks each case's copy
// against the server's.
const FINAL_BOSS_COUNTERS = {
    attack: {armor: 0.15, powers: [], breathevery: 0},
    armor: {armor: 0, powers: ['fastgrimoire'], breathevery: 0},
    hp: {armor: 0, powers: ['breath'], breathevery: 12},
};
const HERO_SPECIALTY = {
    warriorf: 'attack', warriorm: 'attack', magef: 'hp', magem: 'hp', archerm: 'armor', archerf: 'armor',
};

/**
 * What an opponent adds to its fight because of the student's hero: only the final boss has anything, and a
 * student whose hero is not known is fought as the warrior.
 *
 * @param {string} opponent The opponent's key.
 * @param {string} sprite The hero's sprite the phase froze, or ''.
 * @return {{armor: number, powers: Array<string>, breathevery: number}}
 */
function counterFor(opponent, sprite) {
    if (opponent !== 'vampire') {
        return {armor: 0, powers: [], breathevery: 0};
    }
    return FINAL_BOSS_COUNTERS[HERO_SPECIALTY[sprite.replace(/\d+$/, '')] || 'attack'];
}

/**
 * The modifiers, powers and breath rhythm an opponent fights with once its counter is added.
 *
 * @param {object} modifiers The opponent's own {hp, attack, armor}.
 * @param {Array<string>} powers The opponent's own powers.
 * @param {{armor: number, powers: Array<string>, breathevery: number}} counter See counterFor().
 * @return {{modifiers: object, powers: Array<string>, breathEvery: number}}
 */
function withCounter(modifiers, powers, counter) {
    return {
        modifiers: Object.assign({}, modifiers, {armor: modifiers.armor + counter.armor}),
        powers: [...new Set([...powers, ...counter.powers])],
        breathEvery: counter.breathevery,
    };
}

// Attack and armor combinations for the rules that take them, including ones no opponent has
// today (the student's own, an armor past the cap) so the rule itself is covered.
const OPPONENT_POWERS = {
    golem: ['iceshield'],
    mummy: ['longpoison'],
    spider: ['fastgrimoire'],
    wolf: ['manaboost'],
    plant: ['rootheal'],
    slime: ['regen'],
    goblin: ['ambush'],
    ghost: ['intangible'],
    dragon: ['breath'],
    pirate: ['loot'],
    vampire: ['drain'],
};

// Power lists for the rules that read them, one per power plus none and an unknown name. Picked
// at random by the cases that use it, so it is not extended: a new power goes in EVERY_POWER.
const POWER_CONFIGS = [[], ['iceshield'], ['longpoison'], ['fastgrimoire'], ['unknown']];

// Every power, gone through one by one by the per-piece gains. A new power is appended here.
const EVERY_POWER = [
    'iceshield', 'longpoison', 'fastgrimoire', 'manaboost', 'rootheal', 'regen', 'drain', 'ambush', 'intangible',
    'breath', 'loot',
];

const HIT_CONFIGS = [
    {},
    {bossAttack: 1.25},
    {bossArmor: 0.25},
    {bossAttack: 1.25, bossArmor: 0.25},
    {playerArmor: 0.3},
    {playerAttack: 1.2, bossArmor: 0.25},
    {bossArmor: 0.9},
    {bossAttack: 1, bossArmor: 0},
];

/**
 * A small helper over a seeded PRNG for building varied, reproducible arguments.
 *
 * @param {number} seed PRNG seed.
 * @return {object} int(min, max), pick(list), chance(p) and step(min, max, step).
 */
function randomSource(seed) {
    const next = Prng.create(seed);
    const int = (min, max) => min + Math.floor(next() * (max - min + 1));
    return {
        int,
        pick: list => list[int(0, list.length - 1)],
        chance: p => next() < p,
        step: (min, max, step) => min + (step * int(0, Math.floor((max - min) / step))),
    };
}

/**
 * A combat state with plausible values, sometimes with a meter already past 100 so the
 * threshold rules fire.
 *
 * @param {object} rand See randomSource().
 * @return {object}
 */
function randomState(rand) {
    const maxPlayerHp = rand.int(50, 600);
    const maxBossHp = rand.int(50, 3000);
    const meter = () => (rand.chance(0.2) ? rand.step(100, 190, 10) : rand.step(0, 90, 10));
    return {
        currentPlayerHp: rand.step(0, maxPlayerHp * 4, 1) / 4, maxPlayerHp,
        currentHp: rand.step(0, maxBossHp * 4, 1) / 4, maxBossHp,
        playerMultiplier: rand.step(10, 30, 1) / 10, bossMultiplier: rand.step(10, 30, 1) / 10,
        playerShieldMeter: meter(), playerShieldReady: rand.chance(0.3),
        bossShieldMeter: meter(), bossShieldReady: rand.chance(0.3),
        playerPoisonMeter: meter(), playerPoisonRounds: rand.int(0, 6),
        bossPoisonMeter: meter(), bossPoisonRounds: rand.int(0, 6),
        playerMana: rand.chance(0.2) ? rand.step(100, 160, 20) : rand.step(0, 80, 20),
        bossMana: rand.chance(0.2) ? rand.step(100, 160, 20) : rand.step(0, 80, 20),
        playerGold: rand.step(0, 400, 1) / 2, bossGold: rand.step(0, 400, 1) / 2,
        bossTurnCount: rand.int(0, 4),
        bossPotions: rand.int(0, 3), bossSwords: rand.int(0, 3),
    };
}

/**
 * Calls one combat_rules.js function and records the call. The arguments are copied first, so
 * a function that mutates its input still records what it was given.
 *
 * @param {string} fn The function name.
 * @param {Array} args The arguments, in call order.
 * @return {object} {fn, args, result}.
 */
function recordCall(fn, args) {
    const given = JSON.parse(JSON.stringify(args));
    return {fn, args: given, result: CombatRules[fn](...args)};
}

/**
 * Adds the cases for the opponent holding on at its last HP while questions are missing: when it applies, and the
 * student's Orb filling the whole mana bar then.
 *
 * @param {Array} cases The combat cases, added to in place.
 */
function addOrbFullCases(cases) {
    [[{currentHp: 1}, 5, 3], [{currentHp: 1}, 5, 5], [{currentHp: 1}, 0, 0], [{currentHp: 40}, 5, 3], [{currentHp: 0}, 3, 1]]
        .forEach(([state, min, total]) => cases.push(recordCall('bossResists', [state, min, total])));
    const orbRand = randomSource(20261112);
    for (let i = 0; i < 20; i++) {
        cases.push(recordCall('resolveMatchEffects', [{
            currentTurn: orbRand.pick(['player', 'boss']),
            destroyedTypes: [2, orbRand.int(0, 6), orbRand.int(0, 6)],
            matchGroups: [],
            state: randomState(orbRand),
            config: {baseDamage: orbRand.int(1, 60), coinGain: 10, coinFactor: 1, orbFull: true},
        }]));
    }
}

/**
 * Calls every pure combat rule over fixed edge cases and seeded random arguments.
 *
 * @return {Array} The combat vectors, in fixture shape.
 */
function buildCombatVectors() {
    const rand = randomSource(20261031);
    const cases = [];

    for (let size = 3; size <= 8; size++) {
        cases.push(recordCall('comboMultiplier', [size]));
    }
    ['shield', 'magic', 'potion', 'sword'].forEach(type => cases.push(recordCall('phaseLimit', [type])));
    [[0, 0], [3, 2], [3, 3], [5, 9]].forEach(args => cases.push(recordCall('needsRevive', args)));
    cases.push(recordCall('reviveHp', []));
    [
        {gamemode: 'single', currentphase: 3, currentlevel: 1, maxlevels: 1},
        {gamemode: 'campaign', currentphase: 9, currentlevel: 1, maxlevels: 1},
        {gamemode: 'campaign', currentphase: 10, currentlevel: 1, maxlevels: 1},
        {gamemode: 'campaign', currentphase: 10, currentlevel: 2, maxlevels: 3},
        {gamemode: 'campaign', currentphase: 10, currentlevel: 3, maxlevels: 3},
    ].forEach(config => cases.push(recordCall('hasNextPhase', [config])));

    for (let i = 0; i < 40; i++) {
        const state = randomState(rand);
        cases.push(recordCall('resolvePoisonMeters', [state]));
        cases.push(recordCall('resolveShieldMeters', [randomState(rand)]));
        cases.push(recordCall('resolveDamage', [rand.step(0, 4000, 1) / 4, rand.chance(0.3), rand.step(0, 800, 1) / 4]));
        cases.push(recordCall('resolvePoisonTick', [rand.step(0, 4000, 1) / 4, rand.int(0, 4), rand.int(1, 60)]));
    }

    for (let i = 0; i < 150; i++) {
        const destroyedTypes = [];
        const count = rand.int(3, 14);
        for (let j = 0; j < count; j++) {
            destroyedTypes.push(rand.int(0, 6));
        }
        const matchGroups = [];
        const groups = rand.int(1, 3);
        for (let j = 0; j < groups; j++) {
            matchGroups.push({type: rand.int(0, 6), size: rand.int(3, 7)});
        }
        cases.push(recordCall('resolveMatchEffects', [{
            currentTurn: rand.pick(['player', 'boss']),
            destroyedTypes,
            matchGroups,
            state: randomState(rand),
            config: {baseDamage: rand.int(1, 60), coinGain: rand.int(0, 25), coinFactor: rand.pick([0.5, 1, 3])},
        }]));
    }

    cases.push({fn: 'STATE_FIELDS', args: [], result: CombatRules.STATE_FIELDS});
    [[100, 1000], [1, 1], [372, 2501]].forEach(args => cases.push(recordCall('initialState', args)));
    [[100, 1000, {bossPowers: ['iceshield']}], [80, 300, {bossPowers: []}], [80, 300, {bossPowers: ['unknown']}]]
        .forEach(args => cases.push(recordCall('initialState', args)));

    // A source of its own, so adding these calls left every case recorded before them unchanged.
    const turnRand = randomSource(20261101);
    for (let i = 0; i < 40; i++) {
        cases.push(recordCall('matchHitAmount', [
            turnRand.pick(['player', 'boss']), turnRand.int(1, 60) * turnRand.pick([1, 1.5, 2, 2.5, 3]),
            randomState(turnRand), {},
        ]));
        cases.push(recordCall('questionOutcome', [
            turnRand.pick(['player', 'boss']), turnRand.chance(0.5), randomState(turnRand),
            {baseDamage: turnRand.int(1, 60)},
        ]));
        cases.push(recordCall('startTurn', [
            turnRand.pick(['player', 'boss']), randomState(turnRand), {baseDamage: turnRand.int(1, 60)},
        ]));
    }

    for (let i = 0; i < 40; i++) {
        cases.push(recordCall('resolveConsumableEffect', [
            rand.pick(['potion', 'shield', 'magic', 'sword']),
            randomState(rand),
            {baseDamage: rand.int(1, 60)},
        ]));
    }

    const hitRand = randomSource(20261102);
    cases.push({fn: 'ARMOR_CAP', args: [], result: CombatRules.ARMOR_CAP});
    HIT_CONFIGS.forEach(config => {
        ['player', 'boss'].forEach(attacker => {
            [1, 7, 10, 13, 33.00000000000001, 39.6, 45, 62.5, 150].forEach(amount => {
                cases.push(recordCall('hitAmount', [amount, attacker, config]));
            });
        });
    });
    for (let i = 0; i < 40; i++) {
        const config = hitRand.pick(HIT_CONFIGS);
        cases.push(recordCall('matchHitAmount', [
            hitRand.pick(['player', 'boss']), hitRand.int(1, 60) * hitRand.pick([1, 1.5, 2, 2.5, 3]),
            randomState(hitRand), config,
        ]));
        cases.push(recordCall('questionOutcome', [
            hitRand.pick(['player', 'boss']), hitRand.chance(0.5), randomState(hitRand),
            Object.assign({baseDamage: hitRand.int(1, 60)}, hitRand.pick(HIT_CONFIGS)),
        ]));
        cases.push(recordCall('resolveConsumableEffect', [
            hitRand.pick(['potion', 'sword', 'sword']), randomState(hitRand),
            Object.assign({baseDamage: hitRand.int(1, 60)}, hitRand.pick(HIT_CONFIGS)),
        ]));
    }

    ['player', 'boss'].forEach(side => {
        [[], ...EVERY_POWER.map(power => [power]), EVERY_POWER].forEach(powers => {
            [7, 10, 13].forEach(baseDamage => {
                cases.push(recordCall('pieceGains', [side, {baseDamage, bossPowers: powers}]));
            });
        });
    });

    const powerRand = randomSource(20261103);
    for (let i = 0; i < 20; i++) {
        cases.push(recordCall('resolvePoisonMeters', [randomState(powerRand), {bossPowers: powerRand.pick(POWER_CONFIGS)}]));
    }
    for (let i = 0; i < 30; i++) {
        const destroyedTypes = [];
        const count = powerRand.int(3, 14);
        for (let j = 0; j < count; j++) {
            destroyedTypes.push(powerRand.pick([1, 1, 1, 0, 2, 3, 4, 5, 6]));
        }
        cases.push(recordCall('resolveMatchEffects', [{
            currentTurn: powerRand.pick(['player', 'boss']),
            destroyedTypes,
            matchGroups: [{type: powerRand.int(0, 6), size: powerRand.int(3, 6)}],
            state: randomState(powerRand),
            config: {baseDamage: powerRand.int(1, 60), coinGain: powerRand.int(0, 25), coinFactor: 1,
                bossPowers: powerRand.pick(POWER_CONFIGS)},
        }]));
    }

    const turnPowerRand = randomSource(20261104);
    for (let i = 0; i < 30; i++) {
        cases.push(recordCall('startTurn', [
            turnPowerRand.pick(['player', 'boss', 'boss']), randomState(turnPowerRand),
            {baseDamage: turnPowerRand.int(1, 60), bossPowers: turnPowerRand.pick([[], ['regen'], ['regen', 'drain']])},
        ]));
        cases.push(recordCall('drainAmount', [
            turnPowerRand.pick([0, 7, 10, 13.5, 45, 150]), randomState(turnPowerRand),
            {bossPowers: turnPowerRand.pick([[], ['drain'], ['regen', 'drain']])},
        ]));
    }

    const ambushRand = randomSource(20261105);
    for (let i = 0; i < 30; i++) {
        const state = Object.assign(randomState(ambushRand), {bossTurnCount: ambushRand.int(0, 3)});
        const config = Object.assign({bossPowers: ambushRand.pick([[], ['ambush'], ['ambush', 'drain']])},
            ambushRand.pick(HIT_CONFIGS));
        cases.push(recordCall('matchHitAmount', [
            ambushRand.pick(['player', 'boss', 'boss']), ambushRand.int(1, 60) * ambushRand.pick([1, 1.5, 2]), state, config,
        ]));
    }

    const intangibleRand = randomSource(20261106);
    for (let i = 0; i < 30; i++) {
        const state = Object.assign(randomState(intangibleRand), {bossTurnCount: intangibleRand.int(0, 25)});
        cases.push(recordCall('startTurn', [
            intangibleRand.pick(['player', 'boss', 'boss']), state,
            {baseDamage: intangibleRand.int(1, 60), bossPowers: intangibleRand.pick([[], ['intangible'], ['intangible', 'regen']])},
        ]));
    }

    const breathRand = randomSource(20261107);
    for (let i = 0; i < 30; i++) {
        const state = Object.assign(randomState(breathRand), {bossTurnCount: breathRand.pick([8, 9, 9, 19, 29])});
        const config = Object.assign({baseDamage: breathRand.int(1, 60),
            bossPowers: breathRand.pick([[], ['breath'], ['breath', 'drain'], ['breath', 'intangible']])},
            breathRand.pick(HIT_CONFIGS));
        cases.push(recordCall('startTurn', [breathRand.pick(['player', 'boss', 'boss', 'boss']), state, config]));
    }

    const weightRand = randomSource(20261109);
    for (let i = 0; i < 40; i++) {
        cases.push(recordCall('opponentMoveWeights', [randomState(weightRand), {
            difficulty: weightRand.pick(['easy', 'normal', 'hard', 'hard']),
            bossPowers: weightRand.pick([[], ...EVERY_POWER.map(power => [power]), ['ambush', 'drain', 'breath']]),
        }]));
    }

    [1, 2, 3, 4, 6, 9].forEach(level => {
        ['easy', 'normal', 'hard'].forEach(difficulty => {
            [false, true].forEach(finalBoss => cases.push(recordCall('opponentKit', [level, difficulty, finalBoss])));
        });
    });
    const itemRand = randomSource(20261110);
    for (let i = 0; i < 40; i++) {
        const config = Object.assign({baseDamage: itemRand.int(1, 60), bossPowers: itemRand.pick([[], ['drain']])},
            itemRand.pick(HIT_CONFIGS));
        cases.push(recordCall('opponentItemUse', [randomState(itemRand), config]));
        cases.push(recordCall('startTurn', ['boss', randomState(itemRand), config]));
    }

    // The breath at a rhythm of its own, as the final boss has against a hero strong in HP.
    [{}, {bossBreathEvery: 0}, {bossBreathEvery: 6}, {bossBreathEvery: 12}].forEach(config => {
        cases.push(recordCall('breathEvery', [config]));
    });
    const rhythmRand = randomSource(20261111);
    for (let i = 0; i < 30; i++) {
        const state = Object.assign(randomState(rhythmRand), {bossTurnCount: rhythmRand.pick([5, 6, 9, 11, 12, 23, 24])});
        const config = Object.assign({baseDamage: rhythmRand.int(1, 60), bossPowers: ['breath']}, rhythmRand.pick([
            {}, {bossBreathEvery: 6}, {bossBreathEvery: 12}, {bossBreathEvery: 12, bossArmor: 0.15},
        ]));
        cases.push(recordCall('startTurn', ['boss', state, config]));
    }

    addOrbFullCases(cases);

    const lootRand = randomSource(20261108);
    for (let i = 0; i < 30; i++) {
        const state = Object.assign(randomState(lootRand), {bossTurnCount: lootRand.pick([3, 3, 7, 4, 11])});
        cases.push(recordCall('startTurn', [
            lootRand.pick(['player', 'boss', 'boss']), state,
            Object.assign({baseDamage: lootRand.int(1, 60), coinGain: lootRand.pick([0, 5, 10, 25]),
                coinFactor: lootRand.pick([0.5, 1, 3]), bossPowers: lootRand.pick([[], ['loot'], ['loot', 'drain']])},
            lootRand.pick(HIT_CONFIGS)),
        ]));
    }

    return cases;
}

/**
 * Records every replay case.
 *
 * @return {Array} The replay vectors, in fixture shape.
 */
function buildReplayVectors() {
    return REPLAY_CASES.map(spec => {
        const attempt = {
            rngseed: spec.seed,
            currentlevel: spec.level || 1,
            currentphase: spec.phase || 1,
            difficulty: spec.difficulty || 'normal',
            questionsTotal: 0,
            frozenbasebosshp: spec.boss,
            frozenbossdamage: spec.damage,
            frozencoingain: spec.coin,
        };
        const modifiers = spec.opponent ? OPPONENT_MODIFIERS[spec.opponent] : undefined;
        const powers = spec.opponent ? OPPONENT_POWERS[spec.opponent] || [] : undefined;
        const counter = spec.opponent ? counterFor(spec.opponent, spec.hero || '') : undefined;
        if (spec.opponent) {
            attempt.frozenopponent = spec.opponent;
        }
        if (spec.hero) {
            attempt.frozenhero = spec.hero;
        }
        if (spec.frozenStudent) {
            attempt.frozenplayerhp = spec.frozenStudent.hp;
            attempt.frozenplayerattack = spec.frozenStudent.attack;
            attempt.frozenplayerarmor = spec.frozenStudent.armor;
        }
        const playerpuzzle = {
            minquestions: spec.minquestions || 0, basestudenthp: spec.student, maxlevels: spec.maxlevels || 1,
        };

        const fights = spec.opponent ? withCounter(modifiers, powers, counter) : {};
        const recorded = ReplayMirror.record({
            attempt, playerpuzzle, modifiers: fights.modifiers, powers: fights.powers, breathEvery: fights.breathEvery,
            poolEmpty: true, policy: makePolicy(spec),
        });
        if (recorded.result === null) {
            throw new Error(`Replay case "${spec.name}" did not reach a conclusive end.`);
        }
        attempt.questionsTotal = recorded.outcomes.filter(outcome => outcome.counted).length;

        const vector = {
            name: spec.name,
            attempt,
            playerpuzzle,
        };
        if (modifiers) {
            vector.modifiers = modifiers;
            vector.powers = powers;
            vector.counter = counter;
        }
        return Object.assign(vector, {
            events: recorded.events,
            outcomes: recorded.outcomes,
            serveruses: recorded.serverUses,
            expected: recorded.result,
        });
    });
}

/**
 * Serialises the combat fixture with one call per line, so a regenerated file diffs readably.
 *
 * @param {Array} vectors The vectors.
 * @return {string} The file contents.
 */
function serialiseCalls(vectors) {
    return `[\n${vectors.map(vector => `    ${JSON.stringify(vector)}`).join(',\n')}\n]\n`;
}

/**
 * Serialises the replay fixture with one event or outcome per line, so a regenerated file
 * diffs readably.
 *
 * @param {Array} vectors The vectors.
 * @return {string} The file contents.
 */
function serialise(vectors) {
    const lines = ['['];
    vectors.forEach((vector, index) => {
        const entries = Object.entries(vector).map(([key, value]) => {
            if (Array.isArray(value)) {
                const items = value.map(item => `            ${JSON.stringify(item)}`);
                return items.length
                    ? `        ${JSON.stringify(key)}: [\n${items.join(',\n')}\n        ]`
                    : `        ${JSON.stringify(key)}: []`;
            }
            return `        ${JSON.stringify(key)}: ${JSON.stringify(value)}`;
        });
        lines.push(`    {\n${entries.join(',\n')}\n    }${index < vectors.length - 1 ? ',' : ''}`);
    });
    lines.push(']');
    return `${lines.join('\n')}\n`;
}

module.exports = {
    COMBAT_FIXTURE, REPLAY_FIXTURE, buildCombatVectors, buildReplayVectors, serialise, serialiseCalls, withCounter,
};

if (require.main === module) {
    fs.writeFileSync(COMBAT_FIXTURE, serialiseCalls(buildCombatVectors()));
    fs.writeFileSync(REPLAY_FIXTURE, serialise(buildReplayVectors()));
    process.stdout.write(`Wrote ${COMBAT_FIXTURE} and ${REPLAY_FIXTURE}\n`);
}
