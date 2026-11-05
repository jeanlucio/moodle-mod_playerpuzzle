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

/**
 * Compares the three difficulties with the hero at its two ends: with nothing bought, and with every buy made.
 * Each cell plays the same phase many times with different boards and prints the share of matches the student
 * wins and how long the matches it wins last (in student turns, and the HP it has left), so a change to what a
 * difficulty scales can be judged before and after.
 *
 * Usage: node tests/js/engine/simulate_difficulty.js [--runs 300] [--levels 1,10] [--accuracy 0.75,1]
 *   [--phases 5,10] [--hero none,max]
 *
 * The student plays carefully (picks each move by value, like the Normal opponent) and buys nothing in the
 * match. A phase of 10 on the last level is the final boss, fighting with the answer for a hero strong in HP.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global process */

'use strict';

const {INSTANCE, readOpponents, fightWith, playMatch} = require('./sim_common.js');

const HP_FINAL_BASE = 2.4;
const HP_FINAL_PER_LEVEL = 0.62;
const ATTACK_FINAL = 1.9;
const ARMOR_FINAL = 0.25;
const DIFFICULTIES = ['easy', 'normal', 'hard'];

/**
 * Reads --name value pairs.
 *
 * @param {Array<string>} argv Arguments after the script name.
 * @return {object} Option values by name, as strings.
 */
function readOptions(argv) {
    const options = {};
    for (let i = 0; i < argv.length; i += 2) {
        options[argv[i].replace(/^--/, '')] = argv[i + 1];
    }
    return options;
}

/**
 * The student's side of a phase for a hero at one end of its growth.
 *
 * @param {string} hero 'none' (nothing bought) or 'max' (every buy made).
 * @param {number} levels The Campaign's level count.
 * @return {{hp: number, attack: number, armor: number}}
 */
function studentSide(hero, levels) {
    if (hero === 'none') {
        return {hp: INSTANCE.basestudenthp, attack: 1, armor: 0};
    }
    return {
        hp: Math.round(INSTANCE.basestudenthp * (HP_FINAL_BASE + (HP_FINAL_PER_LEVEL * (levels - 1)))),
        attack: ATTACK_FINAL,
        armor: ARMOR_FINAL,
    };
}

/**
 * Prints one line.
 *
 * @param {string} line The text.
 */
function printLine(line) {
    process.stdout.write(`${line}\n`);
}

const options = readOptions(process.argv.slice(2));
const runs = parseInt(options.runs || '300', 10);
const levelList = (options.levels || '1,10').split(',').map(Number);
const accuracies = (options.accuracy || '0.75,1').split(',').map(Number);
const phases = (options.phases || '5,10').split(',').map(Number);
const heroes = (options.hero || 'none,max').split(',');
const opponents = readOpponents();

for (const levels of levelList) {
    for (const phase of phases) {
        const final = phase === 10;
        const opponent = final ? opponents[opponents.length - 1] : opponents[phase - 1];
        const fight = fightWith(opponent, 'hp');
        printLine(`\n== ${levels} level(s), phase ${phase} (${opponent.key}${final ? ', final boss' : ''})`);
        for (const hero of heroes) {
            for (const accuracy of accuracies) {
                const cells = DIFFICULTIES.map(difficulty => {
                    let wins = 0;
                    let turns = 0;
                    let left = 0;
                    for (let seed = 1; seed <= runs; seed++) {
                        const result = playMatch({
                            seed, level: final ? levels : 1, phase, accuracy, difficulty, careful: true,
                            opponent: final ? opponent.key : undefined,
                            modifiers: fight.modifiers, powers: fight.powers, breathEvery: fight.breathEvery,
                            student: studentSide(hero, levels),
                        });
                        if (result.won) {
                            wins++;
                            turns += result.turns;
                            left += result.hpLeft;
                        }
                    }
                    const pct = Math.round((100 * wins) / runs);
                    const length = wins ? String(Math.round(turns / wins)) : '-';
                    const hpLeft = wins ? String(Math.round((100 * left) / wins)).padStart(3) : '  -';
                    return `${difficulty} ${String(pct).padStart(3)}% ${length.padStart(3)}t hp${hpLeft}%`;
                });
                printLine(`  hero ${hero.padEnd(4)} acc ${accuracy.toFixed(2)} | ${cells.join(' | ')}`);
            }
        }
    }
}
