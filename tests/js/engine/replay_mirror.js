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
 * A JavaScript mirror of classes/local/engine/replay.php's verification loop, built on the
 * game's own amd/src/engine/board_rules.js, combat_rules.js and prng.js.
 *
 * replay.php runs the turn sequence the live client runs (board.js::checkMatches() and the
 * combat.js turn hand-offs), but nothing in the PHP suite can prove the two orders agree: the
 * PHP side only ever checks itself. This mirror is the JavaScript half of that check. It can
 * either verify a recorded match, exactly as replay::derive() does, or play a whole match with
 * a fixed policy and record it, producing the event log and the server-side question outcomes
 * a real client and validate_answer.php would have stored. tests/js/engine/generate_vectors.js
 * records such matches into tests/fixtures/replay_vectors.json, and
 * tests/local/engine/replay_vectors_test.php replays every one of them through the real
 * replay::derive(), which must reach the same totals.
 *
 * Any change to the turn order, a hook or a combat rule therefore has to be made on both
 * sides: changing only one makes either the Node freshness check or the PHPUnit replay fail.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */

'use strict';

const BoardRules = require('../../../amd/src/engine/board_rules.js');
const CombatRules = require('../../../amd/src/engine/combat_rules.js');
const Prng = require('../../../amd/src/engine/prng.js');

const ROWS = 8;
const COLS = 8;
const MAX_CASCADE_ROUNDS = 200;
const MAX_TURNS = 500;
const COMBAT_CONSUMABLES = ['potion', 'shield', 'magic', 'sword'];

/**
 * Mirrors combat::calculate_boss_hp(): HP (and boss damage) scaled by level and phase.
 *
 * @param {number} base Base value configured by the teacher.
 * @param {number} level Current level.
 * @param {number} phase Current phase.
 * @return {number}
 */
function scaleBoss(base, level, phase) {
    return Math.round(base * (1 + (0.5 * (level - 1)) + (0.1 * (phase - 1))));
}

/**
 * Mirrors combat::calculate_student_hp().
 *
 * @param {number} base Base student HP.
 * @param {number} level Current level.
 * @param {number} phase Current phase.
 * @return {number}
 */
function scaleStudent(base, level, phase) {
    return Math.round(base * (1 + (0.3 * (level - 1)) + (0.05 * (phase - 1))));
}

const DIFFICULTY_BOSS_FACTORS = {easy: 0.5, normal: 1.0, hard: 2.0};
const DIFFICULTY_COIN_FACTORS = {easy: 0.5, normal: 1.0, hard: 3.0};

/**
 * Builds the phase's combat config the way replay::simulate() does from an attempt row.
 *
 * @param {object} attempt Attempt fields: frozenbasebosshp, frozenbossdamage,
 *  frozencoingain, currentlevel, currentphase, difficulty.
 * @param {object} playerpuzzle Instance fields: basestudenthp.
 * @return {{config: object, maxBossHp: number, maxPlayerHp: number}}
 */
function phaseSetup(attempt, playerpuzzle) {
    const level = attempt.currentlevel;
    const phase = attempt.currentphase;
    const bossFactor = DIFFICULTY_BOSS_FACTORS[attempt.difficulty] ?? 1.0;
    return {
        config: {
            baseDamage: Math.round(scaleBoss(attempt.frozenbossdamage, level, phase) * bossFactor),
            coinGain: attempt.frozencoingain,
            coinFactor: DIFFICULTY_COIN_FACTORS[attempt.difficulty] ?? 1.0,
        },
        maxBossHp: Math.round(scaleBoss(attempt.frozenbasebosshp, level, phase) * bossFactor),
        maxPlayerHp: scaleStudent(playerpuzzle.basestudenthp, level, phase),
    };
}

