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
 * Headless tests for the JavaScript replay mirror, run via `node --test`.
 *
 * Every case below is one tests/local/engine/replay_test.php already pins on the PHP side,
 * with the same seed, log, server outcomes and expected totals: the mirror reaching the same
 * answers is what shows it follows replay::derive()'s turn order, before it is trusted to
 * record new matches for tests/fixtures/replay_vectors.json.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const ReplayMirror = require('./replay_mirror.js');

/**
 * Builds a verification call with replay_test.php's own make_attempt()/make_playerpuzzle()
 * defaults, overridable per case.
 *
 * @param {object} attempt Attempt overrides.
 * @param {object} playerpuzzle Instance overrides.
 * @param {Array} events The recorded log.
 * @param {Array} outcomes Server outcomes, as [side, correct, counted].
 * @param {object} serverUses Consumable uses the server counted.
 * @return {object|null} What the mirror derives.
 */
function derive(attempt, playerpuzzle, events, outcomes = [], serverUses = {}) {
    return deriveRaw(attempt, playerpuzzle, [NO_QUESTION_OPENING, ...events], outcomes, serverUses);
}

/**
 * Like derive(), but with the log exactly as given: every real phase opens with a question that decides who moves
 * first, and derive() supplies the one asked when there was no question to draw, so the student moves first.
 *
 * @param {object} attempt Attempt overrides.
 * @param {object} playerpuzzle Instance overrides.
 * @param {Array} events The recorded log, opening included.
 * @param {Array} outcomes Server outcomes, as [side, correct, counted].
 * @param {object} serverUses Consumable uses the server counted.
 * @return {object|null} What the mirror derives.
 */
function deriveRaw(attempt, playerpuzzle, events, outcomes = [], serverUses = {}) {
    const decoded = outcomes.map(([side, correct, counted]) => ({side, correct, counted}));
    return ReplayMirror.derive({
        attempt: Object.assign({
            rngseed: 1, currentlevel: 1, currentphase: 1, difficulty: 'normal',
            questionsTotal: 0, frozenbasebosshp: 1000, frozenbossdamage: 10, frozencoingain: 10,
        }, attempt),
        playerpuzzle: Object.assign({minquestions: 0, basestudenthp: 1000}, playerpuzzle),
        events,
        outcomes: decoded,
        serverUses,
        poolEmpty: true,
    });
}

const NO_QUESTION_OPENING = {type: 'question', side: 'player', outcome: 'unavailable'};

const seed2Win = [{type: 'move', r1: 3, c1: 1, r2: 3, c2: 2}];
const seed22Log = (bossOutcome) => [
    {type: 'move', r1: 2, c1: 5, r2: 2, c2: 6},
    {type: 'move', r1: 1, c1: 2, r2: 1, c2: 3},
    {type: 'question', side: 'boss', outcome: bossOutcome},
    {type: 'move', r1: 2, c1: 0, r2: 2, c2: 1},
    {type: 'move', r1: 0, c1: 4, r2: 0, c2: 5},
];
const seed56Log = (outcome) => [
    {type: 'move', r1: 0, c1: 7, r2: 1, c2: 7},
    {type: 'question', side: 'player', outcome},
];

test('a real single-move win on seed 2', () => {
    assert.deepEqual(
        derive({rngseed: 2, frozenbasebosshp: 10}, {}, seed2Win),
        {damage: 10, playergold: 15, bossgold: 0, bossdefeated: true}
    );
});

test('a logged Sword consumable is applied before the move', () => {
    assert.deepEqual(
        derive({rngseed: 2, frozenbasebosshp: 10}, {}, [{type: 'consumable', kind: 'sword'}], [], {sword: 1}),
        {damage: 10, playergold: 0, bossgold: 0, bossdefeated: true}
    );
});

test('a counted consumable missing from the log, or a logged one never counted, is inconclusive', () => {
    assert.equal(derive({rngseed: 2, frozenbasebosshp: 10}, {}, seed2Win, [], {shield: 1}), null);
    assert.equal(derive({rngseed: 2, frozenbasebosshp: 10}, {}, [{type: 'consumable', kind: 'sword'}]), null);
});

