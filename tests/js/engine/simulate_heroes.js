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
 * Plays whole Campaigns with a hero that grows only by what the student buys, so the shop's pace,
 * price and effect can be tuned against numbers instead of guesses.
 *
 * A simulated student plays the way simulate_balance.js does (the first Sword match it finds, a fixed share of
 * right answers), or, with --student careful, picks each move by the value of what it gives, the way the Normal
 * opponent does. They buy consumables with a share of the coins (--cons) and use them by the rules of the game,
 * phase after phase from Level 1, Phase 1 to the last,
 * through the real rules. Winning a phase pays its net coins; losing one pays nothing and retries the
 * same phase with the same hero. After every win the student spends all their coins, the hero's
 * specialty first and then whichever attribute is furthest behind. Purchases are limited by the stage
 * the hero is in, and the hero moves up a stage at 25%, 50% and 75% of the Campaign once the
 * specialty is at the stage's cap.
 *
 * Usage: node tests/js/engine/simulate_heroes.js [--levels 1,3,10] [--runs 40] [--accuracy 0.75]
 *   [--difficulty normal] [--hero hp|attack|armor] [--price 12] [--rate 0.4] [--bhp 0.05] [--hp 2.4]
 *   [--atk 1.9] [--arm 0.55] [--hpbasis base|phase] [--tries 30] [--policy balanced|specialty]
 *   [--student careful|naive] [--cons 0.3] [--byphase 1]
 *
 * With --power, no Campaign is played: for each level count it finds the HP and attack that win as
 * often as the armor (--arm) does when a hero puts every buy into that one attribute, in the last
 * quarter of the Campaign, so the three are worth the same. The armor is the reference because it has
 * a ceiling the other two do not.
 *
 * --cons is the share of the coins won each phase that goes to Potions, Swords and a Shield, up to the most a
 * phase can use of each; the rest goes to the hero's upgrades. --byphase also prints the first-try win rate of each
 * phase of a level, over every level, which shows the order of the opponents.
 *
 * --policy is how the simulated student orders their buys: the specialty to its cap first, or
 * whichever attribute is furthest behind.
 *
 * --rate is the buys per phase of the Campaign, per attribute (the whole Campaign allows
 * rate x phases); --hp/--atk/--arm are what the hero's HP multiple, attack multiple and armor reach
 * when every one of those buys is made; --bhp/--batk/--barm are the head start the hero gets in its
 * own specialty, as a share of that attribute's whole range (0.1 is a tenth of what it can reach).
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global process */

'use strict';

const {INSTANCE, readOpponents, fightWith, playMatch} = require('./sim_common.js');
const CombatRules = require('../../../amd/src/engine/combat_rules.js');

const ARMOR_CAP = 0.6;
const ATTRIBUTES = ['hp', 'armor', 'attack'];

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

const raw = readOptions(process.argv.slice(2));
const options = {
    levels: (raw.levels || '1,3,10').split(',').map(Number),
    runs: parseInt(raw.runs || '40', 10),
    accuracy: parseFloat(raw.accuracy || '0.75'),
    difficulty: raw.difficulty || 'normal',
    heroes: (raw.hero || 'hp,attack,armor').split(','),
    price: parseFloat(raw.price || '12'),
    rate: parseFloat(raw.rate || '0.4'),
    bonus: {hp: parseFloat(raw.bhp || '0.05'), attack: parseFloat(raw.batk || '0.05'), armor: parseFloat(raw.barm || '0.05')},
    hp: raw.hp === undefined ? null : parseFloat(raw.hp),
    atk: parseFloat(raw.atk || '1.9'),
    arm: parseFloat(raw.arm || '0.55'),
    hpbasis: raw.hpbasis || 'base',
    tries: parseInt(raw.tries || '30', 10),
    policy: raw.policy || 'balanced',
    student: raw.student || 'careful',
    cons: parseFloat(raw.cons || '0.3'),
    byphase: raw.byphase !== undefined,
};

// What the shop charges for the consumables a simulated student buys (combat::consumable_price()).
const CONSUMABLE_PRICES = {potion: 8, sword: 10, shield: 10};

const opponents = readOpponents();

/**
 * What the HP multiple reaches when every buy is made: --hp, or the game's own, which grows with the levels
 * so the hero keeps up with the opponents (heroes::HP_FINAL_BASE and HP_FINAL_PER_LEVEL).
 *
 * @param {number} levels The activity's level count.
 * @return {number}
 */
