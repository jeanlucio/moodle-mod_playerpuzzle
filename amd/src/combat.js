/**
 * Combat and Rules Module for PlayerPuzzle.
 *
 * @module     mod_playerpuzzle/combat
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
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
            var me = this.scene;
            var questionTriggered = false;
            var triggeredBy = null;
            var damageDealt = 0;

            me.sfxMatch.play();

            for (var i = 0; i < destroyedPieces.length; i++) {
                var piece = destroyedPieces[i];
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
                    onComplete: function(tween, targets) {
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
            var me = this.scene;
            this.currentHp = Math.max(0, this.currentHp - amount);
            this.updateUI();
            me.ui.bossSprite.setTint(0xff0000);
            me.time.delayedCall(200, function() {
                me.ui.bossSprite.clearTint();
            });
        }

        applyDamageToPlayer(amount) {
            var me = this.scene;
            var blocked = Math.min(amount, this.playerShield);
            this.playerShield -= blocked;
            amount -= blocked;

            this.currentPlayerHp = Math.max(0, this.currentPlayerHp - amount);
            this.updateUI();
            me.cameras.main.shake(250, 0.015);
        }

        passTurnToBoss() {
            var me = this.scene;
            this.currentTurn = 'boss';
            me.input.enabled = false;

            if (this.bossPoison > 0) {
                this.currentHp = Math.max(0, this.currentHp - 5);
                this.bossPoison--;
                this.updateUI();
                me.ui.bossSprite.setTint(0xff00ff);
                me.time.delayedCall(300, function() {
                    me.ui.bossSprite.clearTint();
                });
            }

            me.time.delayedCall(800, this.executeBossTurn, [], this);
        }

        executeBossTurn() {
            var me = this.scene;
            var move = me.board.findMove();

            if (move) {
                me.tweens.add({
                    targets: me.ui.bossSprite,
                    scaleX: 1.2, scaleY: 1.2, yoyo: true, duration: 300,
                    onComplete: function() {
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
            var me = this.scene;
            var ctx = this;
            me.input.enabled = false;

            setTimeout(function() {
                me.scene.pause();
                var modalMoodle = $('#playerpuzzle-modal');

                if (modalMoodle.length > 0) {
                    var question = {name: 'Notice', questiontext: ctx.strings.questionerror};
                    if (ctx.gameConfig.questions && ctx.gameConfig.questions.length > 0) {
                        var idx = Math.floor(Math.random() * ctx.gameConfig.questions.length);
                        question = ctx.gameConfig.questions[idx];
                    }

                    var questionText = question.questiontext ?
                        question.questiontext : question.name;

                    var correctAnswers = [];
                    var wrongAnswers = [];
                    if (question.answers) {
                        question.answers.forEach(function(r, indexR) {
                            if (parseFloat(r.fraction) > 0) {
                                correctAnswers.push(indexR);
                            } else {
                                wrongAnswers.push(indexR);
                            }
                        });
                    }

                    var bossCorrect = (Math.random() > 0.3);
                    if (wrongAnswers.length === 0) {
                        bossCorrect = true;
                    }
                    if (correctAnswers.length === 0) {
                        bossCorrect = false;
                    }

                    var bossAnswerIdx = -1;
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

                        var bossWarning = '<span class="text-danger fw-bold">'
                            + ctx.strings.bosstrigger + '</span><br><br>';
                        questionText = bossWarning + questionText;
                    }

                    $('#playerpuzzle-pergunta-texto').html(questionText);
                    var answersContainer = $('#playerpuzzle-respostas-container');
                    answersContainer.empty();
                    $('#playerpuzzle-btn-fechar').hide().off('click');
                    $('#playerpuzzle-btn-pular').hide().off('click');

                    var closeModal = function() {
                        modalMoodle.removeClass('show').css('display', 'none');
                        me.scene.resume();
                        me.time.delayedCall(250, me.board.applyGravity, [], me.board);
                    };

                    if (question.answers && question.answers.length > 0) {
                        var btnClass = ctx.gameConfig.mobile
                            ? 'btn btn-outline-primary w-100 pp-answer-btn'
                            : 'btn btn-outline-primary btn-lg mb-3 w-100';

                        if (trigger === 'player') {
                            $('#playerpuzzle-btn-pular').show().on('click', closeModal);
                            $('#playerpuzzle-btn-fechar').text(ctx.strings.btnattack)
                                .prop('disabled', true).show();

                            var selectedAnswer = null;

                            question.answers.forEach(function(answer) {
                                var cleanText = answer.answer.replace(/(<([^>]+)>)/gi, '');
                                var btn = $('<button class="' + btnClass
                                    + '" data-fraction="' + answer.fraction + '">'
                                    + cleanText + '</button>');

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

                            $('#playerpuzzle-btn-fechar').off('click').on('click', function() {
                                if (!selectedAnswer) {
                                    return;
                                }
                                answersContainer.find('button').prop('disabled', true);
                                $('#playerpuzzle-btn-pular').hide();
                                $('#playerpuzzle-btn-fechar').prop('disabled', true);

                                var feedbackMsg;
                                if (parseFloat(selectedAnswer.fraction) > 0) {
                                    answersContainer.find('.btn-warning')
                                        .removeClass('btn-warning').addClass('btn-success text-white');
                                    ctx.applyDamageToBoss(ctx.baseDamage * 3 * ctx.playerMultiplier);
                                    me.ui.bossSprite.setTint(0x0088ff);
                                    me.tweens.add({
                                        targets: me.ui.bossSprite, y: me.ui.bossSprite.y - 20,
                                        yoyo: true, duration: 150,
                                        onComplete: function() {
                                            me.ui.bossSprite.clearTint();
                                        }
                                    });
                                    feedbackMsg = '<div class="alert alert-success mt-2 mb-0"><strong>'
                                        + ctx.strings.playercorrect + '</strong></div>';
                                } else {
                                    answersContainer.find('.btn-warning')
                                        .removeClass('btn-warning').addClass('btn-danger text-white');
                                    answersContainer.find('[data-fraction]').each(function() {
                                        if (parseFloat($(this).data('fraction')) > 0) {
                                            $(this).removeClass('btn-outline-primary')
                                                .addClass('btn-success text-white');
                                        }
                                    });
                                    ctx.applyDamageToPlayer(30);
                                    ctx.playerMultiplier = 1;
                                    ctx.updateUI();
                                    feedbackMsg = '<div class="alert alert-danger mt-2 mb-0"><strong>'
                                        + ctx.strings.playerwrong.replace('{$a}', 30)
                                        + '</strong></div>';
                                }

                                answersContainer.append(feedbackMsg);
                                $('#playerpuzzle-btn-fechar').text(ctx.strings.btncontinue)
                                    .prop('disabled', false).off('click').on('click', closeModal);
                            });

                        } else {
                            question.answers.forEach(function(answer, idx) {
                                var cleanText = answer.answer.replace(/(<([^>]+)>)/gi, '');
                                var btn = $('<button class="' + btnClass
                                    + '" data-fraction="' + answer.fraction + '">'
                                    + cleanText + '</button>');

                                btn.prop('disabled', true);
                                if (idx === bossAnswerIdx) {
                                    if (bossCorrect) {
                                        btn.removeClass('btn-outline-primary')
                                            .addClass('btn-danger text-white');
                                        btn.html('<strong>'
                                            + ctx.strings.bossansweredcorrect.replace('{$a}', cleanText)
                                            + '</strong>');
                                    } else {
                                        btn.removeClass('btn-outline-primary')
                                            .addClass('btn-secondary text-white');
                                        btn.html('<strong>'
                                            + ctx.strings.bossansweredwrong.replace('{$a}', cleanText)
                                            + '</strong>');
                                    }
                                } else {
                                    btn.removeClass('btn-outline-primary').addClass('btn-light');
                                }
                                answersContainer.append(btn);
                            });

                            var bossFeedback;
                            if (bossCorrect) {
                                ctx.applyDamageToPlayer(ctx.baseDamage * 3);
                                bossFeedback = '<div class="alert alert-danger mt-2 mb-0"><strong>'
                                    + ctx.strings.bosscorrectfeedback.replace('{$a}', ctx.baseDamage * 3)
                                    + '</strong></div>';
                            } else {
                                bossFeedback = '<div class="alert alert-success mt-2 mb-0"><strong>'
                                    + ctx.strings.bosswrongfeedback + '</strong></div>';
                            }
                            answersContainer.append(bossFeedback);
                            $('#playerpuzzle-btn-fechar').text(ctx.strings.btncontinue).show()
                                .off('click').on('click', closeModal);
                        }

                    } else {
                        answersContainer.append(
                            '<p class="text-danger">' + ctx.strings.noanswers + '</p>'
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

        showEndScreen(victory) {
            var me = this.scene;
            var strings = this.strings;
            me.input.enabled = false;
            me.add.graphics().fillStyle(0x000000, 0.85).fillRect(0, 0, me.ui.L.w, me.ui.L.h).setDepth(99);

            var msg = victory ? strings.victory : strings.defeat;
            var colorClass = victory ? 'text-success' : 'text-danger';

            var statsHtml = '<p class="lead text-white mb-4">' + strings.coinscollected;
            statsHtml += ' <strong class="text-warning">' + this.playerGold + '</strong><br>';
            statsHtml += strings.maxmultiplier + ' <strong class="text-info">x'
                + this.playerMultiplier.toFixed(1) + '</strong></p>';
            statsHtml += '<p id="pp-save-status" class="text-muted small">'
                + strings.savingprogress + '</p>';

            var overlayHtml = '<div id="playerpuzzle-gameover" ' +
                'class="d-flex flex-column justify-content-center align-items-center" ' +
                'style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;' +
                ' z-index: 1000; text-align: center;">';
            overlayHtml += '<h1 class="display-4 fw-bold ' + colorClass + ' mb-3">' + msg + '</h1>'
                + statsHtml;
            overlayHtml += '<div class="mt-2 d-flex flex-wrap justify-content-center gap-3">';
            overlayHtml += '<button id="btn-pp-restart" disabled '
                + 'class="btn btn-primary btn-lg fw-bold shadow">'
                + strings.btnplayagain + '</button>';
            overlayHtml += '<button id="btn-pp-exit" disabled '
                + 'class="btn btn-secondary btn-lg fw-bold shadow">'
                + strings.btnexitgame + '</button>';
            overlayHtml += '</div></div>';

            $('#playerpuzzle-canvas-container').css('position', 'relative').append(overlayHtml);

            var postData = {
                cmid: this.gameConfig.cmid,
                sesskey: M.cfg.sesskey,
                ouro: this.playerGold,
                vitoria: victory ? 1 : 0,
                dano: this.maxBossHp - this.currentHp
            };

            $.post(M.cfg.wwwroot + '/mod/playerpuzzle/ajax.php', postData)
                .done(function(respostaStr) {
                    var res = JSON.parse(respostaStr);
                    if (res.status === 'success') {
                        var successMsg = strings.progresssaved.replace('{$a}', res.totalcoins);
                        $('#pp-save-status').removeClass('text-muted').addClass('text-success')
                            .text(successMsg);
                        $('#btn-pp-restart, #btn-pp-exit').prop('disabled', false);
                    }
                })
                .fail(function() {
                    $('#pp-save-status').removeClass('text-muted').addClass('text-danger')
                        .text(strings.saveerror);
                    $('#btn-pp-restart, #btn-pp-exit').prop('disabled', false);
                });

            $('#btn-pp-restart').on('click', function() {
                $('#playerpuzzle-gameover').remove();
                me.scene.restart();
            });
            $('#btn-pp-exit').on('click', function() {
                window.location.href = M.cfg.wwwroot;
            });
        }
    }

    return CombatHandler;
});