test('match damage is rounded like the client on seed 4', () => {
    assert.deepEqual(
        derive(
            {rngseed: 4, frozenbasebosshp: 17, frozenbossdamage: 11},
            {basestudenthp: 100},
            [{type: 'move', r1: 2, c1: 5, r2: 3, c2: 5}]
        ),
        {damage: 17, playergold: 0, bossgold: 0, bossdefeated: true}
    );
});

test('a real defeat on seed 2', () => {
    assert.deepEqual(
        derive({rngseed: 2}, {basestudenthp: 1}, [{type: 'move', r1: 0, c1: 4, r2: 1, c2: 4}]),
        {damage: 0, playergold: 10, bossgold: 0, bossdefeated: false}
    );
});

test('a missing question event is a gap on seed 56', () => {
    assert.equal(
        derive({rngseed: 56, frozenbasebosshp: 100000}, {}, [{type: 'move', r1: 0, c1: 7, r2: 1, c2: 7}]),
        null
    );
});

test('a multi-turn phase with a boss question on seed 22', () => {
    assert.deepEqual(
        derive({rngseed: 22, frozenbasebosshp: 15}, {}, seed22Log('answered'), [['boss', true, false]]),
        {damage: 15, playergold: 0, bossgold: 0, bossdefeated: true}
    );
});

test('impossible swaps are rejected', () => {
    assert.equal(derive({rngseed: 2}, {}, [{type: 'move', r1: 0, c1: 0, r2: 0, c2: 0}]), null);
    assert.equal(derive({rngseed: 2, frozenbasebosshp: 10}, {basestudenthp: 100}, [
        {type: 'move', r1: 0, c1: 2, r2: 0, c2: 3},
        {type: 'move', r1: 1, c1: 2, r2: 2, c2: 2},
    ]), null);
    assert.equal(derive({rngseed: 2, frozenbasebosshp: 10}, {}, [{type: 'move', r1: 0, c1: 3, r2: 3, c2: 1}]), null);
    assert.equal(derive({rngseed: 2}, {}, [{type: 'move'}]), null);
});

test('the boss revives instead of dying while minquestions is unmet', () => {
    assert.equal(
        derive({rngseed: 22, frozenbasebosshp: 15}, {minquestions: 5}, seed22Log('answered'), [['boss', true, false]]),
        null
    );
});

test('question outcomes come from the server on seed 56', () => {
    const seed56 = (outcome, outcomes, bosshp = 40) => derive(
        {rngseed: 56, frozenbasebosshp: bosshp, questionsTotal: outcomes.length},
        {basestudenthp: 100},
        seed56Log(outcome),
        outcomes
    );

    assert.deepEqual(seed56('answered', [['player', true, true]]),
        {damage: 40, playergold: 25, bossgold: 0, bossdefeated: true});
    assert.equal(seed56('answered', [['player', false, true]]), null);
    assert.equal(seed56('answered', []), null);
    assert.equal(seed56('answered', [['boss', true, false]]), null);
    assert.deepEqual(seed56('skipped', [], 10), {damage: 10, playergold: 25, bossgold: 0, bossdefeated: true});
    assert.equal(seed56('skipped', [['player', false, true]], 10), null);
    assert.deepEqual(seed56('failed', [], 10), {damage: 10, playergold: 25, bossgold: 0, bossdefeated: true});
    assert.deepEqual(seed56('unavailable', [], 10), {damage: 10, playergold: 25, bossgold: 0, bossdefeated: true});
});

test('a failed boss validation is inconclusive on seed 22', () => {
    assert.equal(derive({rngseed: 22, frozenbasebosshp: 15}, {}, seed22Log('failed')), null);
});

test('the opponent of each difficulty has the HP and the unit the server gives it', () => {
    const setup = (difficulty, opponent) => ReplayMirror.phaseSetup(
        {
            currentlevel: 1, currentphase: 10, difficulty, frozenbasebosshp: 100, frozenbossdamage: 10,
            frozencoingain: 10, frozenopponent: opponent,
        },
        {basestudenthp: 100},
        {hp: opponent === 'vampire' ? 1.2 : 1, attack: 1, armor: 0}
    );

    // Mirrors combat_test::test_the_final_boss_has_more_hp_on_normal_only().
    assert.equal(setup('normal', 'vampire').maxBossHp, 262);
    assert.equal(setup('easy', 'vampire').maxBossHp, 114);
    assert.equal(setup('hard', 'vampire').maxBossHp, 456);
    assert.equal(setup('normal', 'dragon').maxBossHp, 190);

    // Mirrors combat_test::test_the_student_unit_is_not_weakened_on_easy(): Easy halves the opponent's unit only.
    const easy = setup('easy', '').config;
    assert.equal(easy.baseDamage, 10);
    assert.equal(easy.playerBaseDamage, 19);
    const hard = setup('hard', '').config;
    assert.deepEqual([hard.baseDamage, hard.playerBaseDamage], [38, 38]);
});

