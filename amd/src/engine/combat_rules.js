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
 * Pure combat rules for PlayerPuzzle — decides what happens to HP/meters/gold/mana, never how
 * to render it, log it, or send it anywhere.
 *
 * Every function here takes plain data (numbers, plain state objects) and returns plain data.
 * None of them touch `this.scene`, Phaser, jQuery, AJAX, sound, or the on-screen history log —
 * all of that stays in combat.js, which calls these functions and then handles presentation
 * with whatever they returned. Functions that update several fields at once (resolvePoisonMeters,
 * resolveShieldMeters, resolveMatchEffects, resolveConsumableEffect) take a plain state object,
 * mutate a shallow copy of it, and return that copy — combat.js applies the result back onto its
 * own `this.*` fields (`Object.assign(this, result.state)`), never the other way around.
 *
 * UMD-wrapped exactly like mod_playerpuzzle/engine/board_rules — the same file loads as an AMD
 * module in the browser and as a plain CommonJS module in Node, so
 * tests/js/engine/combat_rules.test.js can run this logic headless, no Phaser involved.
 *
 * @module     mod_playerpuzzle/engine/combat_rules
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
(function(define) {
    define([], function() {
        'use strict';

        // Mirrors attempt_consumables::PHASE_LIMITS server-side — the server is still the
        // source of truth (use_stock.php re-validates), this copy only drives the in-combat
        // badges' enabled/disabled look without a round trip on every use. 'hint' has no fixed
        // limit here — its own cap is however much stock the student bought, checked separately.
        const PHASE_LIMITS = {shield: 1, magic: 1, potion: 3, sword: 3};

        // Every field of the combat state, in one place. combat.js copies exactly these to and
        // from its own fields, and classes/local/engine/combat_engine.php keeps the same list
        // for the server replay: a field added to one and not the other fails the parity tests
        // in tests/fixtures/combat_vectors.json.
        const STATE_FIELDS = [
            'currentPlayerHp', 'maxPlayerHp', 'currentHp', 'maxBossHp',
            'playerMultiplier', 'bossMultiplier',
            'playerShieldMeter', 'playerShieldReady', 'bossShieldMeter', 'bossShieldReady',
            'playerPoisonMeter', 'playerPoisonRounds', 'bossPoisonMeter', 'bossPoisonRounds',
            'playerMana', 'bossMana', 'playerGold', 'bossGold',
            'bossTurnCount', 'bossPotions', 'bossSwords',
        ];

        // The fields a resumed phase takes from the server's rebuilt snapshot: all but the two
        // maximums, which the client always takes from its own page config.
        const RESUMABLE_FIELDS = STATE_FIELDS.filter(field => field !== 'maxPlayerHp' && field !== 'maxBossHp');

        // A right answer hits for this many times the phase's base damage, scaled by the
        // answering side's own multiplier.
        const CRITICAL_MULTIPLIER = 3;

        // The most of a hit any armor can absorb, so a hit always does some damage.
        const ARMOR_CAP = 0.6;

        // The opponent powers the rules know, as the server names them in the config's
        // bossPowers (classes/local/opponents.php decides who has which).
        const POWERS = {
            // The opponent starts the fight with its Shield armed.
            ICESHIELD: 'iceshield',
            // The opponent's Grimoire starts partly filled and its poison lasts one round longer.
            LONGPOISON: 'longpoison',
            // Each Grimoire the opponent matches fills more of its poison meter.
            FASTGRIMOIRE: 'fastgrimoire',
            // Each Orb the opponent matches gives it more mana.
            MANABOOST: 'manaboost',
            // The Potions the opponent matches heal half as much again.
            ROOTHEAL: 'rootheal',
            // The opponent recovers part of its max HP as each of its turns begins.
            REGEN: 'regen',
            // The opponent recovers part of the damage its hits deal.
            DRAIN: 'drain',
            // The opponent's Swords hit harder on its first turn.
            AMBUSH: 'ambush',
            // The opponent's Shield arms by itself every few of its turns.
            INTANGIBLE: 'intangible',
            // Every few of its turns, the opponent lands a strong hit before it moves.
            BREATH: 'breath',
            // Every few of its turns, the opponent takes some of the student's coins.
            LOOT: 'loot',
        };

        // Rounds of poison a full Grimoire meter arms against the other side, and with the
        // opponent's longpoison power.
        const POISON_ROUNDS = 3;
        const LONG_POISON_ROUNDS = 4;

        // How much of its poison meter an opponent with the longpoison power starts with.
        const LONG_POISON_HEAD_START = 20;

        // How much of the poison meter each matched Grimoire fills, and for an opponent with the
        // fastgrimoire power.
        const GRIMOIRE_POISON = 10;
        const FAST_GRIMOIRE_POISON = 15;

        // How much mana each matched Orb gives, and for an opponent with the manaboost power.
        const ORB_MANA = 20;
        const BOOSTED_ORB_MANA = 24;

        // The opponent's starting items: none on level 1, one more per level after it, one more
        // on Hard and one more for the final boss, none at all on Easy; Potions and Swords
        // alternate, a Potion first, never past the student's own limit per phase. It drinks a
        // Potion (healing like the student's) below ITEM_POTION_BELOW of its max HP, and draws a
        // Sword (a hit of the base damage) when it wins the fight or the student is below
        // ITEM_SWORD_BELOW of theirs, never against an armed Shield.
        const ITEM_POTION_BELOW = 0.4;
        const ITEM_SWORD_BELOW = 0.3;
        const ITEM_POTION_HEAL = 1.25;

        // How much the opponent values each piece type (0 Star, 1 Grimoire, 2 Orb, 3 Sword,
        // 4 Shield, 5 Potion, 6 Coin) when it picks a move on Normal and Hard. The Potion's is
        // scaled by how hurt the opponent is, and a Shield is worth less while one is armed.
        const MOVE_WEIGHTS = [1.5, 1.5, 1.5, 3, 1.5, 3, 1];
        const ARMED_SHIELD_WEIGHT = 0.5;

        // On Hard, each power makes the opponent value the pieces that feed it more: power =>
        // {piece type: factor}.
        const PERSONALITY = {
            rootheal: {'5': 2},
            regen: {'5': 1.5},
            manaboost: {'2': 2},
            fastgrimoire: {'1': 2},
            longpoison: {'1': 2},
            iceshield: {'4': 2},
            intangible: {'4': 2},
            ambush: {'3': 1.5},
            breath: {'3': 1.5},
            drain: {'3': 1.5},
            loot: {'6': 1.25},
        };

        // Every how many of its turns an opponent with the loot power takes the student's coins, and
        // the percentage of them it takes each time.
        const LOOT_EVERY = 4;
        const LOOT_PERCENT = 10;

        // The loot comes with a hit worth this many times the base damage for each whole Coin
        // match's worth of coins taken, so taking more coins hits harder.
        const LOOT_HIT_MULTIPLIER = 2;

        // Every how many of its turns an opponent with the breath power lands it (unless the phase's config
        // sets its own rhythm, bossBreathEvery), and how many times the phase's base damage it hits for.
        const BREATH_EVERY = 10;
        const BREATH_MULTIPLIER = 1.5;

        // Every how many of its turns an opponent with the intangible power has its Shield armed.
        const INTANGIBLE_EVERY = 10;

        // How much harder an opponent with the ambush power's Swords hit on its first turn.
        const AMBUSH_MULTIPLIER = 2;

        // The percentage of the damage its hits deal that an opponent with the drain power recovers.
        const DRAIN_PERCENT = 30;

        // The percentage of its max HP an opponent with the regen power recovers per turn.
        const REGEN_PERCENT = 1;

        /**
         * Every how many of its turns the opponent lands its breath: the phase's own rhythm when the config
         * sets one (the final boss's, which depends on the hero), the default otherwise.
         *
         * @param {object} config The phase's rule config; bossBreathEvery is optional.
         * @return {number}
         */
        function breathEvery(config) {
            return config.bossBreathEvery || BREATH_EVERY;
        }

        /**
         * Whether the opponent has a power this phase.
         *
         * @param {object} config The phase's rule config; bossPowers is optional.
         * @param {string} power One of POWERS.
         * @return {boolean}
         */
        function hasPower(config, power) {
            return (config.bossPowers || []).includes(power);
        }

        /**
         * The combat state a phase starts from: both sides at full HP, every meter empty,
         * multipliers at 1 and no gold yet, except what the opponent's powers change at the start.
         *
         * @param {number} maxPlayerHp The student's max HP for this phase.
         * @param {number} maxBossHp The boss's max HP for this phase.
         * @param {object} [config] The phase's rule config; bossPowers is read.
         * @return {object} A state holding exactly STATE_FIELDS.
         */
        function initialState(maxPlayerHp, maxBossHp, config = {}) {
            const state = {
                currentPlayerHp: maxPlayerHp, maxPlayerHp,
                currentHp: maxBossHp, maxBossHp,
                playerMultiplier: 1, bossMultiplier: 1,
                playerShieldMeter: 0, playerShieldReady: false,
                bossShieldMeter: 0, bossShieldReady: false,
                playerPoisonMeter: 0, playerPoisonRounds: 0,
                bossPoisonMeter: 0, bossPoisonRounds: 0,
                playerMana: 0, bossMana: 0,
                playerGold: 0, bossGold: 0,
                bossTurnCount: 0,
                bossPotions: (config.bossKit || {}).potion || 0,
                bossSwords: (config.bossKit || {}).sword || 0,
            };
            if (hasPower(config, POWERS.ICESHIELD)) {
                state.bossShieldReady = true;
            }
            if (hasPower(config, POWERS.LONGPOISON)) {
                state.bossPoisonMeter = LONG_POISON_HEAD_START;
            }
            return state;
        }

        /**
         * Combo-size multiplier for pieces whose effect scales with match size (Sword/Coin):
         * 3 pieces = x1.0 (unchanged baseline), +0.5x per extra piece beyond 3 (4 = x1.5,
         * 5 = x2.0, extrapolating linearly for any longer run).
         *
         * @param {number} size Number of pieces in the matched run.
         * @return {number} The combo multiplier.
         */
        function comboMultiplier(size) {
            return 1 + (0.5 * Math.max(0, size - 3));
        }

        /**
         * Resolves both poison meters: filling one arms POISON_ROUNDS damage rounds against the
         * other side (LONG_POISON_ROUNDS for an opponent with longpoison), ticked once per that
         * side's own turn via resolvePoisonTick(), and resets subtracting 100, preserving any
         * overshoot for the next fill — same overflow behaviour as the mana meters.
         *
         * @param {object} state Object with playerPoisonMeter/bossPoisonMeter/
         *  bossPoisonRounds/playerPoisonRounds (mutated in place).
         * @param {object} [config] The phase's rule config; bossPowers is read.
         * @return {object} The same state object, for chaining.
         */
        function resolvePoisonMeters(state, config = {}) {
            if (state.playerPoisonMeter >= 100) {
                state.playerPoisonMeter -= 100;
                state.bossPoisonRounds += POISON_ROUNDS;
            }
            if (state.bossPoisonMeter >= 100) {
                state.bossPoisonMeter -= 100;
                state.playerPoisonRounds += hasPower(config, POWERS.LONGPOISON) ? LONG_POISON_ROUNDS : POISON_ROUNDS;
            }
            return state;
        }

        /**
         * Resolves both shield meters: filling one arms a single full block of the next hit
         * *received by that same side* (self-defence, unlike the poison meters) and resets to
         * 0 flat — no overflow preserved, since there is nothing to carry forward once the
         * block is armed.
         *
         * @param {object} state Object with playerShieldMeter/playerShieldReady/
         *  bossShieldMeter/bossShieldReady (mutated in place).
         * @return {object} The same state object, for chaining.
         */
        function resolveShieldMeters(state) {
            if (state.playerShieldMeter >= 100) {
                state.playerShieldMeter = 0;
                state.playerShieldReady = true;
            }
            if (state.bossShieldMeter >= 100) {
                state.bossShieldMeter = 0;
                state.bossShieldReady = true;
            }
            return state;
        }

        /**
         * Fixed maximum uses per phase/match for a consumable type, mirroring
         * attempt_consumables::PHASE_LIMITS. A type absent from that mirror (only 'hint') has
         * no fixed limit, so this returns Infinity — its real cap is owned stock instead,
         * checked separately.
         *
         * @param {string} type Consumable type.
         * @return {number}
         */
        function phaseLimit(type) {
            return type in PHASE_LIMITS ? PHASE_LIMITS[type] : Infinity;
        }

        /**
         * Whether the boss reaching 0 HP right now should revive it instead of ending the
         * match — true when a minimum question count is configured and the attempt hasn't
         * answered enough yet.
         *
         * @param {number} minQuestions The instance's configured minimum, 0 = no minimum.
         * @param {number} questionsTotal Questions answered so far this attempt.
         * @return {boolean} True when the boss should revive instead of the match ending.
         */
        function needsRevive(minQuestions, questionsTotal) {
            return minQuestions > 0 && questionsTotal < minQuestions;
        }

        /**
         * The HP the boss is left standing at when it would fall before the minimum of questions is answered: 1.
         * It does not come back with part of its life: the student only has to answer what is missing (see
         * bossResists()), so a strong hit costs nothing and a minimum of any size is only about the questions.
         *
         * @return {number}
         */
        function reviveHp() {
            return 1;
        }

        /**
         * Whether the boss is standing on its last HP waiting for the student to answer the questions still
         * missing: the minimum is not met and the boss has been brought down to that last HP. While it holds, the
         * student's Orb pieces fill the whole mana bar (see resolveMatchEffects()'s config.orbFull), so one Orb
         * combo asks the next question instead of five.
         *
         * @param {object} state Combat state (currentHp).
         * @param {number} minQuestions The instance's configured minimum, 0 = no minimum.
         * @param {number} questionsTotal Questions answered so far this attempt.
         * @return {boolean}
         */
        function bossResists(state, minQuestions, questionsTotal) {
            return needsRevive(minQuestions, questionsTotal) && state.currentHp <= reviveHp();
        }

        /**
         * Whether this victory is a mid-Campaign phase win (more phases/levels remain)
         * rather than the end of the whole attempt — mirrors the boundary check
         * advance_phase.php itself enforces server-side.
         *
         * @param {object} gameConfig The client's game config (gamemode/currentphase/
         *  currentlevel/maxlevels).
         * @return {boolean} True when a next phase or level exists to advance to.
         */
        function hasNextPhase(gameConfig) {
            if (gameConfig.gamemode !== 'campaign') {
                return false;
            }
            const phase = parseInt(gameConfig.currentphase, 10) || 1;
            const level = parseInt(gameConfig.currentlevel, 10) || 1;
            const maxlevels = parseInt(gameConfig.maxlevels, 10) || 1;
            return phase < 10 || level < maxlevels;
        }

        /**
         * Resolves one side taking damage: a ready shield blocks the hit entirely (consuming
         * itself) instead of reducing HP. Symmetric — used for both the player and the boss.
         *
         * @param {number} hp Current HP of the side being hit.
         * @param {boolean} shieldReady Whether that side's shield is currently armed.
         * @param {number} amount Damage amount before the shield check.
         * @return {{newHp: number, shieldConsumed: boolean, appliedAmount: number}}
         */
        function resolveDamage(hp, shieldReady, amount) {
            let appliedAmount = amount;
            let shieldConsumed = false;
            if (shieldReady) {
                shieldConsumed = true;
                appliedAmount = 0;
            }
            return {
                newHp: Math.max(0, hp - appliedAmount),
                shieldConsumed,
                appliedAmount,
            };
        }

        /**
         * Resolves one side's poison tick at the start of their turn: a no-op when no rounds
         * are armed, otherwise one round of damage and one round consumed. Symmetric — used
         * for both the player and the boss.
         *
         * @param {number} hp Current HP of the side ticking.
         * @param {number} poisonRounds Rounds of poison damage still armed for that side.
         * @param {number} tickDamage Damage dealt by a single tick (baseDamage).
         * @return {{newHp: number, newPoisonRounds: number, ticked: boolean}}
         */
        function resolvePoisonTick(hp, poisonRounds, tickDamage) {
            if (poisonRounds <= 0) {
                return {newHp: hp, newPoisonRounds: poisonRounds, ticked: false};
            }
            return {
                newHp: Math.max(0, hp - tickDamage),
                newPoisonRounds: poisonRounds - 1,
                ticked: true,
            };
        }

        /**
         * The unit every amount of a side is counted in: its hits, the Potions it drinks and the poison it inflicts.
         * Both sides use the phase's baseDamage, except when the config gives the student a unit of its own
         * (playerBaseDamage), which is how the Easy difficulty weakens the opponent without weakening the student.
         * Mirrors combat_engine::unit().
         *
         * @param {string} side 'player' or 'boss'.
         * @param {object} config The phase's rule config: baseDamage, and playerBaseDamage when the student's differs.
         * @return {number}
         */
        function unit(side, config) {
            if (side === 'player' && config.playerBaseDamage !== undefined) {
                return config.playerBaseDamage;
            }
            return config.baseDamage;
        }

        /**
         * What each piece a side destroys gives that side: the HP a Potion heals, the multiplier
         * a Star adds, and how much of its meter a Shield, Grimoire or Orb fills. The opponent's
         * powers change some of them for the opponent only.
         *
         * @param {string} side 'player' or 'boss'.
         * @param {object} config The phase's rule config: baseDamage and bossPowers are read.
         * @return {{heal: number, multiplier: number, shield: number, poison: number, mana: number}}
         */
        function pieceGains(side, config) {
            const boss = side === 'boss';
            const sideUnit = unit(side, config);
            return {
                // A quarter of the side's unit, or three eighths with rootheal.
                heal: boss && hasPower(config, POWERS.ROOTHEAL) ? sideUnit * 3 / 8 : sideUnit / 4,
                multiplier: 0.1,
                shield: 10,
                poison: boss && hasPower(config, POWERS.FASTGRIMOIRE) ? FAST_GRIMOIRE_POISON : GRIMOIRE_POISON,
                mana: boss && hasPower(config, POWERS.MANABOOST) ? BOOSTED_ORB_MANA : ORB_MANA,
            };
        }

        /**
         * Resolves everything a single match (one turn's destroyed pieces + match groups)
         * changes about combat state: HP/meters/mana/gold on the acting side, Sword damage
         * and Coin gain by combo size, and whether either side's mana just crossed 100. Never
         * touches rendering — no sound, no tween, no grid mutation, no history log, no
         * accessibility announcement; combat.js does all of that with what this returns.
         *
         * @param {object} params
         * @param {string} params.currentTurn 'player' or 'boss' — whose match this is.
         * @param {Array<number>} params.destroyedTypes Piece types destroyed this turn
         *  (0=Star, 1=Grimoire, 2=Orb, 3=Sword, 4=Shield, 5=Potion, 6=Coin).
         * @param {Array<{type: number, size: number}>} params.matchGroups Match groups this
         *  turn — only type and size (piece count) matter here, never piece identity.
         * @param {object} params.state Current combat state (HP/meters/mana/gold/
         *  multipliers for both sides) — copied, never mutated.
         * @param {object} params.config {baseDamage, coinGain, coinFactor, bossPowers} and, optionally, orbFull: true
         *  when the student's Orb pieces fill the whole mana bar (see bossResists()).
         * @return {object} {state, damageDealt, coinsGained, healGained, multiplierGained,
         *  shieldGained, poisonGained, manaGained, questionTriggered, triggeredBy}.
         */
        function resolveMatchEffects({currentTurn, destroyedTypes, matchGroups, state, config}) {
            const next = Object.assign({}, state);
            let damageDealt = 0;
            let coinsGained = 0;
            let healGained = 0;
            let multiplierGained = 0;
            let shieldGained = 0;
            let poisonGained = 0;
            let manaGained = 0;

            const side = currentTurn === 'player' ? 'player' : 'boss';
            const hpField = side === 'player' ? 'currentPlayerHp' : 'currentHp';
            const maxHpField = side === 'player' ? 'maxPlayerHp' : 'maxBossHp';
            const gains = pieceGains(side, config);
            for (const type of destroyedTypes) {
                if (type === 5) {
                    next[hpField] = Math.min(next[maxHpField], next[hpField] + gains.heal);
                    healGained += gains.heal;
                } else if (type === 0) {
                    next[`${side}Multiplier`] += gains.multiplier;
                    multiplierGained += gains.multiplier;
                } else if (type === 4) {
                    next[`${side}ShieldMeter`] += gains.shield;
                    shieldGained += gains.shield;
                } else if (type === 1) {
                    next[`${side}PoisonMeter`] += gains.poison;
                    poisonGained += gains.poison;
                } else if (type === 2) {
                    const mana = side === 'player' && config.orbFull ? 100 : gains.mana;
                    next[`${side}Mana`] += mana;
                    manaGained += mana;
                }
            }

            // Sword damage is computed per match group (not per piece) so combo size drives
            // the multiplier directly: a 3-piece group always resolves to exactly baseDamage,
            // matching the original flat-per-piece baseline.
            for (const group of matchGroups) {
                if (group.type === 3) {
                    damageDealt += unit(side, config) * comboMultiplier(group.size);
                }
            }

            // Coin reuses the same combo curve as Sword, over the separately configurable
            // coinGain base. The boss's own Coin total never buys anything (it has no shop) —
            // it exists purely to net against the student's balance at payout time.
            for (const group of matchGroups) {
                if (group.type === 6) {
                    const coins = config.coinGain * comboMultiplier(group.size) * config.coinFactor;
                    coinsGained += coins;
                    if (currentTurn === 'player') {
                        next.playerGold += coins;
                    } else {
                        next.bossGold += coins;
                    }
                }
            }

            next.playerMultiplier = Math.round(next.playerMultiplier * 10) / 10;
            next.bossMultiplier = Math.round(next.bossMultiplier * 10) / 10;
            resolvePoisonMeters(next, config);
            resolveShieldMeters(next);

            let questionTriggered = false;
            let triggeredBy = null;
            if (next.playerMana >= 100) {
                next.playerMana -= 100;
                questionTriggered = true;
                triggeredBy = 'player';
            } else if (next.bossMana >= 100) {
                next.bossMana -= 100;
                questionTriggered = true;
                triggeredBy = 'boss';
            }

            return {
                state: next,
                damageDealt, coinsGained, healGained, multiplierGained,
                shieldGained, poisonGained, manaGained,
                questionTriggered, triggeredBy,
            };
        }

        /**
         * The hit a match's Sword damage lands as: the player's own multiplier scales it (the
         * boss's never does — its multiplier only scales its critical hits), then it is
         * rounded to a whole number, so an odd base damage on a 4-piece combo never leaves a
         * half point behind; an opponent with the ambush power doubles it on its first turn;
         * and last the phase's attack and armor apply (see hitAmount()).
         *
         * @param {string} currentTurn 'player' or 'boss' — whose match dealt it.
         * @param {number} damageDealt resolveMatchEffects()'s damageDealt.
         * @param {object} state Combat state after resolveMatchEffects().
         * @param {object} config The phase's attack and armor (see hitAmount()); bossPowers is read.
         * @return {number} The hit to apply to the other side.
         */
        function matchHitAmount(currentTurn, damageDealt, state, config) {
            let multiplied = Math.round(damageDealt * (currentTurn === 'player' ? state.playerMultiplier : 1));
            if (isAmbush(currentTurn, state, config)) {
                multiplied *= AMBUSH_MULTIPLIER;
            }
            return hitAmount(multiplied, currentTurn, config);
        }

        /**
         * How the opponent values each piece type when it picks a move (see
         * board_rules.js::chooseMove()): none on Easy, so it plays the first Sword match it
         * finds; MOVE_WEIGHTS on Normal, with the Potion's scaled by how hurt it is and less for a
         * Shield while one is armed; on Hard, those times the PERSONALITY of each of its powers.
         * A config without a difficulty plays as Normal.
         *
         * @param {object} state Combat state: currentHp, maxBossHp and bossShieldReady are read.
         * @param {object} config The phase's rule config: difficulty and bossPowers are read.
         * @return {?Array<number>} Weight per piece type (index 0-6), or null on Easy.
         */
        function opponentMoveWeights(state, config) {
            if (config.difficulty === 'easy') {
                return null;
            }
            const weights = MOVE_WEIGHTS.slice();
            if (state.bossShieldReady) {
                weights[4] = ARMED_SHIELD_WEIGHT;
            }
            weights[5] = MOVE_WEIGHTS[5] * (state.maxBossHp - state.currentHp) / state.maxBossHp;
            if (config.difficulty === 'hard') {
                (config.bossPowers || []).forEach(power => {
                    Object.entries(PERSONALITY[power] || {}).forEach(([type, factor]) => {
                        weights[type] *= factor;
                    });
                });
            }
            return weights;
        }

        /**
         * The opponent's starting items for a phase (see ITEM_POTION_BELOW).
         *
         * @param {number} level The phase's level.
         * @param {string} difficulty 'easy', 'normal' or 'hard'.
         * @param {boolean} finalBoss Whether the opponent is the final boss.
         * @return {{potion: number, sword: number}}
         */
        function opponentKit(level, difficulty, finalBoss) {
            if (difficulty === 'easy') {
                return {potion: 0, sword: 0};
            }
            const items = Math.max(0, level - 1) + (difficulty === 'hard' ? 1 : 0) + (finalBoss ? 1 : 0);
            return {
                potion: Math.min(PHASE_LIMITS.potion, Math.ceil(items / 2)),
                sword: Math.min(PHASE_LIMITS.sword, Math.floor(items / 2)),
            };
        }

        /**
         * The item the opponent uses as its turn begins, if any: a Sword when it has one, the
         * student has no Shield armed, and the hit wins the fight or the student is below
         * ITEM_SWORD_BELOW of their max HP; otherwise a Potion when it has one and is below
         * ITEM_POTION_BELOW of its own.
         *
         * @param {object} state Combat state.
         * @param {object} config The phase's rule config: baseDamage and the attack and armor
         *  (see hitAmount()) are read.
         * @return {?string} 'sword', 'potion' or null.
         */
        function opponentItemUse(state, config) {
            if (state.bossSwords > 0 && !state.playerShieldReady) {
                const hit = hitAmount(unit('boss', config), 'boss', config);
                if (hit >= state.currentPlayerHp || state.currentPlayerHp < state.maxPlayerHp * ITEM_SWORD_BELOW) {
                    return 'sword';
                }
            }
            if (state.bossPotions > 0 && state.currentHp < state.maxBossHp * ITEM_POTION_BELOW) {
                return 'potion';
            }
            return null;
        }

        /**
         * Whether a match's Swords are an ambush: the opponent's, on its first turn, with the
         * ambush power.
         *
         * @param {string} currentTurn 'player' or 'boss' — whose match it is.
         * @param {object} state Combat state: bossTurnCount is read.
         * @param {object} config The phase's rule config; bossPowers is read.
         * @return {boolean}
         */
        function isAmbush(currentTurn, state, config) {
            return currentTurn === 'boss' && state.bossTurnCount === 1 && hasPower(config, POWERS.AMBUSH);
        }

        /**
         * A hit as it lands after the attacker's attack and the defender's armor: the attack
         * multiplies it, the armor takes away its share (never more than ARMOR_CAP), and the
         * result is rounded to a whole number. Only hits go through here (Swords from the board,
         * critical answers, the Sword consumable); poison does not.
         * A hit with no attack or armor to apply keeps its exact value, as before either
         * existed. The shield is checked afterwards, by resolveDamage().
         *
         * @param {number} amount The hit before attack and armor.
         * @param {string} attacker 'player' or 'boss' — who lands the hit.
         * @param {object} config {playerAttack, bossAttack, playerArmor, bossArmor}, each
         *  optional: a missing attack is 1, a missing armor 0.
         * @return {number}
         */
        function hitAmount(amount, attacker, config) {
            const attack = (attacker === 'player' ? config.playerAttack : config.bossAttack) || 1;
            const armor = Math.min(ARMOR_CAP, (attacker === 'player' ? config.bossArmor : config.playerArmor) || 0);
            if (attack === 1 && armor === 0) {
                return amount;
            }
            return Math.floor((amount * attack * (1 - armor)) + 0.5);
        }

        /**
         * The HP an opponent with the drain power recovers from one of its hits: DRAIN_PERCENT of
         * the damage that hit actually dealt (nothing when the shield took it), up to its max HP.
         * Only the opponent's hits drain (Swords, critical answers, its breath and Sword), never
         * poison. The caller adds it to currentHp.
         *
         * @param {number} appliedAmount The damage the hit dealt, from resolveDamage().
         * @param {object} state Current combat state: currentHp and maxBossHp are read.
         * @param {object} config The phase's rule config; bossPowers is read.
         * @return {number}
         */
        function drainAmount(appliedAmount, state, config) {
            if (!hasPower(config, POWERS.DRAIN)) {
                return 0;
            }
            return Math.min(state.maxBossHp - state.currentHp, appliedAmount * DRAIN_PERCENT / 100);
        }

        /**
         * What a judged question does: a right answer is a critical hit on the other side; a
         * wrong one resets the answering side's multiplier and costs nothing else. Returns the
         * amounts only — applying a hit goes through resolveDamage(), like every other hit. A
         * side that is not hit gets null, not 0: a hit of 0 would still spend a ready shield.
         *
         * @param {string} side 'player' or 'boss' — who answered.
         * @param {boolean} correct Whether the answer was right.
         * @param {object} state Current combat state.
         * @param {object} config {baseDamage}, plus the phase's attack and armor (see hitAmount()).
         * @return {{hitBoss: (number|null), hitPlayer: (number|null), resetPlayerMultiplier: boolean,
         *  resetBossMultiplier: boolean}}
         */
        function questionOutcome(side, correct, state, config) {
            const outcome = {hitBoss: null, hitPlayer: null, resetPlayerMultiplier: false, resetBossMultiplier: false};
            if (side === 'player') {
                if (correct) {
                    outcome.hitBoss = hitAmount(
                        unit('player', config) * CRITICAL_MULTIPLIER * state.playerMultiplier,
                        'player',
                        config
                    );
                } else {
                    outcome.resetPlayerMultiplier = true;
                }
            } else if (correct) {
                outcome.hitPlayer = hitAmount(unit('boss', config) * CRITICAL_MULTIPLIER * state.bossMultiplier, 'boss', config);
            } else {
                outcome.resetBossMultiplier = true;
            }
            return outcome;
        }

        /**
         * Everything that happens to a side as its turn begins, before it moves: its pending
         * poison tick (see resolvePoisonTick()); for the opponent, its turn count goes up (its
         * first turn is 1); with the intangible power, every INTANGIBLE_EVERY turns its Shield
         * arms; with the breath power, while it still stands, every BREATH_EVERY turns (or the config's
         * bossBreathEvery) it hits
         * the student for BREATH_MULTIPLIER times the base damage, through its attack, the
         * student's armor and Shield, and its own drain; with the loot power, every LOOT_EVERY
         * turns it takes LOOT_PERCENT of the student's coins for itself and hits for what it took
         * (see lootHit()); and with the regen
         * power, while it still stands, REGEN_PERCENT of its max HP comes back, up to the max.
         * Last, the opponent may use one of its items (see opponentItemUse()). The caller checks
         * whether the student survived the breath or a Sword.
         *
         * @param {string} side 'player' or 'boss' — whose turn begins.
         * @param {object} state Current combat state — copied, never mutated.
         * @param {object} config {baseDamage}, the phase's attack and armor (see hitAmount());
         *  bossPowers is read.
         * @return {{state: object, poisonTicked: boolean, regenerated: number, shieldArmed: boolean,
         *  breath: ?{hit: number, appliedAmount: number, shieldConsumed: boolean, drained: number},
         *  looted: number, raid: ?object, item: ?{kind: string, amount: number,
         *  appliedAmount: number, shieldConsumed: boolean, drained: number}} shieldArmed is true
         *  when the intangible power armed a Shield that was not armed yet; breath is null on a
         *  turn without one; looted is the coins taken, 0 if none; raid is the hit that came with
         *  them, shaped like breath, or null; item is the item the opponent used, or null.
         */
        function startTurn(side, state, config) {
            const next = Object.assign({}, state);
            const hpField = side === 'player' ? 'currentPlayerHp' : 'currentHp';
            const roundsField = side === 'player' ? 'playerPoisonRounds' : 'bossPoisonRounds';
            // The poison that ticks on a side was inflicted by the other one, in the other's unit.
            const tickUnit = unit(side === 'player' ? 'boss' : 'player', config);
            const tick = resolvePoisonTick(next[hpField], next[roundsField], tickUnit);
            next[hpField] = tick.newHp;
            next[roundsField] = tick.newPoisonRounds;
            let shieldArmed = false;
            if (side === 'boss') {
                next.bossTurnCount += 1;
                if (hasPower(config, POWERS.INTANGIBLE) && next.bossTurnCount % INTANGIBLE_EVERY === 0) {
                    shieldArmed = !next.bossShieldReady;
                    next.bossShieldReady = true;
                }
            }

            let breath = null;
            if (side === 'boss' && hasPower(config, POWERS.BREATH) && next.currentHp > 0
                    && next.bossTurnCount % breathEvery(config) === 0) {
                const hit = hitAmount(unit('boss', config) * BREATH_MULTIPLIER, 'boss', config);
                const applied = resolveDamage(next.currentPlayerHp, next.playerShieldReady, hit);
                next.currentPlayerHp = applied.newHp;
                if (applied.shieldConsumed) {
                    next.playerShieldReady = false;
                }
                const drained = drainAmount(applied.appliedAmount, next, config);
                next.currentHp += drained;
                breath = {hit, appliedAmount: applied.appliedAmount, shieldConsumed: applied.shieldConsumed, drained};
            }

            let looted = 0;
            if (side === 'boss' && hasPower(config, POWERS.LOOT) && next.bossTurnCount % LOOT_EVERY === 0) {
                looted = next.playerGold * LOOT_PERCENT / 100;
                next.playerGold -= looted;
                next.bossGold += looted;
            }
            const raid = looted > 0 ? lootHit(next, looted, config) : null;

            let regenerated = 0;
            if (side === 'boss' && hasPower(config, POWERS.REGEN) && next.currentHp > 0) {
                regenerated = Math.min(next.maxBossHp - next.currentHp, next.maxBossHp * REGEN_PERCENT / 100);
                next.currentHp += regenerated;
            }
            const item = useOpponentItem(side, next, config);
            return {state: next, poisonTicked: tick.ticked, regenerated, shieldArmed, breath, looted, raid, item};
        }

        /**
         * The hit that comes with the loot: LOOT_HIT_MULTIPLIER times the base damage for each
         * whole Coin match's worth of the coins taken, through the opponent's attack, the
         * student's armor and Shield, and its own drain, on the state startTurn() is building.
         * Measured in Coin matches, so how many coins a match pays does not change how hard it
         * hits; with no coins to pay, it never hits.
         *
         * @param {object} next The state startTurn() is building (mutated in place).
         * @param {number} looted The coins taken.
         * @param {object} config The phase's rule config: baseDamage, coinGain, coinFactor and
         *  the attack and armor (see hitAmount()) are read.
         * @return {?{hit: number, appliedAmount: number, shieldConsumed: boolean, drained: number}}
         */
        function lootHit(next, looted, config) {
            const coinMatch = (config.coinGain || 0) * (config.coinFactor || 1);
            if (!(coinMatch > 0)) {
                return null;
            }
            const hit = hitAmount(unit('boss', config) * LOOT_HIT_MULTIPLIER * looted / coinMatch, 'boss', config);
            const applied = resolveDamage(next.currentPlayerHp, next.playerShieldReady, hit);
            next.currentPlayerHp = applied.newHp;
            if (applied.shieldConsumed) {
                next.playerShieldReady = false;
            }
            const drained = drainAmount(applied.appliedAmount, next, config);
            next.currentHp += drained;
            return {hit, appliedAmount: applied.appliedAmount, shieldConsumed: applied.shieldConsumed, drained};
        }

        /**
         * Uses the opponent's item as its turn begins, if any (see opponentItemUse()), on the
         * state startTurn() is building.
         *
         * @param {string} side Whose turn begins.
         * @param {object} next The state startTurn() is building (mutated in place).
         * @param {object} config The phase's rule config.
         * @return {?{kind: string, amount: number, appliedAmount: number, shieldConsumed: boolean,
         *  drained: number}}
         */
        function useOpponentItem(side, next, config) {
            const kind = side === 'boss' && next.currentHp > 0 ? opponentItemUse(next, config) : null;
            if (kind === 'potion') {
                const amount = Math.min(next.maxBossHp - next.currentHp, unit('boss', config) * ITEM_POTION_HEAL);
                next.bossPotions -= 1;
                next.currentHp += amount;
                return {kind, amount, appliedAmount: 0, shieldConsumed: false, drained: 0};
            }
            if (kind === 'sword') {
                const amount = hitAmount(unit('boss', config), 'boss', config);
                const applied = resolveDamage(next.currentPlayerHp, next.playerShieldReady, amount);
                next.bossSwords -= 1;
                next.currentPlayerHp = applied.newHp;
                if (applied.shieldConsumed) {
                    next.playerShieldReady = false;
                }
                const drained = drainAmount(applied.appliedAmount, next, config);
                next.currentHp += drained;
                return {kind, amount, appliedAmount: applied.appliedAmount, shieldConsumed: applied.shieldConsumed, drained};
            }
            return null;
        }

        /**
         * Resolves a used consumable's effect on combat state. Sword deliberately returns
         * only the damage amount rather than applying it: applying damage always goes through
         * resolveDamage() (the shield-check/clamp logic), which combat.js's own
         * applyDamageToBoss() already wraps — resolving it here too would duplicate that
         * shield-check outside its one real implementation.
         *
         * @param {string} type One of 'potion', 'shield', 'magic', 'sword'.
         * @param {object} state Current combat state (only the player-side fields consumables
         *  ever touch) — copied, never mutated.
         * @param {object} config {baseDamage}, plus the phase's attack and armor (see hitAmount()).
         * @return {{state: object, healAmount: number, damageAmount: number}}
         */
        function resolveConsumableEffect(type, state, config) {
            const next = Object.assign({}, state);
            let healAmount = 0;
            let damageAmount = 0;

            if (type === 'potion') {
                healAmount = unit('player', config) * 1.25;
                next.currentPlayerHp = Math.min(next.maxPlayerHp, next.currentPlayerHp + healAmount);
            } else if (type === 'shield') {
                next.playerShieldMeter += 100;
                resolveShieldMeters(next);
            } else if (type === 'magic') {
                next.playerPoisonMeter += 100;
                resolvePoisonMeters(next, config);
            } else if (type === 'sword') {
                damageAmount = hitAmount(unit('player', config), 'player', config);
            }

            return {state: next, healAmount, damageAmount};
        }

        return {
            PHASE_LIMITS,
            STATE_FIELDS,
            RESUMABLE_FIELDS,
            CRITICAL_MULTIPLIER,
            ARMOR_CAP,
            POWERS,
            hasPower,
            unit,
            breathEvery,
            initialState,
            comboMultiplier,
            pieceGains,
            resolvePoisonMeters,
            resolveShieldMeters,
            phaseLimit,
            needsRevive,
            bossResists,
            reviveHp,
            hasNextPhase,
            resolveDamage,
            resolvePoisonTick,
            resolveMatchEffects,
            resolveConsumableEffect,
            matchHitAmount,
            isAmbush,
            opponentMoveWeights,
            opponentKit,
            opponentItemUse,
            hitAmount,
            drainAmount,
            questionOutcome,
            startTurn,
        };
    });
}(typeof define === 'function' && define.amd ? define : function(deps, factory) {
    module.exports = factory();
}));
