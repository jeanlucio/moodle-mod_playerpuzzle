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
 * Plays thousands of Campaign matches through the real rules and prints how hard each opponent
 * is, so profile and power values can be tuned against numbers instead of guesses.
 *
 * Every match runs through replay_mirror.js, the same turn loop the server replays, with a
 * simulated student who plays the way the opponent does (a Sword match when there is one, any
 * match otherwise), uses no consumable and answers right at a fixed rate. The opponent answers
 * right at its Normal rate. Each opponent fights at its own Campaign phase, three ways: without
 * profile or power, with its profile only, and with both.
 *
 * A real student plays better or worse and shops, so a win rate here is not a target in
 * itself: compare the columns, and the opponents with one another.
 *
 * The profile and power tables are read from classes/local/opponents.php through the PHP CLI,
 * so the numbers always follow the server's values.
 *
 * Usage: node tests/js/engine/simulate_balance.js [matches] [student accuracies]
 * e.g.   node tests/js/engine/simulate_balance.js 3000 0.5,0.75,0.9
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global __dirname, process */

'use strict';

const childProcess = require('child_process');
const path = require('path');
const BoardRules = require('../../../amd/src/engine/board_rules.js');
const Prng = require('../../../amd/src/engine/prng.js');
const ReplayMirror = require('./replay_mirror.js');

const PLUGIN_ROOT = path.join(__dirname, '..', '..', '..');

// The Normal multiple-choice rate in combat::BOSS_GUESS_PROBABILITIES.
const OPPONENT_ACCURACY = 0.66;

// The instance values the activity form defaults to.
const INSTANCE = {basebosshp: 100, bossdamage: 10, basestudenthp: 100, coingain: 10};

const NO_MODIFIERS = {hp: 1, attack: 1, armor: 0};

/**
 * Reads the opponent tables from the server's own class.
 *
 * @return {{order: Array<string>, profiles: object, modifiers: object, powers: object}}
 */
function readOpponents() {
    const script = `
        require 'classes/local/engine/combat_engine.php';
        require 'classes/local/opponents.php';
        $class = new ReflectionClass('mod_playerpuzzle\\\\local\\\\opponents');
        echo json_encode([
            'order' => $class->getConstant('CAMPAIGN_ORDER'),
            'profiles' => $class->getConstant('PROFILES'),
            'modifiers' => $class->getConstant('PROFILE_MODIFIERS'),
            'powers' => $class->getConstant('POWERS'),
        ]);`;
    return JSON.parse(childProcess.execFileSync('php', ['-r', script], {cwd: PLUGIN_ROOT, encoding: 'utf8'}));
}

/**
 * Plays one match.
 *
 * @param {number} seed Board seed; the answers draw from a generator seeded from it too.
 * @param {number} phase Campaign phase (level 1).
 * @param {object} modifiers The opponent's {hp, attack, armor}.
 * @param {Array<string>} powers The opponent's powers.
 * @param {number} accuracy The student's share of right answers.
 * @return {{finished: boolean, won: boolean, turns: number, hpLeft: number}} hpLeft is the
 *  student's HP share at the start of their last turn, which is what they finish a won match with.
 */
function playMatch(seed, phase, modifiers, powers, accuracy) {
    const answers = Prng.create((seed * 7919) + 13);
    let lastState = null;
    let turns = 0;
    const policy = {
        move(grid) {
            turns++;
            return BoardRules.findMove(grid, ReplayMirror.ROWS, ReplayMirror.COLS, 3)
                || BoardRules.findMove(grid, ReplayMirror.ROWS, ReplayMirror.COLS, null);
        },
        consumable(turnCount, state) {
            lastState = state;
            return null;
        },
        question(side) {
            const rate = side === 'player' ? accuracy : OPPONENT_ACCURACY;
            return {outcome: 'answered', correct: answers() < rate};
        },
    };
    const attempt = {
        frozenbasebosshp: INSTANCE.basebosshp, frozenbossdamage: INSTANCE.bossdamage,
        frozencoingain: INSTANCE.coingain, currentlevel: 1, currentphase: phase,
        difficulty: 'normal', rngseed: seed, questionsTotal: 0,
    };
    const playerpuzzle = {basestudenthp: INSTANCE.basestudenthp, minquestions: 0};
    const {result} = ReplayMirror.record({attempt, playerpuzzle, modifiers, powers, poolEmpty: false, policy});
    if (!result) {
        return {finished: false, won: false, turns, hpLeft: 0};
    }
    const {maxPlayerHp} = ReplayMirror.phaseSetup(attempt, playerpuzzle);
    const won = result.bossdefeated;
    return {finished: true, won, turns, hpLeft: won ? lastState.currentPlayerHp / maxPlayerHp : 0};
}

/**
 * Plays a batch of matches against one opponent setup.
 *
 * @param {number} matches How many.
 * @param {number} phase Campaign phase.
 * @param {object} modifiers See playMatch().
 * @param {Array<string>} powers See playMatch().
 * @param {number} accuracy See playMatch().
 * @return {{win: number, turns: number, hpLeft: number, unfinished: number}}
 */
function playBatch(matches, phase, modifiers, powers, accuracy) {
    let wins = 0;
    let turns = 0;
    let hpLeft = 0;
    let unfinished = 0;
    for (let seed = 1; seed <= matches; seed++) {
        const match = playMatch(seed, phase, modifiers, powers, accuracy);
        if (!match.finished) {
            unfinished++;
            continue;
        }
        turns += match.turns;
        if (match.won) {
            wins++;
            hpLeft += match.hpLeft;
        }
    }
    const finished = matches - unfinished;
    return {win: wins / finished, turns: turns / finished, hpLeft: wins ? hpLeft / wins : 0, unfinished};
}

const percent = value => `${Math.round(value * 100)}%`.padStart(4);
const cell = batch => `${percent(batch.win)} ${batch.turns.toFixed(1).padStart(4)}t ${percent(batch.hpLeft)}hp`;

const matches = parseInt(process.argv[2] || '1000', 10);
const accuracies = (process.argv[3] || '0.5,0.75,0.9').split(',').map(Number);
const opponents = readOpponents();

for (const accuracy of accuracies) {
    process.stdout.write(`\nStudent right ${percent(accuracy)}, opponent right ${percent(OPPONENT_ACCURACY)}, `
        + `${matches} matches per cell. Each cell: win rate, student turns, HP left on a win.\n`);
    process.stdout.write('phase opponent | no profile      | profile only    | profile+power   | power effect\n');
    opponents.order.forEach((name, index) => {
        const phase = index + 1;
        const profile = opponents.profiles[name];
        const modifiers = profile ? opponents.modifiers[profile] : NO_MODIFIERS;
        const powers = opponents.powers[name] || [];
        const bare = playBatch(matches, phase, NO_MODIFIERS, [], accuracy);
        const profiled = playBatch(matches, phase, modifiers, [], accuracy);
        const full = powers.length ? playBatch(matches, phase, modifiers, powers, accuracy) : null;
        const effect = full
            ? `${Math.round((full.win - profiled.win) * 100)} pts, ${(full.turns - profiled.turns).toFixed(1)} turns`
            : '';
        const unfinished = bare.unfinished + profiled.unfinished + (full ? full.unfinished : 0);
        process.stdout.write(`${String(phase).padStart(5)} ${name.padEnd(8)} | ${cell(bare)} | ${cell(profiled)} | `
            + `${full ? cell(full) : '-'.padEnd(15)} | ${effect}${unfinished ? ` (${unfinished} unfinished)` : ''}\n`);
    });
}
