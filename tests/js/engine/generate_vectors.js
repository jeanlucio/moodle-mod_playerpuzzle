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
    {name: 'easy', seed: 102, boss: 240, damage: 12, coin: 10, student: 150, difficulty: 'easy',
        typeOffset: 1, playerAnswers: ['right'], bossAnswers: ['wrong']},
    {name: 'hard', seed: 103, boss: 120, damage: 8, coin: 5, student: 300, difficulty: 'hard',
        typeOffset: 2, playerAnswers: ['right', 'right', 'wrong'], bossAnswers: ['right']},
    {name: 'level 2 phase 5', seed: 104, boss: 150, damage: 10, coin: 10, student: 200, level: 2, phase: 5,
        typeOffset: 3, playerAnswers: ['wrong', 'right'], bossAnswers: ['wrong', 'right']},
    {name: 'level 3 phase 10', seed: 105, boss: 100, damage: 9, coin: 15, student: 180, level: 3, phase: 10,
        typeOffset: 4, playerAnswers: ['right'], bossAnswers: ['right', 'wrong', 'wrong']},
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
    {name: 'armored opponent, Sword consumables', opponent: 'golem', seed: 116, boss: 180, damage: 11, coin: 10,
        student: 240, typeOffset: 0, playerAnswers: ['right', 'wrong'], bossAnswers: ['right', 'wrong'],
        consumables: {'1': 'sword', '3': 'sword', '5': 'shield'}},
    {name: 'tough opponent', opponent: 'slime', seed: 117, boss: 175, damage: 10, coin: 10, student: 230,
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
    {name: 'final boss, no profile', opponent: 'vampire', seed: 120, boss: 100, damage: 9, coin: 15, student: 180,
        level: 3, phase: 10, typeOffset: 5, playerAnswers: ['right'], bossAnswers: ['right', 'wrong']},
];

// The profile modifiers of the opponents the replay cases fight. The table itself lives only on
// the server (opponents::modifiers()); replay_vectors_test.php checks each case's copy against it,
// and the same for their powers below.
const OPPONENT_MODIFIERS = {
    goblin: {hp: 1, attack: 1.25, armor: 0},
    golem: {hp: 1, attack: 1, armor: 0.25},
    slime: {hp: 1.3, attack: 1, armor: 0},
    plant: {hp: 1, attack: 1, armor: 0.25},
    dragon: {hp: 1, attack: 1.25, armor: 0},
    mummy: {hp: 1, attack: 1.25, armor: 0},
    spider: {hp: 1.3, attack: 1, armor: 0},
    wolf: {hp: 1, attack: 1.25, armor: 0},
    vampire: {hp: 1, attack: 1, armor: 0},
};

// Attack and armor combinations for the rules that take them, including ones no opponent has
// today (the student's own, an armor past the cap) so the rule itself is covered.
const OPPONENT_POWERS = {
    golem: ['iceshield'],
    mummy: ['longpoison'],
    spider: ['fastgrimoire'],
    wolf: ['manaboost'],
};

// Power lists for the rules that read them, one per power plus none and an unknown name. Picked
// at random by the cases that use it, so it is not extended: a new power goes in EVERY_POWER.
const POWER_CONFIGS = [[], ['iceshield'], ['longpoison'], ['fastgrimoire'], ['unknown']];

// Every power, gone through one by one by the per-piece gains. A new power is appended here.
const EVERY_POWER = ['iceshield', 'longpoison', 'fastgrimoire', 'manaboost'];

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
    [1, 15, 99, 1000, 2501].forEach(max => cases.push(recordCall('reviveHp', [max])));
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
        if (spec.opponent) {
            attempt.frozenopponent = spec.opponent;
        }
        const playerpuzzle = {minquestions: spec.minquestions || 0, basestudenthp: spec.student};

        const recorded = ReplayMirror.record({
            attempt, playerpuzzle, modifiers, powers, poolEmpty: true, policy: makePolicy(spec),
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

module.exports = {COMBAT_FIXTURE, REPLAY_FIXTURE, buildCombatVectors, buildReplayVectors, serialise, serialiseCalls};

if (require.main === module) {
    fs.writeFileSync(COMBAT_FIXTURE, serialiseCalls(buildCombatVectors()));
    fs.writeFileSync(REPLAY_FIXTURE, serialise(buildReplayVectors()));
    process.stdout.write(`Wrote ${COMBAT_FIXTURE} and ${REPLAY_FIXTURE}\n`);
}
