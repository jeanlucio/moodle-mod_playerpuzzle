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
        'mod_playerpuzzle/accessibility', 'mod_playerpuzzle/engine/combat_rules', 'mod_playerpuzzle/engine/prng'],
        function($, Ajax, Notification, Templates, Config, Accessibility, CombatRules, Prng) {
    'use strict';

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

    // Mirrors move_log::MAX_EVENTS_PER_CHECKPOINT server-side.
    const MAX_EVENTS_PER_CHECKPOINT = 200;

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
            // ledger (coins_earned/boss_coins_earned), capped by a damage-based ceiling,
            // never trusting this value outright.
            this.coinFactor = parseFloat(gameConfig.coinfactor) || 1;
            // Minimum-questions rule: the server is the only source of truth for how many
            // questions this attempt has answered (questionsTotal starts at whatever a resumed
            // attempt already carries from earlier phases and only ever moves forward, mirroring
            // validate_answer.php's own count) — the client never counts on its own.
            this.minQuestions = parseInt(gameConfig.minquestions, 10) || 0;
            this.questionsTotal = parseInt(gameConfig.questionstotal, 10) || 0;
            // Consumables: uses carried forward from the current phase/match's own use-count
            // window (see attempt_consumables.php), same pattern as questionsTotal above.
            // Stock is bought pre-match in the Lobby, never during a match — this is a
            // read-only mirror of what the student owned when the page loaded, corrected by
            // the server's own response after every use_stock call.
            this.consumableUses = Object.assign(
                {potion: 0, shield: 0, magic: 0, sword: 0, hint: 0},
                gameConfig.consumableuses || {}
            );
            this.consumableStock = Object.assign(
                {potion: 0, shield: 0, magic: 0, sword: 0, hint: 0},
                gameConfig.consumablestock || {}
            );
            this.currentTurn = 'player';

            // Demo match: the server already decided isdemo once, at attempt
            // creation (security::generate_attempt_token(), from the Lobby's "Play Demo"
            // button) — this is a read-only mirror, never re-derived client-side.
            // tutorialSeenTypes tracks which piece types already got their one-time context
            // balloon this session (not persisted — a reload simply re-shows any type not yet
            // seen again, a harmless repeat, not a bug).
            this.isdemo = !!gameConfig.isdemo;
            this.tutorialSeenTypes = new Set();
            this.tutorialQuestionInstructionShown = false;

            // Carried forward from the ledger, not reset to 0 — a mid-phase page reload (or a
            // Campaign attempt resuming a phase already partway through) must not forget coins
            // already earned this window.
            this.playerGold = parseInt(gameConfig.coinsearnedsofar, 10) || 0;
            // Mirrors combat::coin_ceiling() — see clampedPlayerGold()'s own docblock for
            // why the displayed total must clamp to this, not grow unbounded from board
            // matches alone. Falls back to no ceiling at all (rather than 0, which would
            // zero out the display) if the value is ever missing/invalid.
            const parsedceiling = parseInt(gameConfig.coinceiling, 10);
            this.coinCeiling = Number.isFinite(parsedceiling) ? parsedceiling : Infinity;
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

            // A resumed phase overrides every HP/meter/turn default set above. The server's
            // rebuilt snapshot is preferred; the client's own last checkpoint is only the
            // fallback (a Demo, or a log the server could not replay). board.js builds the
            // board from restoredGrid either way; a fresh phase has neither, and every side
            // simply starts at full HP as already set up above.
            this.restoredGrid = null;
            this.pendingQuestion = null;
            this.resumedEnded = false;
            if (gameConfig.snapshot) {
                this.hydrateFromSnapshot(gameConfig.snapshot);
            } else {
                this.hydrateFromCheckpoint(gameConfig.combatstate);
            }

            // Seeded PRNG driving board generation/gravity/shuffle (board.js reads this via
            // this.rng, replacing what used to be plain Math.random calls) — deterministic
            // for a given seed, so a server-side replay of the recorded event log can
            // reproduce the same board states. confirmedEvents is how many of this phase's
            // events the server has stored (the offset the next batch starts at);
            // pendingMoveLog holds the ones recorded since, until the server confirms them.
            // A rebuilt snapshot hands over the generator's exact position in the phase's
            // sequence, so the pieces falling from here on are the ones the server replays.
            this.rng = gameConfig.snapshot
                ? Prng.create(gameConfig.snapshot.rngstate)
                : Prng.create(parseInt(gameConfig.rngseed, 10) || 0);
            this.confirmedEvents = parseInt(gameConfig.moveseq, 10) || 0;
            this.pendingMoveLog = [];

            this._checkpointDirty = false;
            this.matchSaved = false;
            this.startCheckpointing();
        }

        /**
         * Records a player-made board swap into the pending event log, for the next
         * checkpoint to send. Never called for the boss's own moves (executeBossTurn()'s
         * swap is a deterministic scan with no RNG involved, so the server can always
         * recompute it independently — see board.js::checkMatches()'s own call site for
         * where the player-only guard lives) or for a swap that was reverted for producing
         * no match (a no-op the board never actually changed because of).
         *
         * @param {number} r1 Row of the first swapped cell.
         * @param {number} c1 Column of the first swapped cell.
         * @param {number} r2 Row of the second swapped cell.
         * @param {number} c2 Column of the second swapped cell.
         */
        recordMove(r1, c1, r2, c2) {
            this.pendingMoveLog.push({type: 'move', r1, c1, r2, c2});
        }

        /**
         * Records how a mana-triggered question was closed into the pending event log,
         * alongside board swaps, so the server-side replay knows where in the turn sequence
         * it happened. Every question the game opens gets exactly one entry, whatever its
         * ending — a missing one leaves the replay unable to place anything after it.
         *
         * Whether an answer was right is never sent: the replay takes that from the outcome
         * validate_answer.php itself stored.
         *
         * @param {string} side 'player' or 'boss' — who was asked.
         * @param {string} outcome 'answered' (validate_answer replied), 'skipped' (the player
         *  closed it unanswered), 'unavailable' (nothing could be drawn) or 'failed' (the
         *  validation call never came back).
         */
        recordQuestionEvent(side, outcome) {
            this.pendingMoveLog.push({type: 'question', side, outcome});
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

            this.restoredGrid = state.boardgrid;
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
         * Restores a resumed phase from the snapshot the server rebuilt by replaying its
         * event log (replay::snapshot()): HP/meters/gold as the server computed them, whose
         * turn it is, a question still open (the board then has the cells its match emptied
         * still empty), or a match that already ended.
         *
         * @param {object} snapshot The gameConfig.snapshot object.
         */
        hydrateFromSnapshot(snapshot) {
            const {state} = snapshot;
            [
                'currentPlayerHp', 'currentHp', 'playerMultiplier', 'bossMultiplier',
                'playerShieldMeter', 'playerShieldReady', 'bossShieldMeter', 'bossShieldReady',
                'playerPoisonMeter', 'playerPoisonRounds', 'bossPoisonMeter', 'bossPoisonRounds',
                'playerMana', 'bossMana', 'playerGold', 'bossGold',
            ].forEach(key => {
                this[key] = state[key];
            });
            this.currentTurn = snapshot.turn;
            this.restoredGrid = snapshot.grid;
            this.pendingQuestion = snapshot.pendingquestion;
            this.resumedEnded = !!snapshot.terminal;
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
            if (!board || this.matchSaved) {
                return;
            }

            const boardgrid = [];
            for (let row = 0; row < board.rows; row++) {
                for (let col = 0; col < board.cols; col++) {
                    const piece = board.grid[row][col];
                    if (!piece) {
                        // Mid-cascade: a destroyed piece's cell is momentarily empty while
                        // gravity/matches settle (a ~250-750ms window) — not a safe point to
                        // checkpoint. _checkpointDirty is left true (never reset below), so
                        // the next periodic tick or the final unload beacon retries once the
                        // board has settled, instead of saving a board with a hole baked in.
                        return;
                    }
                    boardgrid.push(piece.type);
                }
            }

            // A batch is capped at the server's per-checkpoint limit; anything past it simply
            // waits for the next checkpoint.
            const sendingOffset = this.confirmedEvents;
            const sendingMoveLog = this.pendingMoveLog.slice(0, MAX_EVENTS_PER_CHECKPOINT);

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
                eventoffset: sendingOffset,
                movelog: sendingMoveLog,
            };

            this._checkpointDirty = false;

            if (useBeacon) {
                // Fire and forget: the buffer is kept, not cleared. A tab only backgrounded
                // (not closed) comes back and resends it from the same offset, and the server
                // stores each event exactly once whichever copy arrives first.
                sendCheckpointBeacon(args);
                this._checkpointDirty = true;
                return;
            }

            Ajax.call([{methodname: 'mod_playerpuzzle_save_combat_state', args}])[0].done(res => {
                this.confirmStoredEvents(res.eventcount);
            }).fail(() => {
                // A missed periodic checkpoint is not user-visible and not worth retrying —
                // the next tick (or the final beacon on exit) resends from the same offset.
                this._checkpointDirty = true;
            });
        }

        /**
         * Drops the events the server now reports as stored from the pending buffer. New
         * events recorded while the call was in flight stay pending.
         *
         * @param {number} eventcount Events the server has stored for this phase.
         */
        confirmStoredEvents(eventcount) {
            const stored = parseInt(eventcount, 10);
            if (!(stored > this.confirmedEvents)) {
                return;
            }
            this.pendingMoveLog = this.pendingMoveLog.slice(stored - this.confirmedEvents);
            this.confirmedEvents = stored;
        }

        /**
         * Resolves this turn's destroyed pieces/match groups via
         * mod_playerpuzzle/engine/combat_rules::resolveMatchEffects() — the pure calculation
         * of every HP/meter/mana/gold change this turn causes — then applies the result back
         * onto this.* and handles everything that function deliberately never touches: sound,
         * grid mutation, the destroy tween, the history log, and the tutorial balloon.
         *
         * @param {Array} destroyedPieces Pieces destroyed this turn (real Phaser objects —
         *  board.js still deals in those, only the calculation itself moved to pure logic).
         * @param {Array} matchGroups Match groups this turn, {type, pieces} (pieces: real
         *  Phaser objects; only their count is passed into the pure calculation).
         * @return {{damage: number, question: boolean, trigger: string|null}}
         */
        processEffects(destroyedPieces, matchGroups) {
            const me = this.scene;
            me.sfxMatch.play();

            const result = CombatRules.resolveMatchEffects({
                currentTurn: this.currentTurn,
                destroyedTypes: destroyedPieces.map(piece => piece.type),
                matchGroups: matchGroups.map(group => ({type: group.type, size: group.pieces.length})),
                state: {
                    currentPlayerHp: this.currentPlayerHp, maxPlayerHp: this.maxPlayerHp,
                    currentHp: this.currentHp, maxBossHp: this.maxBossHp,
                    playerMultiplier: this.playerMultiplier, bossMultiplier: this.bossMultiplier,
                    playerShieldMeter: this.playerShieldMeter, playerShieldReady: this.playerShieldReady,
                    bossShieldMeter: this.bossShieldMeter, bossShieldReady: this.bossShieldReady,
                    playerPoisonMeter: this.playerPoisonMeter, playerPoisonRounds: this.playerPoisonRounds,
                    bossPoisonMeter: this.bossPoisonMeter, bossPoisonRounds: this.bossPoisonRounds,
                    playerMana: this.playerMana, bossMana: this.bossMana,
                    playerGold: this.playerGold, bossGold: this.bossGold,
                },
                config: {baseDamage: this.baseDamage, coinGain: this.coinGain, coinFactor: this.coinFactor},
            });
            Object.assign(this, result.state);

            for (const piece of destroyedPieces) {
                if (this.currentTurn === 'player') {
                    me.sfxHit.play();
                }
                me.board.grid[piece.row][piece.col] = null;
                me.tweens.add({
                    targets: piece, scaleX: 0, scaleY: 0, duration: 200,
                    onComplete: (tween, targets) => {
                        targets[0].destroy();
                    }
                });
            }

            this.logTurnEffects(
                result.damageDealt, result.coinsGained, result.healGained,
                result.multiplierGained, result.shieldGained, result.poisonGained, result.manaGained
            );

            this.triggerTutorialBalloons(destroyedPieces, matchGroups);
            this.updateUI();
            return {damage: result.damageDealt, question: result.questionTriggered, trigger: result.triggeredBy};
        }

        /**
         * Fires the one-time tutorial context balloon for a newly-matched piece type this
         * turn, in a Demo match only, and only for the player's own turn (the boss's matches
         * are never something the student needs explained). Kept as its own method rather
         * than inlined into processEffects()'s own loops: that function is already at
         * ESLint's complexity ceiling, and every type this checks is already known once
         * destroyedPieces/matchGroups exist, so a second, simpler pass over the same data
         * costs nothing beyond the one extra call site.
         *
         * Only ever shows one balloon per call, even when several different types are
         * destroyed in the same turn: ui.js::showTutorialBalloon() replaces whatever balloon
         * is currently up, so showing more than one here would just have each new call erase
         * the previous before the student ever sees it. Only the type actually shown is
         * marked "seen" — every other new type this turn stays eligible and gets its own
         * balloon the next time it is destroyed on its own (or is the last new type in some
         * later turn).
         *
         * @param {Array} destroyedPieces Pieces destroyed this turn (star/grimoire/orb/
         *  shield/potion effects are resolved per piece).
         * @param {Array} matchGroups Match groups this turn (sword/coin effects are resolved
         *  per group, driven by combo size).
         */
        triggerTutorialBalloons(destroyedPieces, matchGroups) {
            if (!this.isdemo || this.currentTurn !== 'player') {
                return;
            }

            const newtypes = [];
            const addIfNew = type => {
                if (!this.tutorialSeenTypes.has(type) && !newtypes.includes(type)) {
                    newtypes.push(type);
                }
            };
            destroyedPieces.forEach(piece => addIfNew(piece.type));
            matchGroups
                .filter(group => group.type === 3 || group.type === 6)
                .forEach(group => addIfNew(group.type));

            if (newtypes.length === 0) {
                return;
            }

            const type = newtypes[newtypes.length - 1];
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
                this.playerMana, this.clampedPlayerGold(), this.playerMultiplier
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
         * The player's own gross earnings this window, clamped to this phase/match's coin
         * ceiling — mirrors the same clamp coin_ledger::sync() applies server-side (the
         * actual authority; this only drives the HUD coin display). Clamping here is
         * mandatory, not cosmetic: without it, playerGold keeps growing unbounded from board
         * matches alone, and the HUD would show a number the server was never going to
         * honour at payout.
         *
         * @returns {number}
         */
        clampedPlayerGold() {
            return Math.min(Math.round(this.playerGold), this.coinCeiling);
        }

        /**
         * The final reward this window would pay out right now: gross earnings minus the
         * boss's own share, both clamped to the same coin ceiling as clampedPlayerGold() —
         * mirrors coin_ledger::available() server-side (the actual authority for the real
         * payout; this only drives the end-of-match/phase-complete screens' own preview).
         *
         * @returns {number}
         */
        netCoinBalance() {
            const earned = this.clampedPlayerGold();
            const bossearned = Math.min(Math.round(this.bossGold), this.coinCeiling);
            return Math.max(0, earned - bossearned);
        }

        /**
         * Total damage dealt to the boss this phase, as the integer save_progress/advance_phase
         * accept. HP itself stays fractional (multipliers, crits), so this rounds the remaining
         * HP exactly the way replay::result() does server-side — a raw difference would be
         * rejected by PARAM_INT, and any other rounding would disagree with the replay-derived
         * value on an honest match.
         *
         * @returns {number}
         */
        damageDealt() {
            return this.maxBossHp - Math.max(0, Math.round(this.currentHp));
        }

        /**
         * Fixed maximum uses per phase/match for a consumable type, mirroring
         * attempt_consumables::PHASE_LIMITS. A type absent from that mirror (only 'hint') has
         * no fixed limit, so this returns Infinity — its real cap is owned stock instead,
         * checked separately.
         *
         * @param {string} type Consumable type.
         * @returns {number}
         */
        phaseLimit(type) {
            return CombatRules.phaseLimit(type);
        }

        applyDamageToBoss(amount) {
            const me = this.scene;
            const result = CombatRules.resolveDamage(this.currentHp, this.bossShieldReady, amount);
            this.currentHp = result.newHp;
            if (result.shieldConsumed) {
                this.bossShieldReady = false;
                me.ui.pushHistoryLog('boss', this.strings.historylogshieldblock);
            }

            this.updateUI();
            Accessibility.announce(
                this.strings.damagedealt
                    .replace('{$a->damage}', Math.round(result.appliedAmount))
                    .replace('{$a->hp}', Math.round(this.currentHp))
            );
            me.ui.bossSprite.setTint(0xff0000);
            me.time.delayedCall(200, () => {
                me.ui.bossSprite.clearTint();
            });
        }

        applyDamageToPlayer(amount) {
            const me = this.scene;
            const result = CombatRules.resolveDamage(this.currentPlayerHp, this.playerShieldReady, amount);
            this.currentPlayerHp = result.newHp;
            if (result.shieldConsumed) {
                this.playerShieldReady = false;
                me.ui.pushHistoryLog('player', this.strings.historylogshieldblock);
            }

            this.updateUI();
            Accessibility.announce(
                this.strings.damagetaken
                    .replace('{$a->damage}', Math.round(result.appliedAmount))
                    .replace('{$a->hp}', Math.round(this.currentPlayerHp))
            );
            // Mirrors applyDamageToBoss()'s own tint flash — replaces a screen shake found
            // disruptive, now that a player sprite actually exists on every layout (mobile
            // included) to carry the same feedback the boss already had.
            me.ui.playerSprite.setTint(0xff0000);
            me.time.delayedCall(200, () => {
                me.ui.playerSprite.clearTint();
            });
        }

        passTurnToBoss() {
            const me = this.scene;
            this.currentTurn = 'boss';
            me.input.enabled = false;

            const tick = CombatRules.resolvePoisonTick(this.currentHp, this.bossPoisonRounds, this.baseDamage);
            if (tick.ticked) {
                this.currentHp = tick.newHp;
                this.bossPoisonRounds = tick.newPoisonRounds;
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

            const tick = CombatRules.resolvePoisonTick(this.currentPlayerHp, this.playerPoisonRounds, this.baseDamage);
            if (tick.ticked) {
                this.currentPlayerHp = tick.newHp;
                this.playerPoisonRounds = tick.newPoisonRounds;
                this.updateUI();
                // Mirrors passTurnToBoss()'s own poison-tick tint, replacing a
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
            return CombatRules.needsRevive(this.minQuestions, this.questionsTotal);
        }

        /**
         * Brings the boss back at 50% of this match's own max HP and announces it, leaving
         * combat running exactly as if the boss had never reached 0 — may fire more than once
         * per match, since questionsTotal keeps advancing with every answer regardless.
         */
        reviveBoss() {
            this.currentHp = CombatRules.reviveHp(this.maxBossHp);
            this.updateUI();
            this.scene.ui.pushHistoryLog('boss', this.strings.historylogrevive);
            Accessibility.announce(this.strings.bossrevived);
        }

        /**
         * Uses a consumable, clicked from its use badge (ui.js::createPurchaseBadge()).
         * There is no purchase here — stock was already bought pre-match in the Lobby; this
         * only spends it. Shield alone gets a client-side "already armed" guard — there is
         * nothing to gain from using a second charge before the first is spent, so it is
         * worth skipping the round trip entirely for it; Quick Magic has no such guard,
         * matching its board-piece twin (the Grimoire never blocks overfilling either).
         *
         * Only usable at the start of the player's own turn, while the board is waiting for
         * their move: that is the one point the server-side replay applies a consumable at, so
         * a use during the boss's turn (a Shield raised mid-attack) would describe a fight the
         * replay can never reproduce.
         *
         * @param {string} type One of 'potion', 'shield', 'magic', 'sword'.
         */
        useConsumable(type) {
            const badge = this.scene.ui.purchaseBadges && this.scene.ui.purchaseBadges[type];
            if (badge && badge.disabled) {
                return;
            }
            if (this.currentTurn !== 'player' || !this.scene.input.enabled) {
                return;
            }
            if (type === 'shield' && (this.playerShieldReady || this.playerShieldMeter >= 100)) {
                return;
            }

            this.requestUse(type);
        }

        /**
         * Calls mod_playerpuzzle_use_stock for a non-hint type, applying the consumable's
         * effect once the server confirms the use.
         *
         * @param {string} type Consumable type.
         */
        requestUse(type) {
            // The board stays locked until the server answers, so the use lands in the event
            // log before the player's next move, never after it.
            const me = this.scene;
            me.input.enabled = false;
            let ended = false;
            const unlock = () => {
                if (!ended) {
                    me.input.enabled = true;
                }
            };

            Ajax.call([{
                methodname: 'mod_playerpuzzle_use_stock',
                args: {
                    cmid: this.gameConfig.cmid,
                    token: this.gameConfig.token,
                    type,
                },
            }])[0].done(res => {
                if (res.success) {
                    this.consumableStock[type] = res.newquantity;
                    this.consumableUses[type] = (this.consumableUses[type] || 0) + 1;
                    this.pendingMoveLog.push({type: 'consumable', kind: type});
                    ended = this.applyConsumableEffect(type);
                    this.updateUI();
                }
                unlock();
            }).fail(error => {
                unlock();
                Notification.alert(this.strings.shoperror, (error && error.message) || this.strings.shoperror);
            });
        }

        /**
         * Uses the Question Hint consumable for the question currently open in the modal and
         * reveals its text once the server confirms the use. Kept separate from requestUse()
         * rather than folded into it: Hint needs the extra questionid argument, and its
         * "effect" is revealing text in the modal rather than a combat-state change, so
         * nothing about its success path fits applyConsumableEffect()'s switch.
         *
         * @param {number} questionid The question currently open in the modal.
         */
        requestHint(questionid) {
            Ajax.call([{
                methodname: 'mod_playerpuzzle_use_stock',
                args: {
                    cmid: this.gameConfig.cmid,
                    token: this.gameConfig.token,
                    type: 'hint',
                    questionid,
                },
            }])[0].done(res => {
                if (!res.success) {
                    return;
                }
                this.consumableStock.hint = res.newquantity;
                this.consumableUses.hint = (this.consumableUses.hint || 0) + 1;
                $('#playerpuzzle-hint-text').text(res.hinttext).show();
                $('#playerpuzzle-btn-hint').hide().prop('disabled', true).off('click');
                this.updateUI();
            }).fail(error => {
                Notification.alert(this.strings.shoperror, (error && error.message) || this.strings.shoperror);
            });
        }

        /**
         * Applies a consumable's in-combat effect. Only ever called after the server has
         * confirmed the use (use_stock's {success: true}) — the effect itself is entirely
         * client-side, same as every other board-piece effect.
         *
         * @param {string} type Consumable type.
         * @returns {boolean} True when the effect ended the match (a Sword finishing the boss).
         */
        applyConsumableEffect(type) {
            const me = this.scene;
            // Reuses resolveShieldMeters()/resolvePoisonMeters()'s own overflow-preserving
            // logic via resolveConsumableEffect() instead of setting shieldReady/rounds
            // directly, so a purchase behaves identically to filling the ring by matching
            // Shield/Grimoire pieces.
            const result = CombatRules.resolveConsumableEffect(type, {
                currentPlayerHp: this.currentPlayerHp, maxPlayerHp: this.maxPlayerHp,
                playerShieldMeter: this.playerShieldMeter, playerShieldReady: this.playerShieldReady,
                bossShieldMeter: this.bossShieldMeter, bossShieldReady: this.bossShieldReady,
                playerPoisonMeter: this.playerPoisonMeter, playerPoisonRounds: this.playerPoisonRounds,
                bossPoisonMeter: this.bossPoisonMeter, bossPoisonRounds: this.bossPoisonRounds,
            }, {baseDamage: this.baseDamage});
            Object.assign(this, result.state);

            if (type === 'potion') {
                me.ui.pushHistoryLog('player', this.strings.historylogheal.replace('{$a}', Math.round(result.healAmount)));
            } else if (type === 'shield') {
                me.ui.pushHistoryLog('player', this.strings.historylogshieldcharge.replace('{$a}', 100));
            } else if (type === 'magic') {
                me.ui.pushHistoryLog('player', this.strings.historylogpoisoncharge.replace('{$a}', 100));
            } else if (type === 'sword') {
                this.applyDamageToBoss(result.damageAmount);
                me.ui.pushHistoryLog('player', this.strings.historylogattack.replace('{$a}', Math.round(result.damageAmount)));
            }

            return this.checkGameOver();
        }

        /**
         * Whether this victory is a mid-Campaign phase win (more phases/levels remain)
         * rather than the end of the whole attempt — mirrors the boundary check
         * advance_phase.php itself enforces server-side.
         *
         * @returns {boolean} True when a next phase or level exists to advance to.
         */
        hasNextPhase() {
            return CombatRules.hasNextPhase(this.gameConfig);
        }

        /**
         * Submits a real POST to play.php, mirroring the Lobby's own Play form. A Phaser
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
            if (this.gameConfig.isdemo) {
                // "Play Again" from a Demo's end screen starts another Demo, never a real
                // attempt — the student explicitly asked for practice, not a scored try.
                fields.isdemo = 1;
            }
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

            const netGold = this.netCoinBalance();

            const context = {
                msg: strings.phasecompletetitle,
                coinscollected: strings.coinscollected,
                playergold: netGold,
                btnreview: strings.debriefreview,
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
            // drawer toggle) while choosing the next phase's difficulty.
            document.getElementById('playerpuzzle-phasecomplete').showModal();

            // Read-only lookup, deliberately independent of advance_phase: that call mutates
            // the attempt (rotates the token, moves to the next phase) the instant it
            // succeeds, leaving no safe window to review before the page reloads. This can be
            // called the moment the overlay opens, with no such time pressure.
            Ajax.call([{
                methodname: 'mod_playerpuzzle_get_phase_questionlog',
                args: {cmid: this.gameConfig.cmid, token: this.gameConfig.token},
            }])[0].done(res => {
                if (res.questionlog && res.questionlog.length > 0) {
                    $('#btn-pp-review-phase').prop('hidden', false)
                        .off('click').on('click', () => this.showDebrief(res.questionlog));
                }
            });

            // Both buttons must go through advance_phase before doing anything else: the win
            // is only durable once this call lands (there is no separate "record the win" step
            // like showEndScreen's save_progress). Exiting without it left the attempt parked
            // on the just-defeated phase with a stale, already-0 boss HP checkpoint instead of
            // advancing — mirror that guard on both buttons instead of only on "Continue".
            const performAdvance = (onSuccess) => {
                $('#pp-phase-status').removeClass('text-success text-danger').addClass('text-muted')
                    .text(strings.advancingphase);
                $('#btn-pp-continue-phase, #btn-pp-exit-phase, #btn-pp-review-phase, #pp-phase-difficulty')
                    .prop('disabled', true);

                Ajax.call([{
                    methodname: 'mod_playerpuzzle_advance_phase',
                    args: {
                        cmid: this.gameConfig.cmid,
                        token: this.gameConfig.token,
                        damage: this.damageDealt(),
                        coinsearnedsofar: Math.round(this.playerGold),
                        bosscoinsearnedsofar: Math.round(this.bossGold),
                        difficulty: $('#pp-phase-difficulty').val() || 'normal',
                        eventoffset: this.confirmedEvents,
                        movelog: this.pendingMoveLog,
                    },
                }])[0].done(onSuccess).fail(() => {
                    $('#pp-phase-status').removeClass('text-muted').addClass('text-danger')
                        .text(strings.phaseadvanceerror);
                    $('#btn-pp-continue-phase, #btn-pp-exit-phase, #btn-pp-review-phase, #pp-phase-difficulty')
                        .prop('disabled', false);
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

            setTimeout(async() => {
                me.scene.pause();
                const dialogEl = document.getElementById('playerpuzzle-modal');

                if (dialogEl) {
                    // Native <dialog> restores no focus of its own on close() — the
                    // element that had focus when the dialog opened (typically nothing,
                    // since Phaser's canvas is not itself focusable) is saved here and
                    // restored explicitly to it when the dialog closes.
                    const previouslyFocused = document.activeElement;

                    // The question is drawn server-side, never picked by the client — naming
                    // an arbitrary questionid to validate_answer's forwhom=boss path would
                    // otherwise let the client probe any approved question in the instance:
                    // on Hard difficulty the boss's guess is correct with probability 1.0, so
                    // that alone would be a free oracle for the correct answer. Falls back to
                    // the same "no question" state a missing/empty pool always showed.
                    let question = {text: ctx.strings.questionerror, options: [], hashint: false};
                    try {
                        const res = await Ajax.call([{
                            methodname: 'mod_playerpuzzle_draw_question',
                            // The move that triggered this question is still pending (no
                            // checkpoint runs while cells are empty) and must be on record
                            // before the question can have an outcome.
                            args: {
                                cmid: ctx.gameConfig.cmid,
                                token: ctx.gameConfig.token,
                                eventoffset: ctx.confirmedEvents,
                                movelog: ctx.pendingMoveLog.slice(0, MAX_EVENTS_PER_CHECKPOINT),
                            },
                        }])[0];
                        ctx.confirmStoredEvents(res.eventcount);
                        if (res.available) {
                            question = {
                                id: res.id,
                                text: res.text,
                                hashint: res.hashint,
                                options: res.options,
                            };
                        }
                    } catch (err) {
                        // Question stays the questionerror/no-options fallback above.
                    }

                    let questionText = trigger === 'boss'
                        ? `<strong class="text-danger pp-bold">${ctx.strings.bosstrigger}</strong><br><br>${question.text}`
                        : question.text;

                    // Demo match: shown once per session, only for the player's
                    // own question challenge — the boss's is auto-resolved with no player
                    // interaction, so the instruction would have nothing to explain.
                    if (trigger === 'player' && ctx.isdemo && !ctx.tutorialQuestionInstructionShown) {
                        ctx.tutorialQuestionInstructionShown = true;
                        questionText += `<br><br><em>${ctx.strings.tutorialquestioninstruction}</em>`;
                    }

                    $('#playerpuzzle-question-text').html(questionText);
                    const answersContainer = $('#playerpuzzle-answers-container');
                    answersContainer.empty();
                    // Re-enabled here, not only where each path shows it: a skipped player
                    // question closes with the button still disabled (it only enables once an
                    // answer is picked), and the boss's or an empty question's "Continue" would
                    // otherwise come up disabled, leaving no way to close the modal.
                    $('#playerpuzzle-btn-confirm').hide().prop('disabled', false).off('click');
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
                            $('#playerpuzzle-btn-skip').show().on('click', () => {
                                ctx.recordQuestionEvent('player', 'skipped');
                                closeModal();
                            });
                            $('#playerpuzzle-btn-confirm').text(ctx.strings.btnattack)
                                .prop('disabled', true).show();

                            if (question.hashint) {
                                const hintStock = ctx.consumableStock.hint || 0;
                                const hintDisabled = !ctx.isdemo && hintStock <= 0;
                                $('#playerpuzzle-btn-hint')
                                    .text(ctx.strings.hintbutton.replace('{$a}', ctx.isdemo ? '∞' : hintStock))
                                    .prop('disabled', hintDisabled)
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
                                    ctx.recordQuestionEvent('player', 'answered');
                                    applyResult(!!res.correct, res.correctanswerid || null);
                                }).fail(() => {
                                    ctx.recordQuestionEvent('player', 'failed');
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
                                    answerid: 0,
                                    forwhom: 'boss',
                                },
                            }])[0].done(res => {
                                ctx.recordQuestionEvent('boss', 'answered');
                                renderBossResult(!!res.correct, res.pickedanswerid || null);
                            }).fail(() => {
                                ctx.recordQuestionEvent('boss', 'failed');
                                renderBossResult(false, null);
                            });
                        }

                    } else {
                        ctx.recordQuestionEvent(trigger, 'unavailable');
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
            // The save_progress call below consumes the attempt's token and carries every event
            // still pending, so any later checkpoint could only fail against the spent token.
            this.matchSaved = true;
            me.add.graphics().fillStyle(0x000000, 0.85).fillRect(0, 0, me.ui.L.w, me.ui.L.h).setDepth(99);

            // The boss's own Coin total (bossGold) never buys it anything — it exists purely to
            // net against the student's balance here, the only place it is spent.
            const netGold = this.netCoinBalance();
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
                    damage: this.damageDealt(),
                    coinsearnedsofar: Math.round(this.playerGold),
                    bosscoinsearnedsofar: Math.round(this.bossGold),
                    eventoffset: this.confirmedEvents,
                    movelog: this.pendingMoveLog,
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
