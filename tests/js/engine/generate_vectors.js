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
 * It rewrites tests/fixtures/replay_vectors.json. Never edit that file by hand:
 * tests/js/engine/vectors.test.js fails when it no longer matches what this script builds,
 * and tests/local/engine/replay_vectors_test.php fails when the PHP engine disagrees with it.
 * Regenerate only after understanding why the expected values moved.
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
const ReplayMirror = require('./replay_mirror.js');

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
];

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
        const playerpuzzle = {minquestions: spec.minquestions || 0, basestudenthp: spec.student};

        const recorded = ReplayMirror.record({attempt, playerpuzzle, poolEmpty: true, policy: makePolicy(spec)});
        if (recorded.result === null) {
            throw new Error(`Replay case "${spec.name}" did not reach a conclusive end.`);
        }
        attempt.questionsTotal = recorded.outcomes.filter(outcome => outcome.counted).length;

        return {
            name: spec.name,
            attempt,
            playerpuzzle,
            events: recorded.events,
            outcomes: recorded.outcomes,
            serveruses: recorded.serverUses,
            expected: recorded.result,
        };
    });
}

/**
 * Serialises a fixture with one event or outcome per line, so a regenerated file diffs
 * readably.
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

module.exports = {REPLAY_FIXTURE, buildReplayVectors, serialise};

if (require.main === module) {
    fs.writeFileSync(REPLAY_FIXTURE, serialise(buildReplayVectors()));
    process.stdout.write(`Wrote ${REPLAY_FIXTURE}\n`);
}
