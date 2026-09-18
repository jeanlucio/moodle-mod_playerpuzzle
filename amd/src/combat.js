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
 * Combat and Rules Module for PlayerPuzzle.
 *
 * @module     mod_playerpuzzle/combat
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/templates', 'core/config',
        'mod_playerpuzzle/accessibility'],
        function($, Ajax, Notification, Templates, Config, Accessibility) {
    'use strict';

    // Mirrors combat::CONSUMABLE_PRICES server-side — the server is still the source of
    // truth (buy_consumable re-validates), this copy only drives the shop badges/afford
    // check without a round trip on every coin change.
    const CONSUMABLE_PRICES = {potion: 8, shield: 10, magic: 12, sword: 10, hint: 5};

    // Maps a piece's numeric type (0-6, same indexing as board.js's own PIECE_NAME_KEYS) to
    // the lang string key holding its one-time tutorial balloon text.
    const PIECE_TUTORIAL_KEYS = [
        'tutorialballoon_star', 'tutorialballoon_grimoire', 'tutorialballoon_orb',
        'tutorialballoon_sword', 'tutorialballoon_shield', 'tutorialballoon_potion',
        'tutorialballoon_coin'
    ];

    /**
     * Sends one combat checkpoint via navigator.sendBeacon(), replicating the envelope
     * lib/ajax/service.php expects (methodname/args) — sendBeacon has no XHR/Promise
     * machinery of its own to route through core/ajax. Same pattern Moodle core itself uses
     * for exactly this kind of "must survive page unload" write (see
     * lib/editor/tiny/plugins/autosave/amd/src/repository.js::removeAutosaveSession()). Used
     * only on page unload/backgrounding — an ordinary awaited core/ajax call has no guarantee
     * of completing before the browser tears the page down.
     *
     * @param {object} args Web service arguments for mod_playerpuzzle_save_combat_state.
     */
    function sendCheckpointBeacon(args) {
        const requestUrl = new URL(`${Config.wwwroot}/lib/ajax/service.php`);
        requestUrl.searchParams.set('sesskey', Config.sesskey);
        navigator.sendBeacon(requestUrl, JSON.stringify([{
            index: 0,
            methodname: 'mod_playerpuzzle_save_combat_state',
            args,
        }]));
    }

    class CombatHandler {
        constructor(scene, gameConfig, strings) {
            this.scene = scene;
            this.gameConfig = gameConfig;
            this.strings = strings;

            this.baseDamage = parseInt(gameConfig.bossdamage) || 10;
            // Deliberately never scaled by level/phase, unlike baseDamage — a teacher's
            // fixed-price consumable shop would otherwise get proportionally cheaper as a
            // campaign progresses.
            this.coinGain = parseInt(gameConfig.coingain) || 10;
            // Difficulty coin multiplier (Easy 0.5, Normal 1, Hard 3), applied to every coin
            // gained so the HUD, the history log and the end screen all match what the client
            // reports — the server independently re-derives the bankable amount from its own
            // ledger (coins_earned/boss_coins_earned/coins_spent), capped by a damage-based
            // ceiling, never trusting this value outright.
            this.coinFactor = parseFloat(gameConfig.coinfactor) || 1;
            // Minimum-questions rule: the server is the only source of truth for how many
            // questions this attempt has answered (questionsTotal starts at whatever a resumed
            // attempt already carries from earlier phases and only ever moves forward, mirroring
            // validate_answer.php's own count) — the client never counts on its own.
            this.minQuestions = parseInt(gameConfig.minquestions, 10) || 0;
            this.questionsTotal = parseInt(gameConfig.questionstotal, 10) || 0;
            // Consumable shop: uses/spend carried forward from the current phase/match's own
            // ledger window (see coin_ledger.php), same pattern as questionsTotal above.
            this.maxConsumables = parseInt(gameConfig.maxconsumables, 10) || 1;
            this.consumableUses = Object.assign(
                {potion: 0, shield: 0, magic: 0, sword: 0, hint: 0},
                gameConfig.consumableuses || {}
            );
            this.coinsSpent = parseInt(gameConfig.coinsspent, 10) || 0;
            this.currentTurn = 'player';

            // First-attempt tutorial (Fase 9): the server already decided istutorial once, at
            // attempt creation (security::generate_attempt_token()) — this is a read-only
            // mirror, never re-derived client-side. tutorialSeenTypes tracks which piece types
            // already got their one-time context balloon this session (not persisted — a
            // reload simply re-shows any type not yet seen again, a harmless repeat, not a bug).
            this.istutorial = !!gameConfig.istutorial;
            this.tutorialSeenTypes = new Set();
            this.tutorialQuestionInstructionShown = false;

            // Carried forward from the ledger, not reset to 0 — a mid-phase page reload (or a
            // Campaign attempt resuming a phase already partway through) must not forget coins
            // already earned this window.
            this.playerGold = parseInt(gameConfig.coinsearnedsofar, 10) || 0;
            this.playerShieldMeter = 0;
            this.playerShieldReady = false;
            this.playerMultiplier = 1;
            this.playerMana = 0;
            this.playerPoisonMeter = 0;
            this.playerPoisonRounds = 0;
            // The studenthp/bosshp config values are already scaled server-side for the
            // attempt's current level/phase (combat::calculate_boss_hp()/calculate_student_hp())
            // — Single Match always resolves to the base HP unchanged, so no branching is
            // needed here for game mode.
            this.maxPlayerHp = parseInt(gameConfig.studenthp) || 100;
            this.currentPlayerHp = this.maxPlayerHp;

            this.bossGold = parseInt(gameConfig.bosscoinsearnedsofar, 10) || 0;
            this.bossPoisonMeter = 0;
            this.bossPoisonRounds = 0;
            this.bossShieldMeter = 0;
            this.bossShieldReady = false;
            this.bossMana = 0;
            this.bossMultiplier = 1;
            this.maxBossHp = parseInt(gameConfig.bosshp) || 1000;
            this.currentHp = this.maxBossHp;

            // A checkpointed fight overrides every HP/meter/turn default set above with its
            // last saved values — the board grid itself is restored separately,
            // by board.js reading this same gameConfig.combatstate.boardgrid. Absent for a
            // phase that never got one (a fresh start, or one just advanced past), in which
            // case every side simply starts at full HP as already set up above.
            this.hydrateFromCheckpoint(gameConfig.combatstate);

            this._checkpointDirty = false;
            this.startCheckpointing();
        }

        /**
         * Restores HP/meters/turn from a checkpointed snapshot, if one exists for the
         * current phase.
         *
         * @param {object|null} state Decoded combatstate from gameConfig, or null/undefined.
         */
        hydrateFromCheckpoint(state) {
            if (!state) {
                return;
            }

            this.currentPlayerHp = state.currentplayerhp;
            this.currentHp = state.currentbosshp;
            this.playerShieldMeter = state.playershieldmeter;
            this.playerShieldReady = state.playershieldready;
            this.playerPoisonMeter = state.playerpoisonmeter;
            this.playerPoisonRounds = state.playerpoisonrounds;
            this.playerMana = state.playermana;
            this.playerMultiplier = state.playermultiplier;
            this.bossShieldMeter = state.bossshieldmeter;
            this.bossShieldReady = state.bossshieldready;
            this.bossPoisonMeter = state.bosspoisonmeter;
            this.bossPoisonRounds = state.bosspoisonrounds;
            this.bossMana = state.bossmana;
            this.bossMultiplier = state.bossmultiplier;
            this.currentTurn = state.currentturn;
        }

        /**
         * Starts the reload-resilience checkpoint: every ~10s, if something
         * changed since the last one and the tab is visible, persists the current board/HP/
         * meters/turn so a reload resumes this same fight instead of restarting the phase
         * from scratch. A plain setInterval, not Phaser's own scene time source, so it keeps
         * ticking even while the question modal has the scene paused — real time still
         * passes, and the state at that point is still the current one to save.
         *
         * A second, final checkpoint fires once on page unload/backgrounding
         * (visibilitychange to hidden, and pagehide) via navigator.sendBeacon() — an ordinary
         * awaited AJAX call has no guarantee of completing before the browser tears the page
         * down, which is exactly the gap sendBeacon exists to close (also covers a mobile OS
         * backgrounding the tab, since visibilitychange fires before it can suspend it).
         */
        startCheckpointing() {
            setInterval(() => {
                if (document.hidden || !this._checkpointDirty) {
                    return;
                }
                this.sendCheckpoint(false);
            }, 10000);

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.sendCheckpoint(true);
                }
            });
            window.addEventListener('pagehide', () => this.sendCheckpoint(true));
        }

        /**
         * Builds and sends the current board/HP/meters/turn checkpoint. Never called per
         * board move — only from the periodic timer and the page-unload listeners set up by
         * startCheckpointing().
         *
         * @param {boolean} useBeacon True for the page-unload path (sendBeacon, fire and
         *  forget); false for the periodic path (core/ajax, a normal awaited call — the tab
         *  isn't closing, so there is no urgency).
         */
        sendCheckpoint(useBeacon) {
            const board = this.scene.board;
            if (!board) {
                return;
            }

            const boardgrid = [];
            for (let row = 0; row < board.rows; row++) {
                for (let col = 0; col < board.cols; col++) {
                    boardgrid.push(board.grid[row][col].type);
                }
            }

            const args = {
                cmid: this.gameConfig.cmid,
                token: this.gameConfig.token,
                boardgrid,
                currentplayerhp: Math.round(this.currentPlayerHp),
                currentbosshp: Math.round(this.currentHp),
                playershieldmeter: Math.round(this.playerShieldMeter),
                playershieldready: this.playerShieldReady,
                playerpoisonmeter: Math.round(this.playerPoisonMeter),
                playerpoisonrounds: this.playerPoisonRounds,
                playermana: Math.round(this.playerMana),
                playermultiplier: this.playerMultiplier,
                bossshieldmeter: Math.round(this.bossShieldMeter),
                bossshieldready: this.bossShieldReady,
                bosspoisonmeter: Math.round(this.bossPoisonMeter),
                bosspoisonrounds: this.bossPoisonRounds,
                bossmana: Math.round(this.bossMana),
                bossmultiplier: this.bossMultiplier,
                currentturn: this.currentTurn,
            };

            this._checkpointDirty = false;

            if (useBeacon) {
                sendCheckpointBeacon(args);
                return;
            }

            Ajax.call([{methodname: 'mod_playerpuzzle_save_combat_state', args}])[0].fail(() => {
                // A missed periodic checkpoint is not user-visible and not worth retrying —
                // the next tick (or the final beacon on exit) tries again with fresher state.
                this._checkpointDirty = true;
            });
        }

        /**
         * Combo-size multiplier for pieces whose effect scales with match size (Sword/Coin):
         * 3 pieces = x1.0 (unchanged baseline), +0.5x per extra piece beyond 3 (4 = x1.5,
         * 5 = x2.0, extrapolating linearly for any longer run).
         *
         * @param {number} size Number of pieces in the matched run.
         * @returns {number} The combo multiplier.
         */
        comboMultiplier(size) {
            return 1 + (0.5 * Math.max(0, size - 3));
        }

        /**
         * Resolves both poison meters: filling one arms 3 damage rounds against the opponent
         * (ticked once per their own turn in passTurnToBoss()/passTurnToPlayer()) and resets
         * subtracting 100, preserving any overshoot for the next fill — same overflow
         * behaviour as the mana meters.
         */
        resolvePoisonMeters() {
            if (this.playerPoisonMeter >= 100) {
                this.playerPoisonMeter -= 100;
                this.bossPoisonRounds += 3;
            }
            if (this.bossPoisonMeter >= 100) {
                this.bossPoisonMeter -= 100;
                this.playerPoisonRounds += 3;
            }
        }

        /**
         * Resolves both shield meters: filling one arms a single full block of the next hit
         * *received by that same side* (self-defence, unlike the poison meters) and resets to
         * 0 flat — no overflow preserved, since there is nothing to carry forward once the
         * block is armed.
         */
        resolveShieldMeters() {
            if (this.playerShieldMeter >= 100) {
                this.playerShieldMeter = 0;
                this.playerShieldReady = true;
            }
            if (this.bossShieldMeter >= 100) {
                this.bossShieldMeter = 0;
                this.bossShieldReady = true;
            }
        }

        processEffects(destroyedPieces, matchGroups) {
            const me = this.scene;
            let questionTriggered = false;
            let triggeredBy = null;
            let damageDealt = 0;
            let coinsGained = 0;
            let healGained = 0;
            let multiplierGained = 0;
            let shieldGained = 0;
            let poisonGained = 0;
            let manaGained = 0;

            me.sfxMatch.play();

            for (const piece of destroyedPieces) {
                if (this.currentTurn === 'player') {
                    me.sfxHit.play();
                    if (piece.type === 5) {
                        const heal = this.baseDamage / 4;
                        this.currentPlayerHp = Math.min(this.maxPlayerHp, this.currentPlayerHp + heal);
                        healGained += heal;
                    } else if (piece.type === 0) {
                        this.playerMultiplier += 0.1;
                        multiplierGained += 0.1;
                    } else if (piece.type === 4) {
                        this.playerShieldMeter += 10;
                        shieldGained += 10;
                    } else if (piece.type === 1) {
                        this.playerPoisonMeter += 10;
                        poisonGained += 10;
                    } else if (piece.type === 2) {
                        this.playerMana += 20;
                        manaGained += 20;
                    }
                } else {
                    if (piece.type === 2) {
                        this.bossMana += 20;
                        manaGained += 20;
                    } else if (piece.type === 0) {
                        this.bossMultiplier += 0.1;
                        multiplierGained += 0.1;
                    } else if (piece.type === 1) {
                        this.bossPoisonMeter += 10;
                        poisonGained += 10;
                    } else if (piece.type === 4) {
                        this.bossShieldMeter += 10;
                        shieldGained += 10;
                    } else if (piece.type === 5) {
                        const heal = this.baseDamage / 4;
                        this.currentHp = Math.min(this.maxBossHp, this.currentHp + heal);
                        healGained += heal;
                    }
                }

                me.board.grid[piece.row][piece.col] = null;
                me.tweens.add({
                    targets: piece, scaleX: 0, scaleY: 0, duration: 200,
                    onComplete: (tween, targets) => {
                        targets[0].destroy();
                    }
                });
            }

            // Sword damage is computed per match group (not per piece) so combo size drives the
            // multiplier directly: a 3-piece group always resolves to exactly baseDamage, matching
            // the original flat-per-piece baseline.
            for (const group of matchGroups) {
                if (group.type === 3) {
                    damageDealt += this.baseDamage * this.comboMultiplier(group.pieces.length);
                }
            }

            // Coin reuses the same combo curve as Sword, over the separately configurable
            // coinGain base. The boss's own Coin total never buys anything (it has no shop) —
            // it exists purely to net against the student's balance in showEndScreen().
            for (const group of matchGroups) {
                if (group.type === 6) {
                    const coins = this.coinGain * this.comboMultiplier(group.pieces.length) * this.coinFactor;
                    coinsGained += coins;
                    if (this.currentTurn === 'player') {
                        this.playerGold += coins;
                    } else {
                        this.bossGold += coins;
                    }
                }
            }

            this.playerMultiplier = Math.round(this.playerMultiplier * 10) / 10;
            this.bossMultiplier = Math.round(this.bossMultiplier * 10) / 10;
            this.resolvePoisonMeters();
            this.resolveShieldMeters();
            this.logTurnEffects(
                damageDealt, coinsGained, healGained, multiplierGained, shieldGained, poisonGained, manaGained
            );

            if (this.playerMana >= 100) {
                this.playerMana -= 100;
                questionTriggered = true;
                triggeredBy = 'player';
            } else if (this.bossMana >= 100) {
                this.bossMana -= 100;
                questionTriggered = true;
                triggeredBy = 'boss';
            }

            this.triggerTutorialBalloons(destroyedPieces, matchGroups);
            this.updateUI();
            return {damage: damageDealt, question: questionTriggered, trigger: triggeredBy};
        }

        /**
         * Fires the one-time tutorial context balloon for each newly-matched piece type this
         * turn, in a first-attempt tutorial only, and only for the player's own turn (the
         * boss's matches are never something the student needs explained). Kept as its own
         * method rather than inlined into processEffects()'s own loops: that function is
         * already at ESLint's complexity ceiling, and every type this checks is already known
         * once destroyedPieces/matchGroups exist, so a second, simpler pass over the same data
         * costs nothing beyond the one extra call site.
         *
         * @param {Array} destroyedPieces Pieces destroyed this turn (star/grimoire/orb/
         *  shield/potion effects are resolved per piece).
         * @param {Array} matchGroups Match groups this turn (sword/coin effects are resolved
         *  per group, driven by combo size).
         */
        triggerTutorialBalloons(destroyedPieces, matchGroups) {
            if (!this.istutorial || this.currentTurn !== 'player') {
                return;
            }
            for (const piece of destroyedPieces) {
                this.maybeShowTutorialBalloon(piece.type);
            }
            for (const group of matchGroups) {
                if (group.type === 3 || group.type === 6) {
                    this.maybeShowTutorialBalloon(group.type);
                }
            }
        }

        /**
         * Shows a one-time context balloon explaining a piece type's effect. A no-op for
         * every later match of the same type this session — callers (triggerTutorialBalloons())
         * already guard on istutorial/currentTurn, this only adds the per-type "seen" check.
         *
         * @param {number} type Piece type (0-6).
         */
        maybeShowTutorialBalloon(type) {
            if (this.tutorialSeenTypes.has(type)) {
                return;
            }
            this.tutorialSeenTypes.add(type);

            const key = PIECE_TUTORIAL_KEYS[type];
            const text = key && this.strings[key];
            if (text) {
                this.scene.ui.showTutorialBalloon(text);
            }
        }

        /**
         * Appends one action-history line per non-zero effect resolved by this turn's match,
         * to the acting side's own history panel (this.currentTurn). Damage is logged here
         * even though it is only actually applied later by the caller (board.js, via
         * applyDamageToBoss()/applyDamageToPlayer()) — the amount logged is exactly what will
         * be applied, so this is not misleading, just slightly ahead of the HP bar update.
         *
         * @param {number} damage Sword damage dealt this turn.
         * @param {number} coins Coin gained this turn.
         * @param {number} heal Potion healing applied this turn.
         * @param {number} multiplier Star multiplier gained this turn.
         * @param {number} shield Shield meter gained this turn.
         * @param {number} poison Poison meter gained this turn.
         * @param {number} mana Mana gained this turn.
         */
        logTurnEffects(damage, coins, heal, multiplier, shield, poison, mana) {
            const s = this.strings;
            const side = this.currentTurn;
            const ui = this.scene.ui;

            if (damage > 0) {
                ui.pushHistoryLog(side, s.historylogattack.replace('{$a}', Math.round(damage)));
            }
            if (coins > 0) {
                ui.pushHistoryLog(side, s.historylogcoins.replace('{$a}', Math.round(coins)));
            }
            if (heal > 0) {
                ui.pushHistoryLog(side, s.historylogheal.replace('{$a}', Math.round(heal)));
            }
            if (multiplier > 0) {
                ui.pushHistoryLog(side, s.historylogmultiplier.replace('{$a}', multiplier.toFixed(1)));
            }
            if (shield > 0) {
                ui.pushHistoryLog(side, s.historylogshieldcharge.replace('{$a}', shield));
            }
            if (poison > 0) {
                ui.pushHistoryLog(side, s.historylogpoisoncharge.replace('{$a}', poison));
            }
            if (mana > 0) {
                ui.pushHistoryLog(side, s.historylogmana.replace('{$a}', mana));
            }
        }

        updateUI() {
            // Every caller of updateUI() just changed some piece of combat state — marking
            // the checkpoint dirty here, once, covers all of them instead of touching each
            // call site individually.
            this._checkpointDirty = true;
            this.scene.ui.updatePlayerBar(
                this.currentPlayerHp, this.maxPlayerHp,
                this.playerPoisonMeter, this.playerPoisonRounds,
                this.playerShieldMeter, this.playerShieldReady,
                this.playerMana, this.availableCoinBalance(), this.playerMultiplier
            );
            this.scene.ui.updateBossBar(
                this.currentHp, this.maxBossHp,
                this.bossPoisonMeter, this.bossPoisonRounds,
                this.bossShieldMeter, this.bossShieldReady,
                this.bossMana, this.bossGold, this.bossMultiplier
            );
            this.scene.ui.updateQuestionsCounter(this.questionsTotal, this.minQuestions);
            this.scene.ui.updateConsumableBadges();
        }

        /**
         * Coins actually spendable right now: the player's own gross earnings, minus
         * whatever has already been spent this phase/match — mirrors
         * coin_ledger::spendable() server-side (the actual authority; this only drives the
         * shop badges' enabled/disabled look, buy_consumable.php re-validates for real).
         * Deliberately does not net the boss's own coin gains against this, unlike
         * showEndScreen()'s final netGold — the boss racking up its own coins by matching
         * Coin pieces on its own turns was silently blocking the player from spending coins
         * they had genuinely and separately earned, found via a real playtest report
         * (14/09/2026). Netting against the boss's share is a final-reward concept, not a
         * mid-match spending-power one — see coin_ledger::spendable()'s own docblock.
         *
         * @returns {number}
         */
        availableCoinBalance() {
            return Math.max(0, Math.round(this.playerGold) - this.coinsSpent);
        }

        /**
         * Fixed shop price for a consumable type, mirroring combat::consumable_price().
         *
         * @param {string} type Consumable type.
         * @returns {number}
         */
        consumablePrice(type) {
            return CONSUMABLE_PRICES[type] || 0;
        }

        applyDamageToBoss(amount) {
            const me = this.scene;
            if (this.bossShieldReady) {
                this.bossShieldReady = false;
                amount = 0;
                me.ui.pushHistoryLog('boss', this.strings.historylogshieldblock);
            }

            this.currentHp = Math.max(0, this.currentHp - amount);
            this.updateUI();
            Accessibility.announce(
                this.strings.damagedealt
                    .replace('{$a->damage}', Math.round(amount))
                    .replace('{$a->hp}', Math.round(this.currentHp))
            );
            me.ui.bossSprite.setTint(0xff0000);
            me.time.delayedCall(200, () => {
                me.ui.bossSprite.clearTint();
            });
        }

        applyDamageToPlayer(amount) {
            const me = this.scene;
            if (this.playerShieldReady) {
                this.playerShieldReady = false;
                amount = 0;
                me.ui.pushHistoryLog('player', this.strings.historylogshieldblock);
            }

            this.currentPlayerHp = Math.max(0, this.currentPlayerHp - amount);
            this.updateUI();
            Accessibility.announce(
                this.strings.damagetaken
                    .replace('{$a->damage}', Math.round(amount))
                    .replace('{$a->hp}', Math.round(this.currentPlayerHp))
            );
            // Mirrors applyDamageToBoss()'s own tint flash (28/08/2026) — replaces a screen
            // shake the player found disruptive, now that a player sprite actually exists on
            // every layout (mobile included) to carry the same feedback the boss already had.
            me.ui.playerSprite.setTint(0xff0000);
            me.time.delayedCall(200, () => {
                me.ui.playerSprite.clearTint();
            });
        }

        passTurnToBoss() {
            const me = this.scene;
            this.currentTurn = 'boss';
            me.input.enabled = false;

            if (this.bossPoisonRounds > 0) {
                this.currentHp = Math.max(0, this.currentHp - this.baseDamage);
                this.bossPoisonRounds--;
                this.updateUI();
                me.ui.bossSprite.setTint(0xff00ff);
                me.ui.pushHistoryLog('boss', this.strings.historylogpoisontick.replace('{$a}', this.baseDamage));
                me.time.delayedCall(300, () => {
                    me.ui.bossSprite.clearTint();
                });
                if (this.checkGameOver()) {
                    return;
                }
            }

            me.time.delayedCall(800, this.executeBossTurn, [], this);
        }

        executeBossTurn() {
            const me = this.scene;
            const move = me.board.findMove(3) || me.board.findMove();

            if (move) {
                me.tweens.add({
                    targets: me.ui.bossSprite,
                    scaleX: 1.2, scaleY: 1.2, yoyo: true, duration: 300,
                    onComplete: () => {
                        me.board.swapPieces(move.p1, move.p2);
                    }
                });
            } else {
                me.board.shuffle();
            }
        }

        /**
         * Passes the turn to the player, applying a pending poison tick (mirrors
         * passTurnToBoss()'s own tick) if the boss has armed one.
         *
         * @returns {boolean} True when the tick itself ended the game, so the caller
         * (board.js) knows to skip re-enabling input for a turn that no longer happens.
         */
        passTurnToPlayer() {
            const me = this.scene;
            this.currentTurn = 'player';

            if (this.playerPoisonRounds > 0) {
                this.currentPlayerHp = Math.max(0, this.currentPlayerHp - this.baseDamage);
                this.playerPoisonRounds--;
                this.updateUI();
                // Mirrors passTurnToBoss()'s own poison-tick tint (28/08/2026), replacing a
                // screen shake — see applyDamageToPlayer()'s own comment for why.
                me.ui.playerSprite.setTint(0xff00ff);
                me.ui.pushHistoryLog('player', this.strings.historylogpoisontick.replace('{$a}', this.baseDamage));
                me.time.delayedCall(300, () => {
                    me.ui.playerSprite.clearTint();
                });
                if (this.checkGameOver()) {
                    return true;
                }
            }

            return false;
        }

        checkGameOver() {
            if (this.currentHp <= 0) {
                if (this.needsRevive()) {
                    this.reviveBoss();
                    return false;
                }
                if (this.hasNextPhase()) {
                    this.showPhaseCompleteScreen();
                } else {
                    this.showEndScreen(true);
                }
                return true;
            }
            if (this.currentPlayerHp <= 0) {
                this.showEndScreen(false);
                return true;
            }
            return false;
        }

        /**
         * Whether the boss reaching 0 HP right now should revive it instead of ending the
         * match — true when a minimum question count is configured and the attempt hasn't
         * answered enough yet. questionsTotal never resets on revive, so once the minimum is
         * met (in this phase or an earlier one, for a Campaign attempt spanning several), the
         * boss simply dies for good like before this rule existed.
         *
         * @returns {boolean} True when the boss should revive instead of the match ending.
         */
        needsRevive() {
            return this.minQuestions > 0 && this.questionsTotal < this.minQuestions;
        }

        /**
         * Brings the boss back at 50% of this match's own max HP and announces it, leaving
         * combat running exactly as if the boss had never reached 0 — may fire more than once
         * per match, since questionsTotal keeps advancing with every answer regardless.
         */
        reviveBoss() {
            this.currentHp = Math.ceil(this.maxBossHp * 0.5);
            this.updateUI();
            this.scene.ui.pushHistoryLog('boss', this.strings.historylogrevive);
            Accessibility.announce(this.strings.bossrevived);
        }

        /**
         * Buys a consumable, clicked from its shop badge (ui.js::createPurchaseBadge()).
         * Tries PlayerHUD stock first when one is configured for this type, falling back to
         * local coins if the student turns out to have none. Shield alone gets a client-side
         * "already armed" guard — there is nothing to gain from buying a second charge before
         * the first is spent, so it is worth skipping the round trip entirely for it; Magia
         * Rápida has no such guard, matching its board-piece twin (the Grimoire never blocks
         * overfilling either).
         *
         * @param {string} type One of 'potion', 'shield', 'magic', 'sword'.
         */
        buyConsumable(type) {
            const badge = this.scene.ui.purchaseBadges && this.scene.ui.purchaseBadges[type];
            if (badge && badge.disabled) {
                return;
            }
            if (type === 'shield' && (this.playerShieldReady || this.playerShieldMeter >= 100)) {
                return;
            }

            const hudFirst = !!(this.gameConfig.hudconfigured && this.gameConfig.hudconfigured[type]);
            this.requestPurchase(type, hudFirst ? 'hud' : 'local');
        }

        /**
         * Calls mod_playerpuzzle_buy_consumable with the given source. A source=hud attempt
         * that reports insufficient stock retries once with source=local automatically —
         * from the student's perspective this is still a single click, not two failures.
         *
         * @param {string} type Consumable type.
         * @param {string} source 'local' or 'hud'.
         */
        requestPurchase(type, source) {
            const me = this.scene;

            Ajax.call([{
                methodname: 'mod_playerpuzzle_buy_consumable',
                args: {
                    cmid: this.gameConfig.cmid,
                    token: this.gameConfig.token,
                    type,
                    source,
                    coinsearnedsofar: Math.round(this.playerGold),
                    bosscoinsearnedsofar: Math.round(this.bossGold),
                },
            }])[0].done(res => {
                if (!res.success) {
                    return;
                }
                if (source === 'local') {
                    const price = this.consumablePrice(type);
                    this.coinsSpent += price;
                    me.ui.showCoinFloat(price);
                }
                this.consumableUses[type] = (this.consumableUses[type] || 0) + 1;
                this.applyConsumableEffect(type);
                this.updateUI();
            }).fail(error => {
                if (source === 'hud' && error && error.errorcode === 'insufficienthudstock') {
                    this.requestPurchase(type, 'local');
                    return;
                }
                Notification.alert(this.strings.shoperror, (error && error.message) || this.strings.shoperror);
            });
        }

        /**
         * Buys the Question Hint consumable for the question currently open in the modal and
         * reveals its text once the server authorizes the purchase. Kept separate from
         * requestPurchase() rather than folded into it: Dica has no PlayerHUD funding source
         * (always 'local', same as Magia Rápida), needs the extra questionid argument, and its
         * "effect" is revealing text in the modal rather than a combat-state change, so nothing
         * about its success path fits applyConsumableEffect()'s switch.
         *
         * @param {number} questionid The question currently open in the modal.
         */
        requestHint(questionid) {
            const me = this.scene;

            Ajax.call([{
                methodname: 'mod_playerpuzzle_buy_consumable',
                args: {
                    cmid: this.gameConfig.cmid,
                    token: this.gameConfig.token,
                    type: 'hint',
                    source: 'local',
                    questionid,
                    coinsearnedsofar: Math.round(this.playerGold),
                    bosscoinsearnedsofar: Math.round(this.bossGold),
                },
            }])[0].done(res => {
                if (!res.success) {
                    return;
                }
                const price = this.consumablePrice('hint');
                this.coinsSpent += price;
                me.ui.showCoinFloat(price);
                this.consumableUses.hint = (this.consumableUses.hint || 0) + 1;
                $('#playerpuzzle-hint-text').text(res.hinttext).show();
                $('#playerpuzzle-btn-hint').hide().prop('disabled', true).off('click');
                this.updateUI();
            }).fail(error => {
                Notification.alert(this.strings.shoperror, (error && error.message) || this.strings.shoperror);
            });
        }

        /**
         * Applies a purchased consumable's in-combat effect. Only ever called after the
         * server has authorized the purchase (buy_consumable's {success: true}) — the
         * effect itself is entirely client-side, same as every other board-piece effect.
         *
         * @param {string} type Consumable type.
         */
        applyConsumableEffect(type) {
            const me = this.scene;

            if (type === 'potion') {
                const heal = this.baseDamage * 1.25;
                this.currentPlayerHp = Math.min(this.maxPlayerHp, this.currentPlayerHp + heal);
                me.ui.pushHistoryLog('player', this.strings.historylogheal.replace('{$a}', Math.round(heal)));
            } else if (type === 'shield') {
                // Reuses resolveShieldMeters()'s own overflow-preserving logic instead of
                // setting shieldReady directly, so a purchase behaves identically to filling
                // the ring by matching Shield pieces.
                this.playerShieldMeter += 100;
                this.resolveShieldMeters();
                me.ui.pushHistoryLog('player', this.strings.historylogshieldcharge.replace('{$a}', 100));
            } else if (type === 'magic') {
                this.playerPoisonMeter += 100;
                this.resolvePoisonMeters();
                me.ui.pushHistoryLog('player', this.strings.historylogpoisoncharge.replace('{$a}', 100));
            } else if (type === 'sword') {
                this.applyDamageToBoss(this.baseDamage);
                me.ui.pushHistoryLog('player', this.strings.historylogattack.replace('{$a}', Math.round(this.baseDamage)));
            }

            this.checkGameOver();
        }

        /**
         * Whether this victory is a mid-Campaign phase win (more phases/levels remain)
         * rather than the end of the whole attempt — mirrors the boundary check
         * advance_phase.php itself enforces server-side.
         *
         * @returns {boolean} True when a next phase or level exists to advance to.
         */
        hasNextPhase() {
            const cfg = this.gameConfig;
            if (cfg.gamemode !== 'campaign') {
                return false;
            }
            const phase = parseInt(cfg.currentphase, 10) || 1;
            const level = parseInt(cfg.currentlevel, 10) || 1;
            const maxlevels = parseInt(cfg.maxlevels, 10) || 1;
            return phase < 10 || level < maxlevels;
        }

        /**
         * Submits a real POST to play.php, mirroring the Lobby's own "Jogar" form. A Phaser
         * scene.restart() would only reset client-side state and keep reusing a token the
         * server has already rotated or consumed, so the next attempt's own save/advance
         * call would always fail with an invalid-token error.
         *
         * Carries the current difficulty: on a post-defeat replay (no in-progress attempt)
         * the new attempt is created with it, so the student stays on the difficulty they
         * were playing; on a phase advance the attempt already exists and play.php ignores
         * this in favour of the value advance_phase stored.
         */
        submitRestartForm() {
            const restartForm = document.createElement('form');
            restartForm.method = 'POST';
            let restarturl = `${M.cfg.wwwroot}/mod/playerpuzzle/play.php?id=${this.gameConfig.cmid}`;
            if (this.gameConfig.mobile) {
                restarturl += '&mobile=1';
            }
            restartForm.action = restarturl;
            const fields = {
                sesskey: M.cfg.sesskey,
                difficulty: this.gameConfig.difficulty || 'normal',
            };
            Object.entries(fields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                restartForm.appendChild(input);
            });
            document.body.appendChild(restartForm);
            restartForm.submit();
        }

        /**
         * The three difficulty options for the phase-complete screen, pre-selecting the one
         * the current phase was played on. Campaign lets the student re-pick at every phase
         * transition (the choice is passed to advance_phase and stored on the attempt).
         *
         * @returns {Array<{value: string, label: string, checked: boolean}>}
         */
        difficultyChoices() {
            const current = this.gameConfig.difficulty || 'normal';
            return ['easy', 'normal', 'hard'].map(value => ({
                value,
                label: this.strings['difficulty_' + value],
                checked: value === current,
            }));
        }

        /**
         * Shown instead of showEndScreen() when a Campaign attempt wins a phase that is not
         * the last phase of the last level. The student picks the next phase's difficulty
         * and confirms; advance_phase then banks this phase's coins, stores the new
         * difficulty and phase on the attempt, and the "Continue" flow reloads play.php,
         * where the resumed 'inprogress' row picks everything up.
         */
        async showPhaseCompleteScreen() {
            const me = this.scene;
            const strings = this.strings;
            me.input.enabled = false;
            me.add.graphics().fillStyle(0x000000, 0.85).fillRect(0, 0, me.ui.L.w, me.ui.L.h).setDepth(99);

            const netGold = Math.round(Math.max(0, this.playerGold - this.bossGold));

            const context = {
                msg: strings.phasecompletetitle,
                coinscollected: strings.coinscollected,
                playergold: netGold,
                btncontinue: strings.btncontinue,
                btnexitgame: strings.btnexitgame,
                difficultylabel: strings.phasedifficulty,
                difficultychoices: this.difficultyChoices(),
            };

            const html = await Templates.render('mod_playerpuzzle/phase_complete_overlay', context);
            $('#playerpuzzle-canvas-container').append(html);
            // Calling showModal() (not just setting the `open` attribute) traps focus inside
            // the dialog and promotes it to the browser's top layer — without it, this was a
            // plain absolutely-positioned <div> with no focus containment at all, letting
            // focus drift onto page furniture outside the canvas (e.g. the course index
            // drawer toggle) while choosing the next phase's difficulty (reported live,
            // 30/08/2026).
            document.getElementById('playerpuzzle-phasecomplete').showModal();

            // Both buttons must go through advance_phase before doing anything else: the win
            // is only durable once this call lands (there is no separate "record the win" step
            // like showEndScreen's save_progress). Exiting without it left the attempt parked
            // on the just-defeated phase with a stale, already-0 boss HP checkpoint instead of
            // advancing (reported live, 16/09/2026) — mirror that guard on both buttons instead
            // of only on "Continue".
            const performAdvance = (onSuccess) => {
                $('#pp-phase-status').removeClass('text-success text-danger').addClass('text-muted')
                    .text(strings.advancingphase);
                $('#btn-pp-continue-phase, #btn-pp-exit-phase, #pp-phase-difficulty').prop('disabled', true);

                Ajax.call([{
                    methodname: 'mod_playerpuzzle_advance_phase',
                    args: {
                        cmid: this.gameConfig.cmid,
                        token: this.gameConfig.token,
                        damage: this.maxBossHp - this.currentHp,
                        coinsearnedsofar: Math.round(this.playerGold),
                        bosscoinsearnedsofar: Math.round(this.bossGold),
                        difficulty: $('#pp-phase-difficulty').val() || 'normal',
                    },
                }])[0].done(onSuccess).fail(() => {
                    $('#pp-phase-status').removeClass('text-muted').addClass('text-danger')
                        .text(strings.phaseadvanceerror);
                    $('#btn-pp-continue-phase, #btn-pp-exit-phase, #pp-phase-difficulty').prop('disabled', false);
                });
            };

            $('#btn-pp-continue-phase').on('click', () => performAdvance(res => {
                // Announce the transition for screen-reader users, then give it a beat to
                // be read before the full page reload wipes the live region.
                const total = parseInt(this.gameConfig.maxlevels, 10) * 10 || 10;
                Accessibility.announce(
                    strings.phaseadvanced
                        .replace('{$a->level}', res.currentlevel)
                        .replace('{$a->phase}', res.currentphase)
                        .replace('{$a->total}', total)
                );
                me.time.delayedCall(1600, () => this.submitRestartForm());
            }));
            $('#btn-pp-exit-phase').on('click', () => performAdvance(() => {
                window.location.href = this.gameConfig.viewurl;
            }));
        }

        openQuestionModal(trigger) {
            const me = this.scene;
            const ctx = this;
            me.input.enabled = false;

            if (trigger === 'player') {
                Accessibility.announce(this.strings.manafull);
            }

            setTimeout(() => {
                me.scene.pause();
                const dialogEl = document.getElementById('playerpuzzle-modal');

                if (dialogEl) {
                    // Native <dialog> restores no focus of its own on close() — the
                    // element that had focus when the dialog opened (typically nothing,
                    // since Phaser's canvas is not itself focusable) is saved here and
                    // restored explicitly to it when the dialog closes.
                    const previouslyFocused = document.activeElement;

                    let question = {text: ctx.strings.questionerror, options: []};
                    if (ctx.gameConfig.questions && ctx.gameConfig.questions.length > 0) {
                        const idx = Math.floor(Math.random() * ctx.gameConfig.questions.length);
                        question = ctx.gameConfig.questions[idx];
                    }

                    let questionText = trigger === 'boss'
                        ? `<strong class="text-danger pp-bold">${ctx.strings.bosstrigger}</strong><br><br>${question.text}`
                        : question.text;

                    // First-attempt tutorial (Fase 9): shown once ever this session, only for
                    // the player's own question challenge — the boss's is auto-resolved with
                    // no player interaction, so the instruction would have nothing to explain.
                    if (trigger === 'player' && ctx.istutorial && !ctx.tutorialQuestionInstructionShown) {
                        ctx.tutorialQuestionInstructionShown = true;
                        questionText += `<br><br><em>${ctx.strings.tutorialquestioninstruction}</em>`;
                    }

                    $('#playerpuzzle-question-text').html(questionText);
                    const answersContainer = $('#playerpuzzle-answers-container');
                    answersContainer.empty();
                    $('#playerpuzzle-btn-confirm').hide().off('click');
                    $('#playerpuzzle-btn-skip').hide().off('click');
                    $('#playerpuzzle-hint-text').hide().empty();
                    $('#playerpuzzle-btn-hint').hide().prop('disabled', false).off('click');

                    const closeModal = () => {
                        dialogEl.close();
                        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                            previouslyFocused.focus();
                        }
                        me.scene.resume();
                        me.time.delayedCall(250, me.board.applyGravity, [], me.board);
                    };

                    if (question.options && question.options.length > 0) {
                        const btnClass = ctx.gameConfig.mobile
                            ? 'btn btn-outline-primary w-100 pp-answer-btn'
                            : 'btn btn-outline-primary btn-lg mb-3 w-100';

                        if (trigger === 'player') {
                            $('#playerpuzzle-btn-skip').show().on('click', closeModal);
                            $('#playerpuzzle-btn-confirm').text(ctx.strings.btnattack)
                                .prop('disabled', true).show();

                            if (question.hashint) {
                                const hintPrice = ctx.consumablePrice('hint');
                                $('#playerpuzzle-btn-hint')
                                    .text(ctx.strings.hintbutton.replace('{$a}', hintPrice))
                                    .show()
                                    .on('click', () => ctx.requestHint(question.id));
                            }

                            let selectedAnswer = null;

                            question.options.forEach(option => {
                                const btn = $(`<button class="${btnClass}" data-answerid="${option.id}">${option.text}</button>`);

                                // Using function() to preserve jQuery's this binding for the clicked button.
                                btn.on('click', function() {
                                    answersContainer.find('button')
                                        .removeClass('btn-warning')
                                        .addClass('btn-outline-primary');
                                    $(this).removeClass('btn-outline-primary').addClass('btn-warning');
                                    selectedAnswer = option;
                                    $('#playerpuzzle-btn-confirm').prop('disabled', false);
                                });

                                answersContainer.append(btn);
                            });

                            $('#playerpuzzle-btn-confirm').off('click').on('click', () => {
                                if (!selectedAnswer) {
                                    return;
                                }
                                answersContainer.find('button').prop('disabled', true);
                                $('#playerpuzzle-btn-skip').hide();
                                $('#playerpuzzle-btn-confirm').prop('disabled', true);

                                const applyResult = (isCorrect, correctanswerid) => {
                                    let feedbackMsg;
                                    if (isCorrect) {
                                        answersContainer.find('.btn-warning')
                                            .removeClass('btn-warning').addClass('btn-success text-white');
                                        const critDamage = ctx.baseDamage * 3 * ctx.playerMultiplier;
                                        ctx.applyDamageToBoss(critDamage);
                                        me.ui.pushHistoryLog(
                                            'player', ctx.strings.historylogcritical.replace('{$a}', Math.round(critDamage))
                                        );
                                        me.ui.bossSprite.setTint(0x0088ff);
                                        me.tweens.add({
                                            targets: me.ui.bossSprite, y: me.ui.bossSprite.y - 20,
                                            yoyo: true, duration: 150,
                                            onComplete: () => {
                                                me.ui.bossSprite.clearTint();
                                            }
                                        });
                                        feedbackMsg = '<div class="alert alert-success mt-2 mb-0">'
                                            + `<strong>${ctx.strings.playercorrect}</strong></div>`;
                                    } else {
                                        answersContainer.find('.btn-warning')
                                            .removeClass('btn-warning').addClass('btn-danger text-white');
                                        if (correctanswerid) {
                                            answersContainer
                                                .find(`[data-answerid="${correctanswerid}"]`)
                                                .removeClass('btn-outline-primary')
                                                .addClass('btn-success text-white');
                                        }
                                        ctx.applyDamageToPlayer(30);
                                        me.ui.pushHistoryLog('player', ctx.strings.historylogwronganswer.replace('{$a}', 30));
                                        const lostMultiplier = ctx.playerMultiplier;
                                        ctx.playerMultiplier = 1;
                                        ctx.updateUI();
                                        const wrongMsg = ctx.strings.playerwrong.replace('{$a}', 30);
                                        feedbackMsg = '<div class="alert alert-danger mt-2 mb-0">'
                                            + `<strong>${wrongMsg}</strong></div>`;
                                        if (lostMultiplier > 1) {
                                            const lostMsg = ctx.strings.playerlostmultiplier
                                                .replace('{$a}', lostMultiplier.toFixed(1));
                                            feedbackMsg += '<div class="alert alert-warning mt-2 mb-0">'
                                                + `<strong>${lostMsg}</strong></div>`;
                                            me.ui.pushHistoryLog('player', ctx.strings.historylogmultiplierlost);
                                        }
                                    }
                                    answersContainer.append(feedbackMsg);
                                    $('#playerpuzzle-btn-confirm').text(ctx.strings.btncontinue)
                                        .prop('disabled', false).off('click').on('click', closeModal);
                                };

                                Ajax.call([{
                                    methodname: 'mod_playerpuzzle_validate_answer',
                                    args: {
                                        cmid: ctx.gameConfig.cmid,
                                        token: ctx.gameConfig.token,
                                        questionid: question.id,
                                        answerid: selectedAnswer.id,
                                        forwhom: 'player',
                                    },
                                }])[0].done(res => {
                                    if (typeof res.questionstotal === 'number' && ctx.minQuestions > 0) {
                                        ctx.questionsTotal = res.questionstotal;
                                        ctx.updateUI();
                                        if (ctx.questionsTotal < ctx.minQuestions) {
                                            Accessibility.announce(
                                                ctx.strings.questionsprogress
                                                    .replace('{$a->current}', ctx.questionsTotal)
                                                    .replace('{$a->total}', ctx.minQuestions)
                                            );
                                        }
                                    }
                                    applyResult(!!res.correct, res.correctanswerid || null);
                                }).fail(() => {
                                    applyResult(false, null);
                                });
                            });

                        } else {
                            // The boss's answer is drawn server-side (validate_answer with
                            // forwhom:'boss') so the client never learns the correct one —
                            // its precision is the difficulty-weighted probability there.
                            const renderBossResult = (isBossCorrect, pickedId) => {
                                question.options.forEach(option => {
                                    const plainText = option.text.replace(/(<([^>]+)>)/gi, '');
                                    const btn = $(`<button class="${btnClass}" disabled>${option.text}</button>`);

                                    // Coerce both sides: the option id arrives as a string
                                    // from the JSON config, pickedId as a number from the WS.
                                    if (Number(option.id) === Number(pickedId)) {
                                        if (isBossCorrect) {
                                            btn.removeClass('btn-outline-primary').addClass('btn-danger text-white');
                                            btn.html(
                                                `<strong>${ctx.strings.bossansweredcorrect.replace('{$a}', plainText)}</strong>`
                                            );
                                        } else {
                                            btn.removeClass('btn-outline-primary').addClass('btn-secondary text-white');
                                            btn.html(
                                                `<strong>${ctx.strings.bossansweredwrong.replace('{$a}', plainText)}</strong>`
                                            );
                                        }
                                    } else {
                                        btn.removeClass('btn-outline-primary').addClass('btn-light');
                                    }
                                    answersContainer.append(btn);
                                });

                                let bossFeedback;
                                if (isBossCorrect) {
                                    const bossCritDamage = ctx.baseDamage * 3 * ctx.bossMultiplier;
                                    ctx.applyDamageToPlayer(bossCritDamage);
                                    me.ui.pushHistoryLog(
                                        'boss', ctx.strings.historylogcritical.replace('{$a}', Math.round(bossCritDamage))
                                    );
                                    const dmgMsg = ctx.strings.bosscorrectfeedback
                                        .replace('{$a}', Math.round(bossCritDamage));
                                    bossFeedback = `<div class="alert alert-danger mt-2 mb-0"><strong>${dmgMsg}</strong></div>`;
                                } else {
                                    const wfMsg = ctx.strings.bosswrongfeedback;
                                    bossFeedback = `<div class="alert alert-success mt-2 mb-0"><strong>${wfMsg}</strong></div>`;
                                    const lostBossMultiplier = ctx.bossMultiplier;
                                    ctx.bossMultiplier = 1;
                                    ctx.updateUI();
                                    if (lostBossMultiplier > 1) {
                                        const lostMsg = ctx.strings.bosslostmultiplier
                                            .replace('{$a}', lostBossMultiplier.toFixed(1));
                                        bossFeedback += '<div class="alert alert-warning mt-2 mb-0">'
                                            + `<strong>${lostMsg}</strong></div>`;
                                        me.ui.pushHistoryLog('boss', ctx.strings.historylogmultiplierlost);
                                    }
                                }
                                answersContainer.append(bossFeedback);
                                $('#playerpuzzle-btn-confirm').text(ctx.strings.btncontinue).show()
                                    .off('click').on('click', closeModal);
                            };

                            Ajax.call([{
                                methodname: 'mod_playerpuzzle_validate_answer',
                                args: {
                                    cmid: ctx.gameConfig.cmid,
                                    token: ctx.gameConfig.token,
                                    questionid: question.id,
                                    answerid: 0,
                                    forwhom: 'boss',
                                },
                            }])[0].done(res => {
                                renderBossResult(!!res.correct, res.pickedanswerid || null);
                            }).fail(() => {
                                renderBossResult(false, null);
                            });
                        }

                    } else {
                        answersContainer.append(
                            `<p class="text-danger">${ctx.strings.noanswers}</p>`
                        );
                        $('#playerpuzzle-btn-confirm').text(ctx.strings.btncontinue).show()
                            .off('click').on('click', closeModal);
                    }

                    // Content (question text, answer buttons) is already in the DOM at
                    // this point, so the browser's own auto-focus on showModal() lands on
                    // a real answer button — no extra JS-driven focus call needed.
                    dialogEl.showModal();
                } else {
                    me.scene.resume();
                    me.time.delayedCall(250, me.board.applyGravity, [], me.board);
                }
            }, 250);
        }

        async showEndScreen(victory) {
            const me = this.scene;
            const strings = this.strings;
            const viewurl = this.gameConfig.viewurl;
            me.input.enabled = false;
            me.add.graphics().fillStyle(0x000000, 0.85).fillRect(0, 0, me.ui.L.w, me.ui.L.h).setDepth(99);

            // The boss's own Coin total (bossGold) never buys it anything — it exists purely to
            // net against the student's balance here, the only place it is spent.
            const netGold = Math.round(Math.max(0, this.playerGold - this.bossGold));
            // A defeat/timeout discards the session's coins server-side — showing the collected
            // total here first, only to contradict it with "0" once the save confirms, reads as
            // a bug. Showing the true outcome (0) up front avoids that.
            const displayGold = victory ? netGold : 0;

            const context = {
                colorclass: victory ? 'text-success' : 'text-danger',
                msg: victory ? strings.victory : strings.defeat,
                coinscollected: strings.coinscollected,
                playergold: displayGold,
                maxmultiplier: strings.maxmultiplier,
                playermultiplier: this.playerMultiplier.toFixed(1),
                savingprogress: strings.savingprogress,
                btnreview: strings.debriefreview,
                btnplayagain: strings.btnplayagain,
                btnexitgame: strings.btnexitgame,
            };

            const html = await Templates.render('mod_playerpuzzle/gameover_overlay', context);
            $('#playerpuzzle-canvas-container').append(html);
            Accessibility.announce(
                `${victory ? strings.victory : strings.defeat} ${strings.coinscollected} ${displayGold}.`
            );

            Ajax.call([{
                methodname: 'mod_playerpuzzle_save_progress',
                args: {
                    cmid: this.gameConfig.cmid,
                    token: this.gameConfig.token,
                    victory: victory ? 1 : 0,
                    damage: this.maxBossHp - this.currentHp,
                    coinsearnedsofar: Math.round(this.playerGold),
                    bosscoinsearnedsofar: Math.round(this.bossGold),
                },
            }])[0].done(res => {
                const successMsg = strings.progresssaved.replace('{$a}', res.coinsbanked);
                $('#pp-save-status').removeClass('text-muted').addClass('text-success')
                    .text(successMsg);
                $('#btn-pp-restart, #btn-pp-exit').prop('disabled', false);
                if (res.questionlog && res.questionlog.length > 0) {
                    $('#btn-pp-review').prop('hidden', false)
                        .off('click').on('click', () => this.showDebrief(res.questionlog));
                }
            }).fail(() => {
                $('#pp-save-status').removeClass('text-muted').addClass('text-danger')
                    .text(strings.saveerror);
                $('#btn-pp-restart, #btn-pp-exit').prop('disabled', false);
            });

            $('#btn-pp-restart').on('click', () => {
                this.submitRestartForm();
            });
            $('#btn-pp-exit').on('click', () => {
                window.location.href = viewurl;
            });
        }

        /**
         * Renders the post-game question review into a native dialog and opens it. The
         * dialog's own "Close" button (method="dialog") and the Escape key dismiss it;
         * focus returns to the "Review questions" button afterwards.
         *
         * @param {Array<{questiontext: string, chosenanswer: string, correctanswer: string,
         *  iscorrect: boolean}>} questionlog The phase's answered questions from save_progress.
         */
        async showDebrief(questionlog) {
            const strings = this.strings;
            const correct = questionlog.filter(q => q.iscorrect).length;

            const context = {
                title: strings.debrieftitle,
                summary: strings.debriefsummary
                    .replace('{$a->correct}', correct)
                    .replace('{$a->total}', questionlog.length),
                youranswerlabel: strings.debriefyouranswer,
                correctanswerlabel: strings.debriefcorrectanswer,
                correctsr: strings.debriefcorrectsr,
                wrongsr: strings.debriefwrongsr,
                closelabel: strings.btncontinue,
                emptytext: strings.debriefempty,
                questions: questionlog,
            };

            const html = await Templates.render('mod_playerpuzzle/debrief', context);
            const existing = document.getElementById('pp-debrief-dialog');
            if (existing) {
                existing.remove();
            }
            $('#playerpuzzle-canvas-container').append(html);

            const dialogEl = document.getElementById('pp-debrief-dialog');
            const trigger = document.getElementById('btn-pp-review');
            dialogEl.addEventListener('close', () => {
                if (trigger && typeof trigger.focus === 'function') {
                    trigger.focus();
                }
            }, {once: true});
            dialogEl.showModal();
        }
    }

    return CombatHandler;
});