function hpFinal(levels) {
    return options.hp === null ? 2.4 + (0.62 * (levels - 1)) : options.hp;
}

/**
 * The hero for a Campaign of a given length: how many buys it allows and what each is worth.
 *
 * @param {number} levels The activity's level count.
 * @param {string} specialty 'hp', 'attack' or 'armor'.
 * @return {object} The hero's shape.
 */
function buildHero(levels, specialty) {
    const phases = levels * 10;
    const total = Math.max(1, Math.round(options.rate * phases));
    return {
        specialty, phases, total,
        steps: {hp: (hpFinal(levels) - 1) / total, attack: (options.atk - 1) / total, armor: options.arm / total},
        buys: {hp: 0, attack: 0, armor: 0},
        stage: 1,
    };
}

/**
 * The most buys of an attribute the hero's current stage allows.
 *
 * @param {object} hero The hero.
 * @return {number} The cap.
 */
function stageCap(hero) {
    return Math.round(hero.total * hero.stage / 4);
}

/**
 * The hero's side of the next phase.
 *
 * @param {object} hero The hero.
 * @param {number} level Level of the phase.
 * @param {number} phase Phase within the level.
 * @return {{hp: number, attack: number, armor: number}}
 */
function studentSide(hero, level, phase) {
    const worth = attribute => hero.buys[attribute]
        + (hero.specialty === attribute ? options.bonus[attribute] * hero.total : 0);
    const basis = options.hpbasis === 'phase' ? 1 + (0.3 * (level - 1)) + (0.05 * (phase - 1)) : 1;
    return {
        hp: Math.round(INSTANCE.basestudenthp * basis * (1 + (worth('hp') * hero.steps.hp))),
        attack: 1 + (worth('attack') * hero.steps.attack),
        armor: Math.min(ARMOR_CAP, worth('armor') * hero.steps.armor),
    };
}

/**
 * Buys Potions, Swords and a Shield with a share of the coins, in turn, up to the most a phase can use of each.
 *
 * @param {object} stock The consumables in hand, changed in place.
 * @param {number} coins Coins in hand.
 * @return {number} Coins left.
 */
function buyConsumables(stock, coins) {
    let budget = Math.floor(coins * options.cons);
    let left = coins;
    for (;;) {
        const available = budget;
        const wanted = Object.keys(CONSUMABLE_PRICES).filter(kind => stock[kind] < CombatRules.phaseLimit(kind)
            && CONSUMABLE_PRICES[kind] <= available);
        if (wanted.length === 0) {
            return left;
        }
        // The kind the student has fewest of compared with what a phase can use.
        wanted.sort((a, b) => (stock[a] / CombatRules.phaseLimit(a)) - (stock[b] / CombatRules.phaseLimit(b)));
        stock[wanted[0]]++;
        budget -= CONSUMABLE_PRICES[wanted[0]];
        left -= CONSUMABLE_PRICES[wanted[0]];
    }
}

/**
 * The consumable a careful student uses this turn, by the rules the opponent's items follow: a Sword when it
 * finishes the fight, a Potion when hurt below 40%, a Shield when below 60% with none armed.
 *
 * @param {object} stock The consumables in hand, changed in place when one is used.
 * @return {function(object, object, object): ?string}
 */
function consumeFrom(stock) {
    return (state, uses, rules) => {
        const free = kind => stock[kind] > 0 && (uses[kind] || 0) < CombatRules.phaseLimit(kind);
        let pick = null;
        if (free('sword') && state.currentHp <= CombatRules.resolveConsumableEffect('sword', state, rules).damageAmount) {
            pick = 'sword';
        } else if (free('potion') && state.currentPlayerHp < state.maxPlayerHp * 0.4) {
            pick = 'potion';
        } else if (free('shield') && !state.playerShieldReady && state.currentPlayerHp < state.maxPlayerHp * 0.6) {
            pick = 'shield';
        }
        if (pick) {
            stock[pick]--;
        }
        return pick;
    };
}

/**
 * Spends every coin the hero can: the specialty first, then the attribute furthest behind its cap,
 * moving up a stage whenever its conditions are met.
 *
 * @param {object} hero The hero.
 * @param {number} coins Coins in hand.
 * @param {number} completed Phases won so far.
 * @return {number} Coins left.
 */
