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

define(['jquery', 'core/ajax', 'core/templates'], function($, Ajax, Templates) {
    'use strict';

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
            this.currentTurn = 'player';

            this.playerGold = 0;
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

            this.bossGold = 0;
            this.bossPoisonMeter = 0;
            this.bossPoisonRounds = 0;
            this.bossShieldMeter = 0;
            this.bossShieldReady = false;
            this.bossMana = 0;
            this.bossMultiplier = 1;
            this.maxBossHp = parseInt(gameConfig.bosshp) || 1000;
            this.currentHp = this.maxBossHp;
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
                        const heal = this.baseDamage / 6;
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
                        const heal = this.baseDamage / 6;
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
                    const coins = this.coinGain * this.comboMultiplier(group.pieces.length);
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

            this.updateUI();
            return {damage: damageDealt, question: questionTriggered, trigger: triggeredBy};
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
            this.scene.ui.updatePlayerBar(
                this.currentPlayerHp, this.maxPlayerHp,
                this.playerPoisonMeter, this.playerPoisonRounds,
                this.playerShieldMeter, this.playerShieldReady,
                this.playerMana, this.playerGold, this.playerMultiplier
            );
            this.scene.ui.updateBossBar(
                this.currentHp, this.maxBossHp,
                this.bossPoisonMeter, this.bossPoisonRounds,
                this.bossShieldMeter, this.bossShieldReady,
                this.bossMana, this.bossGold, this.bossMultiplier
            );
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
            me.cameras.main.shake(250, 0.015);
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
                me.cameras.main.shake(200, 0.008);
                me.ui.pushHistoryLog('player', this.strings.historylogpoisontick.replace('{$a}', this.baseDamage));
                if (this.checkGameOver()) {
                    return true;
                }
            }

            return false;
        }

        checkGameOver() {
            if (this.currentHp <= 0) {
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
         */
        submitRestartForm() {
            const restartForm = document.createElement('form');
            restartForm.method = 'POST';
            let restarturl = `${M.cfg.wwwroot}/mod/playerpuzzle/play.php?id=${this.gameConfig.cmid}`;
            if (this.gameConfig.mobile) {
                restarturl += '&mobile=1';
            }
            restartForm.action = restarturl;
            const sesskeyInput = document.createElement('input');
            sesskeyInput.type = 'hidden';
            sesskeyInput.name = 'sesskey';
            sesskeyInput.value = M.cfg.sesskey;
            restartForm.appendChild(sesskeyInput);
            document.body.appendChild(restartForm);
            restartForm.submit();
        }

        /**
         * Shown instead of showEndScreen() when a Campaign attempt wins a phase that is not
         * the last phase of the last level: advances the attempt server-side (banking this
         * phase's coins) and, on success, offers to continue into the newly advanced phase
         * via a full reload of play.php — the same resumed 'inprogress' row picks up the new
         * currentlevel/currentphase there, so no game config needs to be threaded through by
         * hand on this side.
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
                advancingphase: strings.advancingphase,
                btncontinue: strings.btncontinue,
                btnexitgame: strings.btnexitgame,
            };

            const html = await Templates.render('mod_playerpuzzle/phase_complete_overlay', context);
            $('#playerpuzzle-canvas-container').append(html);

            let advanced = false;

            const attemptAdvance = () => {
                $('#pp-phase-status').removeClass('text-success text-danger').addClass('text-muted')
                    .text(strings.advancingphase);
                $('#btn-pp-continue-phase').prop('disabled', true);

                Ajax.call([{
                    methodname: 'mod_playerpuzzle_advance_phase',
                    args: {
                        cmid: this.gameConfig.cmid,
                        token: this.gameConfig.token,
                        damage: this.maxBossHp - this.currentHp,
                        gold: netGold,
                    },
                }])[0].done(res => {
                    advanced = true;
                    const nextinfo = strings.nextlevel.replace('{$a}', res.currentlevel)
                        + ' — ' + strings.nextphase.replace('{$a}', res.currentphase);
                    $('#pp-phase-status').removeClass('text-muted').addClass('text-success').text(nextinfo);
                    $('#btn-pp-continue-phase').prop('disabled', false);
                }).fail(() => {
                    $('#pp-phase-status').removeClass('text-muted').addClass('text-danger')
                        .text(strings.phaseadvanceerror);
                    $('#btn-pp-continue-phase').prop('disabled', false);
                });
            };

            $('#btn-pp-continue-phase').on('click', () => {
                if (advanced) {
                    this.submitRestartForm();
                } else {
                    attemptAdvance();
                }
            });
            $('#btn-pp-exit-phase').on('click', () => {
                window.location.href = this.gameConfig.viewurl;
            });

            attemptAdvance();
        }

        openQuestionModal(trigger) {
            const me = this.scene;
            const ctx = this;
            me.input.enabled = false;

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

                    const numOptions = question.options ? question.options.length : 0;
                    const bossPickIdx = (trigger === 'boss' && numOptions > 0)
                        ? Math.floor(Math.random() * numOptions) : -1;

                    const questionText = trigger === 'boss'
                        ? `<strong class="text-danger pp-bold">${ctx.strings.bosstrigger}</strong><br><br>${question.text}`
                        : question.text;

                    $('#playerpuzzle-question-text').html(questionText);
                    const answersContainer = $('#playerpuzzle-answers-container');
                    answersContainer.empty();
                    $('#playerpuzzle-btn-confirm').hide().off('click');
                    $('#playerpuzzle-btn-skip').hide().off('click');

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
                                        questionid: question.id,
                                        answerid: selectedAnswer.id,
                                    },
                                }])[0].done(res => {
                                    applyResult(!!res.correct, res.correctanswerid || null);
                                }).fail(() => {
                                    applyResult(false, null);
                                });
                            });

                        } else {
                            const bossPick = question.options[bossPickIdx];

                            const renderBossResult = isBossCorrect => {
                                question.options.forEach((option, idx) => {
                                    const plainText = option.text.replace(/(<([^>]+)>)/gi, '');
                                    const btn = $(`<button class="${btnClass}" disabled>${option.text}</button>`);

                                    if (idx === bossPickIdx) {
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
                                    questionid: question.id,
                                    answerid: bossPick.id,
                                },
                            }])[0].done(res => {
                                renderBossResult(!!res.correct);
                            }).fail(() => {
                                renderBossResult(false);
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
                btnplayagain: strings.btnplayagain,
                btnexitgame: strings.btnexitgame,
            };

            const html = await Templates.render('mod_playerpuzzle/gameover_overlay', context);
            $('#playerpuzzle-canvas-container').append(html);

            Ajax.call([{
                methodname: 'mod_playerpuzzle_save_progress',
                args: {
                    cmid: this.gameConfig.cmid,
                    token: this.gameConfig.token,
                    gold: netGold,
                    victory: victory ? 1 : 0,
                    damage: this.maxBossHp - this.currentHp,
                },
            }])[0].done(res => {
                const successMsg = strings.progresssaved.replace('{$a}', res.coinsbanked);
                $('#pp-save-status').removeClass('text-muted').addClass('text-success')
                    .text(successMsg);
                $('#btn-pp-restart, #btn-pp-exit').prop('disabled', false);
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
    }

    return CombatHandler;
});