test('scaleAttack grows a Normal opponent\'s attack over the Campaign and leaves the other difficulties alone', () => {
    // The same cases combat_test.php pins for combat::scale_attack().
    assert.equal(ReplayMirror.scaleAttack(1.0, 'normal', 1, 1, 1), 1.0);
    assert.equal(ReplayMirror.scaleAttack(1.0, 'normal', 1, 5, 1), 1.267);
    assert.equal(ReplayMirror.scaleAttack(1.0, 'normal', 1, 10, 1), 1.6);
    assert.equal(ReplayMirror.scaleAttack(1.15, 'normal', 2, 5, 3), 1.483);
    assert.equal(ReplayMirror.scaleAttack(1.0, 'normal', 10, 10, 10), 1.6);
    assert.equal(ReplayMirror.scaleAttack(1.0, 'normal', 1, 1, 0), 1.0);
    assert.equal(ReplayMirror.scaleAttack(1.0, 'normal', 3, 10, 1), 1.6);
    assert.equal(ReplayMirror.scaleAttack(1.15, 'hard', 1, 10, 1), 1.15);
    assert.equal(ReplayMirror.scaleAttack(1.0, 'easy', 1, 10, 1), 1.0);
});

test('a phase with no opening question on record is inconclusive', () => {
    assert.equal(deriveRaw({rngseed: 2, frozenbasebosshp: 10}, {}, seed2Win), null);
});

test('an opening declared unavailable while the pool has questions is refused', () => {
    const decoded = [];
    assert.equal(ReplayMirror.derive({
        attempt: {
            rngseed: 2, currentlevel: 1, currentphase: 1, difficulty: 'normal', questionsTotal: 0,
            frozenbasebosshp: 10, frozenbossdamage: 10, frozencoingain: 10,
        },
        playerpuzzle: {minquestions: 0, basestudenthp: 1000},
        events: [NO_QUESTION_OPENING, ...seed2Win],
        outcomes: decoded,
        serverUses: {},
        poolEmpty: false,
    }), null);
});

test('an opening answered right leaves the move to the student, and counts as an answered question', () => {
    const right = deriveRaw(
        {rngseed: 2, frozenbasebosshp: 10, questionsTotal: 1}, {minquestions: 1},
        [{type: 'question', side: 'player', outcome: 'answered'}, ...seed2Win],
        [['player', true, true]]
    );
    assert.deepEqual(right, {damage: 10, playergold: 15, bossgold: 0, bossdefeated: true});
    // Needed one question and asked one: the opponent falls on the first Sword combo, with no resistance.
    assert.equal(right.bossdefeated, true);
});

test('an opening missed, failed or skipped gives the opponent the first move', () => {
    // On seed 2 the opponent's first move is a winning one against a student with 1 HP: the phase ends with the
    // opening as its only event, which is only possible if the opponent moved before the student did.
    const lost = {damage: 0, playergold: 0, bossgold: 15, bossdefeated: false};
    for (const outcome of ['skipped', 'failed']) {
        assert.deepEqual(
            deriveRaw({rngseed: 2}, {basestudenthp: 1}, [{type: 'question', side: 'player', outcome}]),
            lost,
            outcome
        );
    }
    assert.deepEqual(
        deriveRaw(
            {rngseed: 2, questionsTotal: 1}, {basestudenthp: 1},
            [{type: 'question', side: 'player', outcome: 'answered'}],
            [['player', false, true]]
        ),
        lost
    );
    // Moving first, the same student has not lost yet: a log with only the opening is not a finished phase.
    assert.equal(
        deriveRaw(
            {rngseed: 2, questionsTotal: 1}, {basestudenthp: 1},
            [{type: 'question', side: 'player', outcome: 'answered'}],
            [['player', true, true]]
        ),
        null
    );
});