function shop(hero, coins, completed) {
    let left = coins;
    for (;;) {
        if (hero.stage < 4 && completed >= Math.ceil(hero.phases * hero.stage / 4) && hero.buys[hero.specialty] >= stageCap(hero)) {
            hero.stage++;
            continue;
        }
        const cap = stageCap(hero);
        const open = ATTRIBUTES.filter(attribute => hero.buys[attribute] < cap);
        if (left < options.price || open.length === 0) {
            return left;
        }
        // 'specialty' fills the specialty to the cap before anything else; 'balanced' always buys what
        // is furthest behind, the specialty winning a tie.
        const behind = open.sort((a, b) => (hero.buys[a] / cap) - (hero.buys[b] / cap)
            || (a === hero.specialty ? -1 : 0) - (b === hero.specialty ? -1 : 0))[0];
        const pick = options.policy === 'specialty' && open.includes(hero.specialty) ? hero.specialty : behind;
        hero.buys[pick]++;
        left -= options.price;
    }
}

/**
 * Plays one Campaign.
 *
 * @param {number} run Run number, which seeds every match.
 * @param {number} levels The activity's level count.
 * @param {string} specialty The hero's specialty.
 * @return {object} How far it got and what it cost.
 */
function playCampaign(run, levels, specialty) {
    const hero = buildHero(levels, specialty);
    let coins = 0;
    let triesTotal = 0;
    const firstTry = [];
    const byPhase = [];
    const stock = {potion: 0, sword: 0, shield: 0};
    const consume = options.cons > 0 ? consumeFrom(stock) : undefined;
    for (let k = 1; k <= hero.phases; k++) {
        const level = Math.floor((k - 1) / 10) + 1;
        const phase = ((k - 1) % 10) + 1;
        const opponent = opponents[k === hero.phases ? opponents.length - 1 : phase - 1];
        let won = null;
        for (let attempt = 1; attempt <= options.tries && won === null; attempt++) {
            triesTotal++;
            const fight = fightWith(opponent, hero.specialty);
            const match = playMatch({
                seed: (run * 100003) + (k * 131) + attempt, level, phase, accuracy: options.accuracy,
                difficulty: options.difficulty, modifiers: fight.modifiers, powers: fight.powers,
                breathEvery: fight.breathEvery, opponent: opponent.key, student: studentSide(hero, level, phase),
                careful: options.student === 'careful', consume, maxlevels: levels,
            });
            if (attempt === 1) {
                firstTry.push(match.finished && match.won ? 1 : 0);
                byPhase.push({index: k === hero.phases ? 11 : phase, won: match.finished && match.won ? 1 : 0});
            }
            if (match.finished && match.won) {
                won = match;
            }
        }
        if (won === null) {
            return {completed: k - 1, stuck: k, triesTotal, firstTry, byPhase, hero, coins};
        }
        coins = options.cons > 0 ? buyConsumables(stock, coins + won.coins) : coins + won.coins;
        coins = shop(hero, coins, k);
    }
    return {completed: hero.phases, stuck: 0, triesTotal, firstTry, byPhase, hero, coins};
}

const percent = value => `${Math.round(value * 100)}%`.padStart(4);

/**
 * How often a hero that put everything into one attribute wins the last quarter of the Campaign.
 *
 * @param {number} levels The activity's level count.
 * @param {object} side The student's side to fight with.
 * @param {number} samples Matches per phase.
 * @return {number} Win rate, 0 to 1.
 */
function lateWinRate(levels, side, samples) {
    const phases = levels * 10;
    const first = Math.floor(phases * 0.75) + 1;
    const step = Math.max(1, Math.floor((phases - first + 1) / 6));
    let played = 0;
    let wins = 0;
    for (let k = first; k <= phases; k += step) {
        const level = Math.floor((k - 1) / 10) + 1;
        const phase = ((k - 1) % 10) + 1;
        const opponent = opponents[k === phases ? opponents.length - 1 : phase - 1];
        const fight = fightWith(opponent);
        for (let seed = 1; seed <= samples; seed++) {
            const match = playMatch({
                seed: (seed * 7001) + k, level, phase, accuracy: options.accuracy, difficulty: options.difficulty,
                modifiers: fight.modifiers, powers: fight.powers, breathEvery: fight.breathEvery, opponent: opponent.key,
                student: side, maxlevels: levels,
            });
            played++;
            wins += match.finished && match.won ? 1 : 0;
        }
    }
    return wins / played;
}