/**
 * Runs one phase, either verifying a recorded log or recording one with a policy.
 *
 * Verifying (no policy): mirrors replay::derive() — returns the derived totals, or null on
 * any gap or inconsistency. Recording (with a policy): every point where the verifier would
 * read the next event instead asks the policy, and the event (plus, for an answered question,
 * the server-side outcome) is appended, so the returned log is exactly what a client playing
 * those choices would have sent.
 *
 * @param {object} params
 * @param {object} params.attempt See phaseSetup(), plus rngseed and questionsTotal (the
 *  attempt's questions_total column).
 * @param {object} params.playerpuzzle See phaseSetup(), plus minquestions.
 * @param {Array} params.events Recorded events (verifying), or [] (recording).
 * @param {Array} params.outcomes Server outcomes {side, correct, counted} (verifying), or [].
 * @param {object} params.serverUses Consumable uses the server counted, by type.
 * @param {boolean} params.poolEmpty Whether the instance had no approved question.
 * @param {object|null} params.policy Recording policy, or null to verify.
 * @return {{result: object|null, events: Array, outcomes: Array, serverUses: object}}
 */
function runPhase({attempt, playerpuzzle, events, outcomes, serverUses, poolEmpty, policy}) {
    const rng = Prng.create(attempt.rngseed);
    const grid = BoardRules.generateGrid(ROWS, COLS, null, rng);
    const {config, maxBossHp, maxPlayerHp} = phaseSetup(attempt, playerpuzzle);
    const recording = policy !== null && policy !== undefined;
    const uses = Object.assign({}, serverUses);

    let state = {
        currentPlayerHp: maxPlayerHp, maxPlayerHp,
        currentHp: maxBossHp, maxBossHp,
        playerMultiplier: 1.0, bossMultiplier: 1.0,
        playerShieldMeter: 0, playerShieldReady: false,
        bossShieldMeter: 0, bossShieldReady: false,
        playerPoisonMeter: 0, playerPoisonRounds: 0,
        bossPoisonMeter: 0, bossPoisonRounds: 0,
        playerMana: 0, bossMana: 0,
        playerGold: 0.0, bossGold: 0.0,
    };

    const countedInPhase = outcomes.filter(outcome => outcome.counted).length;
    const sim = {
        events, eventIndex: 0,
        outcomes, outcomeIndex: 0,
        questionsTotal: Math.max(0, attempt.questionsTotal - countedInPhase),
        loggedUses: {potion: 0, shield: 0, magic: 0, sword: 0},
        questionCount: {player: 0, boss: 0},
    };
    const minQuestions = playerpuzzle.minquestions;

    const done = result => ({result, events: sim.events, outcomes: sim.outcomes, serverUses: uses});

    const finish = () => {
        if (sim.eventIndex !== sim.events.length || sim.outcomeIndex !== sim.outcomes.length) {
            return done(null);
        }
        for (const kind of COMBAT_CONSUMABLES) {
            if (sim.loggedUses[kind] !== (uses[kind] || 0)) {
                return done(null);
            }
        }
        return done({
            damage: Math.max(0, maxBossHp - Math.max(0, Math.round(state.currentHp))),
            playergold: Math.max(0, Math.round(state.playerGold)),
            bossgold: Math.max(0, Math.round(state.bossGold)),
            bossdefeated: state.currentHp <= 0,
        });
    };

    const applyQuestionOutcome = (side, correct) => {
        if (side === 'player') {
            if (correct) {
                const crit = config.baseDamage * 3 * state.playerMultiplier;
                const applied = CombatRules.resolveDamage(state.currentHp, state.bossShieldReady, crit);
                state.currentHp = applied.newHp;
                if (applied.shieldConsumed) {
                    state.bossShieldReady = false;
                }
            } else {
                const applied = CombatRules.resolveDamage(state.currentPlayerHp, state.playerShieldReady, 30);
                state.currentPlayerHp = applied.newHp;
                if (applied.shieldConsumed) {
                    state.playerShieldReady = false;
                }
                state.playerMultiplier = 1.0;
            }
        } else if (correct) {
            const crit = config.baseDamage * 3 * state.bossMultiplier;
            const applied = CombatRules.resolveDamage(state.currentPlayerHp, state.playerShieldReady, crit);
            state.currentPlayerHp = applied.newHp;
            if (applied.shieldConsumed) {
                state.playerShieldReady = false;
            }
        } else {
            state.bossMultiplier = 1.0;
        }
    };

    const consumeQuestion = side => {
        if (recording && sim.eventIndex === sim.events.length) {
            const choice = policy.question(side, sim.questionCount[side]++, state);
            sim.events.push({type: 'question', side, outcome: choice.outcome});
            if (choice.outcome === 'answered') {
                sim.outcomes.push({side, correct: choice.correct, counted: side === 'player'});
            }
        }
        const event = sim.events[sim.eventIndex];
        if (!event || event.type !== 'question' || event.side !== side) {
            return false;
        }
        sim.eventIndex++;

        switch (event.outcome) {
            case 'answered': {
                const outcome = sim.outcomes[sim.outcomeIndex];
                if (!outcome || outcome.side !== side) {
                    return false;
                }
                sim.outcomeIndex++;
                applyQuestionOutcome(side, !!outcome.correct);
                if (outcome.counted) {
                    sim.questionsTotal++;
                }
                return true;
            }
            case 'skipped':
                return side === 'player';
            case 'unavailable':
                return poolEmpty;
            case 'failed':
                if (side !== 'player') {
                    return false;
                }
                applyQuestionOutcome('player', false);
                return true;
        }
        return false;
    };

    const resolveCascade = turn => {
        for (let round = 0; round < MAX_CASCADE_ROUNDS; round++) {
            const toDestroy = [];
            const matchGroups = [];
            BoardRules.checkHorizontal(grid, ROWS, COLS, toDestroy, matchGroups);
            BoardRules.checkVertical(grid, ROWS, COLS, toDestroy, matchGroups);
            if (toDestroy.length === 0) {
                return round > 0;
            }

            const destroyedTypes = toDestroy.map(cell => grid[cell.row][cell.col]);
            toDestroy.forEach(cell => {
                grid[cell.row][cell.col] = null;
            });
            const sizedGroups = matchGroups.map(group => ({type: group.type, size: group.cells.length}));

            const result = CombatRules.resolveMatchEffects({
                currentTurn: turn, destroyedTypes, matchGroups: sizedGroups, state, config,
            });
            state = result.state;

            if (result.damageDealt > 0) {
                const scaled = Math.round(result.damageDealt * (turn === 'player' ? state.playerMultiplier : 1));
                if (turn === 'player') {
                    const applied = CombatRules.resolveDamage(state.currentHp, state.bossShieldReady, scaled);
                    state.currentHp = applied.newHp;
                    if (applied.shieldConsumed) {
                        state.bossShieldReady = false;
                    }
                } else {
                    const applied = CombatRules.resolveDamage(state.currentPlayerHp, state.playerShieldReady, scaled);
                    state.currentPlayerHp = applied.newHp;
                    if (applied.shieldConsumed) {
                        state.playerShieldReady = false;
                    }
                }
            }

            if (result.questionTriggered && !consumeQuestion(result.triggeredBy)) {
                return false;
            }

            BoardRules.applyGravityToGrid(grid, ROWS, COLS, rng);
        }
        return false;
    };

    const applyConsumable = kind => {
        const result = CombatRules.resolveConsumableEffect(kind, state, config);
        state = result.state;
        if (kind === 'sword') {
            const applied = CombatRules.resolveDamage(state.currentHp, state.bossShieldReady, result.damageAmount);
            state.currentHp = applied.newHp;
            if (applied.shieldConsumed) {
                state.bossShieldReady = false;
            }
        }
    };

    // Returns true when the boss reached 0 and stays dead (the match is over), after reviving it
    // when the minimum question count is not met yet.
    const bossDiesForGood = () => {
        if (state.currentHp > 0) {
            return false;
        }
        if (CombatRules.needsRevive(minQuestions, sim.questionsTotal)) {
            state.currentHp = CombatRules.reviveHp(maxBossHp);
            return false;
        }
        return true;
    };

    const atLogEnd = () => sim.eventIndex === sim.events.length;

    // Reads the player's next swap (recording it first, when recording), or null when the log
    // has none or holds something no real client could have sent.
    const nextMove = turnCount => {
        if (recording && atLogEnd()) {
            const move = policy.move(grid, turnCount);
            if (!move) {
                return null;
            }
            sim.events.push({type: 'move', r1: move.r1, c1: move.c1, r2: move.r2, c2: move.c2});
        }
        const event = sim.events[sim.eventIndex];
        if (!event || event.type !== 'move' || [event.r1, event.c1, event.r2, event.c2].some(v => v === undefined)) {
            return null;
        }
        if (Math.abs(event.r1 - event.r2) + Math.abs(event.c1 - event.c2) !== 1) {
            return null;
        }
        sim.eventIndex++;
        return event;
    };

    // Each turn returns undefined to keep playing, or the phase's final outcome.
    const playerTurn = turnCount => {
        if (recording && atLogEnd()) {
            const kind = policy.consumable(turnCount, state, sim.loggedUses);
            if (kind) {
                sim.events.push({type: 'consumable', kind});
                uses[kind] = (uses[kind] || 0) + 1;
            }
        }
        while (sim.events[sim.eventIndex] && sim.events[sim.eventIndex].type === 'consumable') {
            const kind = sim.events[sim.eventIndex].kind;
            if (!COMBAT_CONSUMABLES.includes(kind)) {
                return done(null);
            }
            sim.eventIndex++;
            sim.loggedUses[kind]++;
            applyConsumable(kind);
            if (bossDiesForGood()) {
                return finish();
            }
        }

        const event = nextMove(turnCount);
        if (event === null) {
            return done(null);
        }
        BoardRules.swapInGrid(grid, event.r1, event.c1, event.r2, event.c2);
        if (!resolveCascade('player')) {
            return done(null);
        }
        if (bossDiesForGood() || state.currentPlayerHp <= 0) {
            return finish();
        }

        const tick = CombatRules.resolvePoisonTick(state.currentHp, state.bossPoisonRounds, config.baseDamage);
        if (tick.ticked) {
            state.currentHp = tick.newHp;
            state.bossPoisonRounds = tick.newPoisonRounds;
            if (bossDiesForGood()) {
                return finish();
            }
        }
        return undefined;
    };

    const bossTurn = () => {
        let move = BoardRules.findMove(grid, ROWS, COLS, 3) || BoardRules.findMove(grid, ROWS, COLS, null);
        if (move === null) {
            BoardRules.shuffleUntilValid(grid, ROWS, COLS, rng);
            move = BoardRules.findMove(grid, ROWS, COLS, 3) || BoardRules.findMove(grid, ROWS, COLS, null);
            if (move === null) {
                return done(null);
            }
        }
        BoardRules.swapInGrid(grid, move.r1, move.c1, move.r2, move.c2);
        if (!resolveCascade('boss')) {
            return done(null);
        }
        if (state.currentPlayerHp <= 0) {
            return finish();
        }

        const tick = CombatRules.resolvePoisonTick(state.currentPlayerHp, state.playerPoisonRounds, config.baseDamage);
        if (tick.ticked) {
            state.currentPlayerHp = tick.newHp;
            state.playerPoisonRounds = tick.newPoisonRounds;
            if (state.currentPlayerHp <= 0) {
                return finish();
            }
        }
        if (!BoardRules.hasAvailableMove(grid, ROWS, COLS)) {
            BoardRules.shuffleUntilValid(grid, ROWS, COLS, rng);
        }
        return undefined;
    };

    // The player always moves first, so their turns are the even ones.
    for (let turnCount = 0; turnCount < MAX_TURNS; turnCount++) {
        const outcome = turnCount % 2 === 0 ? playerTurn(turnCount) : bossTurn();
        if (outcome !== undefined) {
            return outcome;
        }
    }

    return done(null);
}

/**
 * Verifies a recorded phase, like replay::derive().
 *
 * @param {object} params See runPhase(), without a policy.
 * @return {object|null} The derived totals, or null when inconclusive.
 */
function derive(params) {
    return runPhase(Object.assign({}, params, {policy: null})).result;
}

/**
 * Plays and records a whole phase with a policy.
 *
 * @param {object} params See runPhase(); events/outcomes/serverUses start empty.
 * @return {{result: object|null, events: Array, outcomes: Array, serverUses: object}}
 */
function record(params) {
    return runPhase(Object.assign({events: [], outcomes: [], serverUses: {}}, params));
}

module.exports = {ROWS, COLS, phaseSetup, derive, record};
