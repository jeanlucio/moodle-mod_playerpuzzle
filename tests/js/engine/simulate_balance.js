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
 * simulated student who plays the way the opponent does on Easy (the first Sword match it finds,
 * any match otherwise), uses no consumable and answers right at a fixed rate. The opponent plays
 * and answers as the chosen difficulty makes it (see combat_rules.js::opponentMoveWeights() and
 * combat::BOSS_GUESS_PROBABILITIES), with that difficulty's HP and damage. Each opponent fights
 * at its own Campaign phase, the final boss at phase 10 like the dragon it replaces, three ways:
 * without profile or power, with its profile only (the final boss: its extra HP), and with its
 * powers too.
 *
 * A real student plays better or worse and shops, so a win rate here is not a target in
 * itself: compare the columns, and the opponents with one another.
 *
 * Each opponent's modifiers and powers come from classes/local/opponents.php through the PHP
 * CLI, so the numbers always follow the server's values.
 *
 * Usage: node tests/js/engine/simulate_balance.js [matches] [accuracies] [difficulties]
 * e.g.   node tests/js/engine/simulate_balance.js 3000 0.5,0.75 easy,normal,hard
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global process */

'use strict';

const {OPPONENT_ACCURACY, NO_MODIFIERS, readOpponents, fightWith, playMatch: playOne} = require('./sim_common.js');

/**
 * Plays one match at Level 1.
 *
 * @param {number} seed Board seed.
 * @param {number} phase Campaign phase (level 1).
 * @param {object} modifiers The opponent's {hp, attack, armor}.
 * @param {Array<string>} powers The opponent's powers.
 * @param {number} accuracy The student's share of right answers.
 * @param {string} difficulty 'easy', 'normal' or 'hard'.
 * @return {object} See sim_common.js::playMatch().
 */
function playMatch(seed, phase, modifiers, powers, accuracy, difficulty) {
    return playOne({seed, level: 1, phase, modifiers, powers, accuracy, difficulty});
}

/**
 * Plays a batch of matches against one opponent setup.
 *
 * @param {number} matches How many.
 * @param {number} phase Campaign phase.
 * @param {object} modifiers See playMatch().
 * @param {Array<string>} powers See playMatch().
 * @param {number} accuracy See playMatch().
 * @param {string} difficulty See playMatch().
 * @return {{win: number, turns: number, hpLeft: number, coins: number, unfinished: number}}
 */
function playBatch(matches, phase, modifiers, powers, accuracy, difficulty) {
    let wins = 0;
    let turns = 0;
    let hpLeft = 0;
    let unfinished = 0;
    let coins = 0;
    for (let seed = 1; seed <= matches; seed++) {
        const match = playMatch(seed, phase, modifiers, powers, accuracy, difficulty);
        if (!match.finished) {
            unfinished++;
            continue;
        }
        turns += match.turns;
        coins += match.coins;
        if (match.won) {
            wins++;
            hpLeft += match.hpLeft;
        }
    }
    const finished = matches - unfinished;
    return {win: wins / finished, turns: turns / finished, hpLeft: wins ? hpLeft / wins : 0, coins: coins / finished, unfinished};
}

const percent = value => `${Math.round(value * 100)}%`.padStart(4);
const cell = batch => `${percent(batch.win)} ${batch.turns.toFixed(1).padStart(4)}t ${percent(batch.hpLeft)}hp `
    + `${String(Math.round(batch.coins)).padStart(3)}c`;

const matches = parseInt(process.argv[2] || '1000', 10);
const accuracies = (process.argv[3] || '0.5,0.75,0.9').split(',').map(Number);
const difficulties = (process.argv[4] || 'normal').split(',');
const opponents = readOpponents();

for (const difficulty of difficulties) {
    for (const accuracy of accuracies) {
        process.stdout.write(`\n${difficulty}: student right ${percent(accuracy)}, opponent right `
            + `${percent(OPPONENT_ACCURACY[difficulty])}, ${matches} matches per cell. `
            + 'Each cell: win rate, student turns, HP left on a win, net coins.\n');
        process.stdout.write('phase opponent | no profile           | profile only         | '
            + 'profile+power        | power effect\n');
        opponents.forEach((opponent, index) => {
            const {key: name, modifiers} = opponent;
            // The final boss's second power depends on the student's hero, which this simulator has none of:
            // it fights the warrior's, as the server does for a student with no hero.
            const {modifiers: fullModifiers, powers} = fightWith(opponent);
            const phase = Math.min(index + 1, 10);
            const bare = playBatch(matches, phase, NO_MODIFIERS, [], accuracy, difficulty);
            const profiled = playBatch(matches, phase, modifiers, [], accuracy, difficulty);
            const full = powers.length ? playBatch(matches, phase, fullModifiers, powers, accuracy, difficulty) : null;
            const effect = full
                ? `${Math.round((full.win - profiled.win) * 100)} pts, ${(full.turns - profiled.turns).toFixed(1)} turns`
                : '';
            const unfinished = bare.unfinished + profiled.unfinished + (full ? full.unfinished : 0);
            process.stdout.write(`${String(phase).padStart(5)} ${name.padEnd(8)} | ${cell(bare)} | ${cell(profiled)} | `
                + `${full ? cell(full) : '-'.padEnd(20)} | ${effect}${unfinished ? ` (${unfinished} unfinished)` : ''}\n`);
        });
    }
}
