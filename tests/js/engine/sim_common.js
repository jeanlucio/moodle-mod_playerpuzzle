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
 * Pieces the balance simulators share: the opponents as the server defines them, and one match
 * played through the real rules (replay_mirror.js, the turn loop the server replays).
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global __dirname, module */

'use strict';

const childProcess = require('child_process');
const path = require('path');
const BoardRules = require('../../../amd/src/engine/board_rules.js');
const CombatRules = require('../../../amd/src/engine/combat_rules.js');
const Prng = require('../../../amd/src/engine/prng.js');
const ReplayMirror = require('./replay_mirror.js');

const PLUGIN_ROOT = path.join(__dirname, '..', '..', '..');

// The multiple-choice rates in combat::BOSS_GUESS_PROBABILITIES.
const OPPONENT_ACCURACY = {easy: 0.33, normal: 0.66, hard: 1.0};

// The instance values the activity form defaults to.
const INSTANCE = {basebosshp: 100, bossdamage: 10, basestudenthp: 100, coingain: 10};

const NO_MODIFIERS = {hp: 1, attack: 1, armor: 0};

/**
 * Asks the server's own class for every opponent, in Campaign order with the final boss last, and
 * what each fights with.
 *
 * @return {Array<{key: string, modifiers: object, powers: Array<string>, counters: object}>} counters is what the
 *  opponent adds against a hero strong in attack, armor or HP (only the final boss adds anything).
 */
function readOpponents() {
    const script = `
        require 'classes/local/engine/combat_engine.php';
        require 'classes/local/heroes.php';
        require 'classes/local/opponents.php';
        $class = 'mod_playerpuzzle\\\\local\\\\opponents';
        $keys = array_merge($class::CAMPAIGN_ORDER, [$class::FINAL_BOSS]);
        echo json_encode(array_map(fn($key) => [
            'key' => $key,
            'modifiers' => $class::modifiers($key),
            'powers' => $class::powers($key),
            'counters' => [
                'attack' => $class::counter($key, 'warriorf1'),
                'armor' => $class::counter($key, 'archerm1'),
                'hp' => $class::counter($key, 'magef1'),
            ],
        ], $keys));`;
    return JSON.parse(childProcess.execFileSync('php', ['-r', script], {cwd: PLUGIN_ROOT, encoding: 'utf8'}));
}

/**
 * Plays one match.
 *
 * @param {object} params
 * @param {number} params.seed Board seed; the answers draw from a generator seeded from it too.
 * @param {number} params.level Campaign level.
 * @param {number} params.phase Campaign phase within the level.
 * @param {object} params.modifiers The opponent's {hp, attack, armor}.
 * @param {Array<string>} params.powers The opponent's powers.
 * @param {number} params.accuracy The student's share of right answers.
 * @param {string} params.difficulty 'easy', 'normal' or 'hard'.
 * @param {string} [params.opponent] The opponent's key (only the final boss's matters).
 * @param {number} [params.breathEvery] Every how many of its turns the opponent lands its breath, if not 10.
 * @param {boolean} [params.careful] Whether the student picks their moves the way the Normal opponent does, by
 *  the value of what each match gives, instead of playing the first Sword match.
 * @param {function(object, object, object): ?string} [params.consume] Called each turn with the combat state, the
 *  consumables already used this phase and the phase's rule config; returns the consumable to use, or null.
 * @param {{hp: number, attack: number, armor: number}} [params.student] The student's side as a phase
 *  freezes it; absent, the instance's own scaling with no attack or armor.
 * @return {{finished: boolean, won: boolean, turns: number, hpLeft: number, coins: number}} hpLeft
 *  is the student's HP share at the start of their last turn, which is what they finish a won match
 *  with; coins is what the match nets them (their coins minus the opponent's, never below 0).
 */
function playMatch({
    seed, level, phase, modifiers, powers, accuracy, difficulty, opponent, student, breathEvery, careful, consume,
}) {
    const answers = Prng.create((seed * 7919) + 13);
    let lastState = null;
    let turns = 0;
    let rules = null;
    const policy = {
        move(grid) {
            turns++;
            if (careful && lastState) {
                // The Normal opponent's own weights, from the student's side: a Potion is worth what they are
                // hurt by, and a Shield less while one is armed.
                const weights = CombatRules.opponentMoveWeights({
                    bossShieldReady: lastState.playerShieldReady,
                    currentHp: lastState.currentPlayerHp,
                    maxBossHp: lastState.maxPlayerHp,
                }, {difficulty: 'normal'});
                const move = BoardRules.chooseMove(grid, ReplayMirror.ROWS, ReplayMirror.COLS, weights);
                if (move) {
                    return move;
                }
            }
            return BoardRules.findMove(grid, ReplayMirror.ROWS, ReplayMirror.COLS, 3)
                || BoardRules.findMove(grid, ReplayMirror.ROWS, ReplayMirror.COLS, null);
        },
        consumable(turnCount, state, loggedUses) {
            lastState = state;
            return consume ? consume(state, loggedUses, rules) : null;
        },
        question(side) {
            const rate = side === 'player' ? accuracy : OPPONENT_ACCURACY[difficulty];
            return {outcome: 'answered', correct: answers() < rate};
        },
    };
    const attempt = {
        frozenbasebosshp: INSTANCE.basebosshp, frozenbossdamage: INSTANCE.bossdamage,
        frozencoingain: INSTANCE.coingain, currentlevel: level, currentphase: phase,
        difficulty, rngseed: seed, questionsTotal: 0,
    };
    if (opponent) {
        attempt.frozenopponent = opponent;
    }
    if (student) {
        attempt.frozenplayerhp = student.hp;
        attempt.frozenplayerattack = student.attack;
        attempt.frozenplayerarmor = student.armor;
    }
    const playerpuzzle = {basestudenthp: INSTANCE.basestudenthp, minquestions: 0};
    rules = ReplayMirror.phaseSetup(attempt, playerpuzzle, modifiers, powers, breathEvery).config;
    const {result} = ReplayMirror.record({attempt, playerpuzzle, modifiers, powers, breathEvery, poolEmpty: false, policy});
    if (!result) {
        return {finished: false, won: false, turns, hpLeft: 0, coins: 0};
    }
    const {maxPlayerHp} = ReplayMirror.phaseSetup(attempt, playerpuzzle);
    const won = result.bossdefeated;
    const coins = Math.max(0, result.playergold - result.bossgold);
    return {finished: true, won, turns, hpLeft: won ? lastState.currentPlayerHp / maxPlayerHp : 0, coins};
}

/**
 * What an opponent fights a hero with: its own modifiers and powers plus what it adds against the hero's
 * specialty. Without a hero it is the warrior, as on the server.
 *
 * @param {{modifiers: object, powers: Array<string>, counters: object}} opponent An entry of readOpponents().
 * @param {string} [specialty] 'attack', 'armor' or 'hp'.
 * @return {{modifiers: object, powers: Array<string>, breathEvery: number}}
 */
function fightWith(opponent, specialty = 'attack') {
    const counter = opponent.counters[specialty];
    return {
        modifiers: Object.assign({}, opponent.modifiers, {armor: opponent.modifiers.armor + counter.armor}),
        powers: [...new Set([...opponent.powers, ...counter.powers])],
        breathEvery: counter.breathevery,
    };
}

module.exports = {OPPONENT_ACCURACY, INSTANCE, NO_MODIFIERS, readOpponents, fightWith, playMatch};
