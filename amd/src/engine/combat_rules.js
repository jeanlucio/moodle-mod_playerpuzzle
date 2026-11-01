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
        ];

        // The fields a resumed phase takes from the server's rebuilt snapshot: all but the two
        // maximums, which the client always takes from its own page config.
        const RESUMABLE_FIELDS = STATE_FIELDS.filter(field => field !== 'maxPlayerHp' && field !== 'maxBossHp');

        // A right answer hits for this many times the phase's base damage, scaled by the
        // answering side's own multiplier.
        const CRITICAL_MULTIPLIER = 3;

        // A wrong player answer always costs this much HP, whatever the phase or difficulty.
        const WRONG_ANSWER_DAMAGE = 30;

        // The most of a hit any armor can absorb, so a hit always does some damage.
        const ARMOR_CAP = 0.6;

        // The opponent powers the rules know, as the server names them in the config's
        // bossPowers (classes/local/opponents.php decides who has which).
        const POWERS = {
            // The opponent starts the fight with its Shield armed.
            ICESHIELD: 'iceshield',
            // The opponent's Grimoire poison lasts one round longer.
            LONGPOISON: 'longpoison',
            // Each Grimoire the opponent matches fills more of its poison meter.
            FASTGRIMOIRE: 'fastgrimoire',
            // Each Orb the opponent matches gives it more mana.
            MANABOOST: 'manaboost',
        };

        // Rounds of poison a full Grimoire meter arms against the other side, and with the
        // opponent's longpoison power.
        const POISON_ROUNDS = 3;
        const LONG_POISON_ROUNDS = 4;

        // How much of the poison meter each matched Grimoire fills, and for an opponent with the
        // fastgrimoire power.
        const GRIMOIRE_POISON = 10;
        const FAST_GRIMOIRE_POISON = 15;

        // How much mana each matched Orb gives, and for an opponent with the manaboost power.
        const ORB_MANA = 20;
        const BOOSTED_ORB_MANA = 30;

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
            };
            if (hasPower(config, POWERS.ICESHIELD)) {
                state.bossShieldReady = true;
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
         * The HP the boss comes back at when revived: 50% of this match's own max HP.
         *
         * @param {number} maxBossHp This match's max boss HP.
         * @return {number}
         */
        function reviveHp(maxBossHp) {
            return Math.ceil(maxBossHp * 0.5);
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
            return {
                heal: config.baseDamage / 4,
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
         * @param {object} params.config {baseDamage, coinGain, coinFactor, bossPowers}.
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
                    next[`${side}Mana`] += gains.mana;
                    manaGained += gains.mana;
                }
            }

            // Sword damage is computed per match group (not per piece) so combo size drives
            // the multiplier directly: a 3-piece group always resolves to exactly baseDamage,
            // matching the original flat-per-piece baseline.
            for (const group of matchGroups) {
                if (group.type === 3) {
                    damageDealt += config.baseDamage * comboMultiplier(group.size);
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
         * half point behind, and last the phase's attack and armor apply (see hitAmount()).
         *
         * @param {string} currentTurn 'player' or 'boss' — whose match dealt it.
         * @param {number} damageDealt resolveMatchEffects()'s damageDealt.
         * @param {object} state Combat state after resolveMatchEffects().
         * @param {object} config The phase's attack and armor (see hitAmount()).
         * @return {number} The hit to apply to the other side.
         */
        function matchHitAmount(currentTurn, damageDealt, state, config) {
            const multiplied = Math.round(damageDealt * (currentTurn === 'player' ? state.playerMultiplier : 1));
            return hitAmount(multiplied, currentTurn, config);
        }

        /**
         * A hit as it lands after the attacker's attack and the defender's armor: the attack
         * multiplies it, the armor takes away its share (never more than ARMOR_CAP), and the
         * result is rounded to a whole number. Only hits go through here (Swords from the board,
         * critical answers, the Sword consumable); the wrong-answer penalty and poison do not.
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
         * What a judged question does: a right answer is a critical hit on the other side; a
         * wrong one resets the answering side's multiplier, and costs the player
         * WRONG_ANSWER_DAMAGE too (the boss has no HP penalty for a wrong guess). Returns the
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
                    outcome.hitBoss = hitAmount(config.baseDamage * CRITICAL_MULTIPLIER * state.playerMultiplier, 'player', config);
                } else {
                    outcome.hitPlayer = WRONG_ANSWER_DAMAGE;
                    outcome.resetPlayerMultiplier = true;
                }
            } else if (correct) {
                outcome.hitPlayer = hitAmount(config.baseDamage * CRITICAL_MULTIPLIER * state.bossMultiplier, 'boss', config);
            } else {
                outcome.resetBossMultiplier = true;
            }
            return outcome;
        }

        /**
         * Everything that happens to a side as its turn begins, before it moves. Today that is
         * only its pending poison tick (see resolvePoisonTick()).
         *
         * @param {string} side 'player' or 'boss' — whose turn begins.
         * @param {object} state Current combat state — copied, never mutated.
         * @param {object} config {baseDamage}.
         * @return {{state: object, poisonTicked: boolean}}
         */
        function startTurn(side, state, config) {
            const next = Object.assign({}, state);
            const hpField = side === 'player' ? 'currentPlayerHp' : 'currentHp';
            const roundsField = side === 'player' ? 'playerPoisonRounds' : 'bossPoisonRounds';
            const tick = resolvePoisonTick(next[hpField], next[roundsField], config.baseDamage);
            next[hpField] = tick.newHp;
            next[roundsField] = tick.newPoisonRounds;
            return {state: next, poisonTicked: tick.ticked};
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
                healAmount = config.baseDamage * 1.25;
                next.currentPlayerHp = Math.min(next.maxPlayerHp, next.currentPlayerHp + healAmount);
            } else if (type === 'shield') {
                next.playerShieldMeter += 100;
                resolveShieldMeters(next);
            } else if (type === 'magic') {
                next.playerPoisonMeter += 100;
                resolvePoisonMeters(next, config);
            } else if (type === 'sword') {
                damageAmount = hitAmount(config.baseDamage, 'player', config);
            }

            return {state: next, healAmount, damageAmount};
        }

        return {
            PHASE_LIMITS,
            STATE_FIELDS,
            RESUMABLE_FIELDS,
            CRITICAL_MULTIPLIER,
            WRONG_ANSWER_DAMAGE,
            ARMOR_CAP,
            POWERS,
            hasPower,
            initialState,
            comboMultiplier,
            pieceGains,
            resolvePoisonMeters,
            resolveShieldMeters,
            phaseLimit,
            needsRevive,
            reviveHp,
            hasNextPhase,
            resolveDamage,
            resolvePoisonTick,
            resolveMatchEffects,
            resolveConsumableEffect,
            matchHitAmount,
            hitAmount,
            questionOutcome,
            startTurn,
        };
    });
}(typeof define === 'function' && define.amd ? define : function(deps, factory) {
    module.exports = factory();
}));