/**
 * Finds the value of one attribute that wins as often as a target, by bisection.
 *
 * @param {number} levels The activity's level count.
 * @param {string} attribute 'hp', 'attack' or 'armor'.
 * @param {number} target The win rate to match.
 * @return {number} The attribute value (an attack multiple, or an armor share), or NaN if out of reach.
 */
function matchPower(levels, attribute, target) {
    const sides = {
        hp: value => ({hp: Math.round(INSTANCE.basestudenthp * value), attack: 1, armor: 0}),
        attack: value => ({hp: INSTANCE.basestudenthp, attack: value, armor: 0}),
        armor: value => ({hp: INSTANCE.basestudenthp, attack: 1, armor: value}),
    };
    let [low, high] = {hp: [1, 80], attack: [1, 12], armor: [0, ARMOR_CAP]}[attribute];
    if (lateWinRate(levels, sides[attribute](high), 40) < target) {
        return NaN;
    }
    for (let i = 0; i < 9; i++) {
        const mid = (low + high) / 2;
        if (lateWinRate(levels, sides[attribute](mid), 40) < target) {
            low = mid;
        } else {
            high = mid;
        }
    }
    return (low + high) / 2;
}

if (raw.power !== undefined) {
    options.levels.forEach(levels => {
        // The armor is the reference: it has a ceiling the other two do not.
        const target = lateWinRate(levels, {hp: INSTANCE.basestudenthp, attack: 1, armor: options.arm}, 40);
        const hp = matchPower(levels, 'hp', target);
        const attack = matchPower(levels, 'attack', target);
        process.stdout.write(`${String(levels).padStart(2)} lv | all-in armor ${options.arm} wins ${percent(target)} `
            + `of the last quarter | HP that matches it: x${hp.toFixed(2)} | attack that matches it: x${attack.toFixed(2)}\n`);
    });
    process.exit(0);
}

process.stdout.write(`Student right ${percent(options.accuracy)}, ${options.difficulty}; price ${options.price}, `
    + `${options.rate} buys/phase, specialty head start (share of the attribute's range) hp ${options.bonus.hp} `
    + `attack ${options.bonus.attack} armor ${options.bonus.armor}, final HP x${options.hp === null ? 'by level' : options.hp} `
    + `(${options.hpbasis}), `
    + `attack x${options.atk}, armor ${options.arm}, ${options.policy} buys, ${options.student} student, `
    + `${Math.round(options.cons * 100)}% to consumables; ${options.runs} Campaigns per cell.\n`);
options.levels.forEach(levels => {
    options.heroes.forEach(specialty => {
        const runs = [];
        for (let run = 1; run <= options.runs; run++) {
            runs.push(playCampaign(run, levels, specialty));
        }
        const avg = pick => runs.reduce((sum, result) => sum + pick(result), 0) / runs.length;
        const quarters = [0, 1, 2, 3].map(quarter => {
            const cells = runs.flatMap(result => {
                const from = Math.floor(result.firstTry.length * quarter / 4);
                const to = Math.floor(result.firstTry.length * (quarter + 1) / 4);
                return result.firstTry.slice(from, to);
            });
            return cells.length ? cells.reduce((sum, value) => sum + value, 0) / cells.length : 0;
        });
        const finished = runs.filter(result => result.stuck === 0).length / runs.length;
        if (options.byphase) {
            const rows = {};
            runs.flatMap(result => result.byPhase).forEach(({index, won}) => {
                rows[index] = rows[index] || [0, 0];
                rows[index][0] += won;
                rows[index][1]++;
            });
            process.stdout.write(`    first-try win by phase of the level: ${Object.keys(rows).map(index =>
                `${index === '11' ? 'V' : index}:${Math.round((100 * rows[index][0]) / rows[index][1])}`).join(' ')}\n`);
        }
        process.stdout.write(`${String(levels).padStart(2)} lv ${specialty.padEnd(6)} | finished ${percent(finished)} | `
            + `tries/phase ${(avg(result => result.triesTotal / Math.max(1, result.firstTry.length))).toFixed(2)} | `
            + `1st-try win by quarter ${quarters.map(percent).join(' ')} | stage ${avg(result => result.hero.stage).toFixed(1)} | `
            + `buys hp/atk/arm ${avg(r => r.hero.buys.hp).toFixed(0)}/${avg(r => r.hero.buys.attack).toFixed(0)}/`
            + `${avg(r => r.hero.buys.armor).toFixed(0)} of ${runs[0].hero.total} | coins left ${avg(r => r.coins).toFixed(0)}\n`);
    });
});
