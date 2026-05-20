/**
 * Combat and Rules Module for PlayerPuzzle.
 *
 * @module     mod_playerpuzzle/combat
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/templates'], function($, Templates) {
    'use strict';

    class CombatHandler {
        constructor(scene, gameConfig, strings) {
            this.scene = scene;
            this.gameConfig = gameConfig;
            this.strings = strings;

            this.baseDamage = parseInt(gameConfig.bossdamage) || 10;
            this.currentTurn = 'player';

            this.playerGold = 0;
            this.playerShield = 0;
            this.playerMultiplier = 1;
            this.playerMana = 0;
            this.maxPlayerHp = 100;
            this.currentPlayerHp = this.maxPlayerHp;

            this.bossPoison = 0;
            this.bossMana = 0;
            this.maxBossHp = gameConfig.bosshp;
            this.currentHp = this.maxBossHp;
        }

        processEffects(destroyedPieces) {
            const me = this.scene;
            let questionTriggered = false;
            let triggeredBy = null;
            let damageDealt = 0;

            me.sfxMatch.play();

            for (const piece of destroyedPieces) {
                if (this.currentTurn === 'player') {
                    me.sfxHit.play();
                    if (piece.type === 6) {
                        this.playerGold += 10;
                    } else if (piece.type === 5) {
                        this.currentPlayerHp = Math.min(this.maxPlayerHp, this.currentPlayerHp + 5);
                    } else if (piece.type === 0) {
                        this.playerMultiplier += 0.1;
                    } else if (piece.type === 4) {
                        this.playerShield += 5;
                    } else if (piece.type === 1) {
                        this.bossPoison += 3;
                    } else if (piece.type === 2) {
                        this.playerMana += 20;
                    }
                } else {
                    if (piece.type === 2) {
                        this.bossMana += 20;
                    }
                }

                if (piece.type === 3) {
                    damageDealt += (this.baseDamage / 3);
                }

                me.board.grid[piece.row][piece.col] = null;
                me.tweens.add({
                    targets: piece, scaleX: 0, scaleY: 0, duration: 200,
                    onComplete: (tween, targets) => {
                        targets[0].destroy();
                    }
                });
            }

            this.playerMultiplier = Math.round(this.playerMultiplier * 10) / 10;
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

        updateUI() {
            this.scene.ui.updatePlayerBar(
                this.currentPlayerHp, this.maxPlayerHp, this.playerMana,
                this.playerShield, this.playerGold, this.playerMultiplier
            );
            this.scene.ui.updateBossBar(
                this.currentHp, this.maxBossHp, this.bossMana, this.bossPoison
            );
        }

        applyDamageToBoss(amount) {
            const me = this.scene;
            this.currentHp = Math.max(0, this.currentHp - amount);
            this.updateUI();
            me.ui.bossSprite.setTint(0xff0000);
            me.time.delayedCall(200, () => {
                me.ui.bossSprite.clearTint();
            });
        }

        applyDamageToPlayer(amount) {
            const me = this.scene;
            const blocked = Math.min(amount, this.playerShield);
            this.playerShield -= blocked;
            amount -= blocked;

            this.currentPlayerHp = Math.max(0, this.currentPlayerHp - amount);
            this.updateUI();
            me.cameras.main.shake(250, 0.015);
        }

        passTurnToBoss() {
            const me = this.scene;
            this.currentTurn = 'boss';
            me.input.enabled = false;

            if (this.bossPoison > 0) {
                this.currentHp = Math.max(0, this.currentHp - 5);
                this.bossPoison--;
                this.updateUI();
                me.ui.bossSprite.setTint(0xff00ff);
                me.time.delayedCall(300, () => {
                    me.ui.bossSprite.clearTint();
                });
            }

            me.time.delayedCall(800, this.executeBossTurn, [], this);
        }

        executeBossTurn() {
            const me = this.scene;
            const move = me.board.findMove();

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

        passTurnToPlayer() {
            this.currentTurn = 'player';
        }

        checkGameOver() {
            if (this.currentHp <= 0) {
                this.showEndScreen(true);
                return true;
            }
            if (this.currentPlayerHp <= 0) {
                this.showEndScreen(false);
                return true;
            }
            return false;
        }

        openQuestionModal(trigger) {
            const me = this.scene;
            const ctx = this;
            me.input.enabled = false;

            setTimeout(() => {
                me.scene.pause();
                const modalMoodle = $('#playerpuzzle-modal');

                if (modalMoodle.length > 0) {
                    let question = {name: 'Notice', questiontext: ctx.strings.questionerror};
                    if (ctx.gameConfig.questions && ctx.gameConfig.questions.length > 0) {
                        const idx = Math.floor(Math.random() * ctx.gameConfig.questions.length);
                        question = ctx.gameConfig.questions[idx];
                    }

                    let questionText = question.questiontext ?
                        question.questiontext : question.name;

                    const correctAnswers = [];
                    const wrongAnswers = [];
                    if (question.answers) {
                        question.answers.forEach((r, indexR) => {
                            if (parseFloat(r.fraction) > 0) {
                                correctAnswers.push(indexR);
                            } else {
                                wrongAnswers.push(indexR);
                            }
                        });
                    }

                    let bossCorrect = (Math.random() > 0.3);
                    if (wrongAnswers.length === 0) {
                        bossCorrect = true;
                    }
                    if (correctAnswers.length === 0) {
                        bossCorrect = false;
                    }

                    let bossAnswerIdx = -1;
                    if (trigger === 'boss') {
                        if (bossCorrect && correctAnswers.length > 0) {
                            bossAnswerIdx = correctAnswers[
                                Math.floor(Math.random() * correctAnswers.length)
                            ];
                        } else if (!bossCorrect && wrongAnswers.length > 0) {
                            bossAnswerIdx = wrongAnswers[
                                Math.floor(Math.random() * wrongAnswers.length)
                            ];
                        }

                        const bossWarning = `<strong class="text-danger pp-bold">${ctx.strings.bosstrigger}</strong><br><br>`;
                        questionText = bossWarning + questionText;
                    }

                    $('#playerpuzzle-pergunta-texto').html(questionText);
                    const answersContainer = $('#playerpuzzle-respostas-container');
                    answersContainer.empty();
                    $('#playerpuzzle-btn-fechar').hide().off('click');
                    $('#playerpuzzle-btn-pular').hide().off('click');

                    const closeModal = () => {
                        modalMoodle.removeClass('show').css('display', 'none');
                        me.scene.resume();
                        me.time.delayedCall(250, me.board.applyGravity, [], me.board);
                    };

                    if (question.answers && question.answers.length > 0) {
                        const btnClass = ctx.gameConfig.mobile
                            ? 'btn btn-outline-primary w-100 pp-answer-btn'
                            : 'btn btn-outline-primary btn-lg mb-3 w-100';

                        if (trigger === 'player') {
                            $('#playerpuzzle-btn-pular').show().on('click', closeModal);
                            $('#playerpuzzle-btn-fechar').text(ctx.strings.btnattack)
                                .prop('disabled', true).show();

                            let selectedAnswer = null;

                            question.answers.forEach(answer => {
                                const cleanText = answer.answer.replace(/(<([^>]+)>)/gi, '');
                                const btn = $(
                                    `<button class="${btnClass}" data-fraction="${answer.fraction}">${cleanText}</button>`
                                );

                                // Using function() to preserve jQuery's this binding for the clicked button.
                                btn.on('click', function() {
                                    answersContainer.find('button')
                                        .removeClass('btn-warning')
                                        .addClass('btn-outline-primary');
                                    $(this).removeClass('btn-outline-primary').addClass('btn-warning');
                                    selectedAnswer = answer;
                                    $('#playerpuzzle-btn-fechar').prop('disabled', false);
                                });

                                answersContainer.append(btn);
                            });

                            $('#playerpuzzle-btn-fechar').off('click').on('click', () => {
                                if (!selectedAnswer) {
                                    return;
                                }
                                answersContainer.find('button').prop('disabled', true);
                                $('#playerpuzzle-btn-pular').hide();
                                $('#playerpuzzle-btn-fechar').prop('disabled', true);

                                let feedbackMsg;
                                if (parseFloat(selectedAnswer.fraction) > 0) {
                                    answersContainer.find('.btn-warning')
                                        .removeClass('btn-warning').addClass('btn-success text-white');
                                    ctx.applyDamageToBoss(ctx.baseDamage * 3 * ctx.playerMultiplier);
                                    me.ui.bossSprite.setTint(0x0088ff);
                                    me.tweens.add({
                                        targets: me.ui.bossSprite, y: me.ui.bossSprite.y - 20,
                                        yoyo: true, duration: 150,
                                        onComplete: () => {
                                            me.ui.bossSprite.clearTint();
                                        }
                                    });
                                    const correctText = ctx.strings.playercorrect;
                                    feedbackMsg = '<div class="alert alert-success mt-2 mb-0">'
                                        + `<strong>${correctText}</strong></div>`;
                                } else {
                                    answersContainer.find('.btn-warning')
                                        .removeClass('btn-warning').addClass('btn-danger text-white');
                                    // Using function() to preserve jQuery's this binding in the each() iterator.
                                    answersContainer.find('[data-fraction]').each(function() {
                                        if (parseFloat($(this).data('fraction')) > 0) {
                                            $(this).removeClass('btn-outline-primary')
                                                .addClass('btn-success text-white');
                                        }
                                    });
                                    ctx.applyDamageToPlayer(30);
                                    ctx.playerMultiplier = 1;
                                    ctx.updateUI();
                                    const wrongMsg = ctx.strings.playerwrong.replace('{$a}', 30);
                                    feedbackMsg = `<div class="alert alert-danger mt-2 mb-0"><strong>${wrongMsg}</strong></div>`;
                                }

                                answersContainer.append(feedbackMsg);
                                $('#playerpuzzle-btn-fechar').text(ctx.strings.btncontinue)
                                    .prop('disabled', false).off('click').on('click', closeModal);
                            });

                        } else {
                            question.answers.forEach((answer, idx) => {
                                const cleanText = answer.answer.replace(/(<([^>]+)>)/gi, '');
                                const btn = $(
                                    `<button class="${btnClass}" data-fraction="${answer.fraction}">${cleanText}</button>`
                                );

                                btn.prop('disabled', true);
                                if (idx === bossAnswerIdx) {
                                    if (bossCorrect) {
                                        btn.removeClass('btn-outline-primary')
                                            .addClass('btn-danger text-white');
                                        btn.html(`<strong>${ctx.strings.bossansweredcorrect.replace('{$a}', cleanText)}</strong>`);
                                    } else {
                                        btn.removeClass('btn-outline-primary')
                                            .addClass('btn-secondary text-white');
                                        btn.html(`<strong>${ctx.strings.bossansweredwrong.replace('{$a}', cleanText)}</strong>`);
                                    }
                                } else {
                                    btn.removeClass('btn-outline-primary').addClass('btn-light');
                                }
                                answersContainer.append(btn);
                            });

                            let bossFeedback;
                            if (bossCorrect) {
                                ctx.applyDamageToPlayer(ctx.baseDamage * 3);
                                const dmgMsg = ctx.strings.bosscorrectfeedback.replace('{$a}', ctx.baseDamage * 3);
                                bossFeedback = `<div class="alert alert-danger mt-2 mb-0"><strong>${dmgMsg}</strong></div>`;
                            } else {
                                const wfMsg = ctx.strings.bosswrongfeedback;
                                bossFeedback = `<div class="alert alert-success mt-2 mb-0"><strong>${wfMsg}</strong></div>`;
                            }
                            answersContainer.append(bossFeedback);
                            $('#playerpuzzle-btn-fechar').text(ctx.strings.btncontinue).show()
                                .off('click').on('click', closeModal);
                        }

                    } else {
                        answersContainer.append(
                            `<p class="text-danger">${ctx.strings.noanswers}</p>`
                        );
                        $('#playerpuzzle-btn-fechar').text(ctx.strings.btncontinue).show()
                            .off('click').on('click', closeModal);
                    }

                    modalMoodle.addClass('show').css('display', 'block');
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

            const context = {
                colorclass: victory ? 'text-success' : 'text-danger',
                msg: victory ? strings.victory : strings.defeat,
                coinscollected: strings.coinscollected,
                playergold: this.playerGold,
                maxmultiplier: strings.maxmultiplier,
                playermultiplier: this.playerMultiplier.toFixed(1),
                savingprogress: strings.savingprogress,
                btnplayagain: strings.btnplayagain,
                btnexitgame: strings.btnexitgame,
            };

            const html = await Templates.render('mod_playerpuzzle/gameover_overlay', context);
            $('#playerpuzzle-canvas-container').append(html);

            const postData = {
                cmid: this.gameConfig.cmid,
                sesskey: M.cfg.sesskey,
                ouro: this.playerGold,
                vitoria: victory ? 1 : 0,
                dano: this.maxBossHp - this.currentHp
            };

            $.post(`${M.cfg.wwwroot}/mod/playerpuzzle/ajax.php`, postData)
                .done(res => {
                    if (res.status === 'success') {
                        const successMsg = strings.progresssaved.replace('{$a}', res.totalcoins);
                        $('#pp-save-status').removeClass('text-muted').addClass('text-success')
                            .text(successMsg);
                    }
                    $('#btn-pp-restart, #btn-pp-exit').prop('disabled', false);
                })
                .fail(() => {
                    $('#pp-save-status').removeClass('text-muted').addClass('text-danger')
                        .text(strings.saveerror);
                    $('#btn-pp-restart, #btn-pp-exit').prop('disabled', false);
                });

            $('#btn-pp-restart').on('click', () => {
                $('#playerpuzzle-gameover').remove();
                me.scene.restart();
            });
            $('#btn-pp-exit').on('click', () => {
                window.location.href = viewurl;
            });
        }
    }

    return CombatHandler;
});
