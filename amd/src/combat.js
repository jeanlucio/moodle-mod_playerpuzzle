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
        constructor(scene, gameConfig) {
            this.scene = scene;
            this.gameConfig = gameConfig;

            this.danoBase = parseInt(gameConfig.bossdamage) || 10;
            this.turnoAtual = 'aluno';

            this.alunoOuro = 0;
            this.alunoEscudo = 0;
            this.alunoMultiplicador = 1;
            this.alunoMana = 0;
            this.maxPlayerHp = 100;
            this.currentPlayerHp = this.maxPlayerHp;

            this.chefeEnvenenadoTurnos = 0;
            this.chefeMana = 0;
            this.maxBossHp = gameConfig.bosshp;
            this.currentHp = this.maxBossHp;
        }

        processarEfeitos(pecasDestruidas) {
            var me = this.scene;
            var ativouPergunta = false;
            var quemAtivou = null;
            var danoCausado = 0;

            me.sfxMatch.play();

            for (var i = 0; i < pecasDestruidas.length; i++) {
                var peca = pecasDestruidas[i];
                if (this.turnoAtual === 'aluno') {
                    me.sfxHit.play();
                    if (peca.tipo === 6) {
                        this.alunoOuro += 10;
                    } else if (peca.tipo === 5) {
                        this.currentPlayerHp = Math.min(this.maxPlayerHp, this.currentPlayerHp + 5);
                    } else if (peca.tipo === 0) {
                        this.alunoMultiplicador += 0.1;
                    } else if (peca.tipo === 4) {
                        this.alunoEscudo += 5;
                    } else if (peca.tipo === 1) {
                        this.chefeEnvenenadoTurnos += 3;
                    } else if (peca.tipo === 2) {
                        this.alunoMana += 20;
                    }
                } else {
                    if (peca.tipo === 2) {
                        this.chefeMana += 20;
                    }
                }

                if (peca.tipo === 3) {
                    danoCausado += (this.danoBase / 3);
                }

                me.board.tabuleiro[peca.row][peca.col] = null;
                me.tweens.add({
                    targets: peca, scaleX: 0, scaleY: 0, duration: 200,
                    onComplete: function(tween, targets) {
                        targets[0].destroy();
                    }
                });
            }

            this.alunoMultiplicador = Math.round(this.alunoMultiplicador * 10) / 10;
            if (this.alunoMana >= 100) {
                this.alunoMana -= 100;
                ativouPergunta = true;
                quemAtivou = 'aluno';
            } else if (this.chefeMana >= 100) {
                this.chefeMana -= 100;
                ativouPergunta = true;
                quemAtivou = 'chefe';
            }

            this.atualizarUI();
            return {dano: danoCausado, pergunta: ativouPergunta, quem: quemAtivou};
        }

        atualizarUI() {
            this.scene.ui.atualizarBarraAluno(
                this.currentPlayerHp, this.maxPlayerHp, this.alunoMana,
                this.alunoEscudo, this.alunoOuro, this.alunoMultiplicador
            );
            this.scene.ui.atualizarBarraBoss(
                this.currentHp, this.maxBossHp, this.chefeMana, this.chefeEnvenenadoTurnos
            );
        }

        aplicarDanoBoss(dCausado) {
            var me = this.scene;
            this.currentHp = Math.max(0, this.currentHp - dCausado);
            this.atualizarUI();
            me.ui.bossSprite.setTint(0xff0000);
            me.time.delayedCall(200, function() {
                me.ui.bossSprite.clearTint();
            });
        }

        aplicarDanoAluno(dCausado) {
            var me = this.scene;
            var danoBloqueado = Math.min(dCausado, this.alunoEscudo);
            this.alunoEscudo -= danoBloqueado;
            dCausado -= danoBloqueado;

            this.currentPlayerHp = Math.max(0, this.currentPlayerHp - dCausado);
            this.atualizarUI();
            me.cameras.main.shake(250, 0.015);
        }

        passarTurnoParaChefe() {
            var me = this.scene;
            this.turnoAtual = 'chefe';
            me.input.enabled = false;

            if (this.chefeEnvenenadoTurnos > 0) {
                this.currentHp = Math.max(0, this.currentHp - 5);
                this.chefeEnvenenadoTurnos--;
                this.atualizarUI();
                me.ui.bossSprite.setTint(0xff00ff);
                me.time.delayedCall(300, function() {
                    me.ui.bossSprite.clearTint();
                });
            }

            me.time.delayedCall(800, this.executarTurnoChefe, [], this);
        }

        executarTurnoChefe() {
            var me = this.scene;
            var jogada = me.board.encontrarJogada();

            if (jogada) {
                me.tweens.add({
                    targets: me.ui.bossSprite,
                    scaleX: 1.2, scaleY: 1.2, yoyo: true, duration: 300,
                    onComplete: function() {
                        me.board.trocarPecas(jogada.p1, jogada.p2);
                    }
                });
            } else {
                me.board.embaralhar();
            }
        }

        passarTurnoParaAluno() {
            this.turnoAtual = 'aluno';
        }

        verificarFimDeJogo() {
            if (this.currentHp <= 0) {
                this.mostrarTelaFim(true);
                return true;
            }
            if (this.currentPlayerHp <= 0) {
                this.mostrarTelaFim(false);
                return true;
            }
            return false;
        }

        abrirModalPergunta(quemAtivou) {
            var me = this.scene;
            var ctx = this;
            me.input.enabled = false;

            setTimeout(function() {
                me.scene.pause();
                var modalMoodle = $('#playerpuzzle-modal');

                if (modalMoodle.length > 0) {
                    var perguntaSorteada = {name: 'Notice', questiontext: 'Question error.'};
                    if (ctx.gameConfig.questions && ctx.gameConfig.questions.length > 0) {
                        var idx = Math.floor(Math.random() * ctx.gameConfig.questions.length);
                        perguntaSorteada = ctx.gameConfig.questions[idx];
                    }

                    var textoDaPergunta = perguntaSorteada.questiontext ?
                        perguntaSorteada.questiontext : perguntaSorteada.name;

                    var respCertas = [];
                    var respErradas = [];
                    if (perguntaSorteada.answers) {
                        perguntaSorteada.answers.forEach(function(r, indexR) {
                            if (parseFloat(r.fraction) > 0) {
                                respCertas.push(indexR);
                            } else {
                                respErradas.push(indexR);
                            }
                        });
                    }

                    var chefeAcertou = (Math.random() > 0.3);
                    if (respErradas.length === 0) {
                        chefeAcertou = true;
                    }
                    if (respCertas.length === 0) {
                        chefeAcertou = false;
                    }

                    var indexChefe = -1;
                    if (quemAtivou === 'chefe') {
                        if (chefeAcertou && respCertas.length > 0) {
                            indexChefe = respCertas[Math.floor(Math.random() * respCertas.length)];
                        } else if (!chefeAcertou && respErradas.length > 0) {
                            indexChefe = respErradas[Math.floor(Math.random() * respErradas.length)];
                        }

                        var avisoC = '<span class="text-danger fw-bold">👹 The boss triggered a question!</span><br><br>';
                        textoDaPergunta = avisoC + textoDaPergunta;
                    }

                    $('#playerpuzzle-pergunta-texto').html(textoDaPergunta);
                    var containerRespostas = $('#playerpuzzle-respostas-container');
                    containerRespostas.empty();
                    $('#playerpuzzle-btn-fechar').hide().off('click');
                    $('#playerpuzzle-btn-pular').hide().off('click');

                    var fecharModal = function() {
                        modalMoodle.removeClass('show').css('display', 'none');
                        me.scene.resume();
                        me.time.delayedCall(250, me.board.aplicarGravidade, [], me.board);
                    };

                    if (perguntaSorteada.answers && perguntaSorteada.answers.length > 0) {
                        var btnClass = ctx.gameConfig.mobile
                            ? 'btn btn-outline-primary w-100 pp-answer-btn'
                            : 'btn btn-outline-primary btn-lg mb-3 w-100';

                        if (quemAtivou === 'aluno') {
                            $('#playerpuzzle-btn-pular').show().on('click', fecharModal);
                            $('#playerpuzzle-btn-fechar').text('⚔️ Atacar!')
                                .prop('disabled', true).show();

                            var respostaSelecionada = null;

                            perguntaSorteada.answers.forEach(function(resposta) {
                                var txtLimpo = resposta.answer.replace(/(<([^>]+)>)/gi, '');
                                var btnHTML = $('<button class="' + btnClass
                                    + '" data-fraction="' + resposta.fraction + '">'
                                    + txtLimpo + '</button>');

                                btnHTML.on('click', function() {
                                    containerRespostas.find('button')
                                        .removeClass('btn-warning')
                                        .addClass('btn-outline-primary');
                                    $(this).removeClass('btn-outline-primary').addClass('btn-warning');
                                    respostaSelecionada = resposta;
                                    $('#playerpuzzle-btn-fechar').prop('disabled', false);
                                });

                                containerRespostas.append(btnHTML);
                            });

                            $('#playerpuzzle-btn-fechar').off('click').on('click', function() {
                                if (!respostaSelecionada) {
                                    return;
                                }
                                containerRespostas.find('button').prop('disabled', true);
                                $('#playerpuzzle-btn-pular').hide();
                                $('#playerpuzzle-btn-fechar').prop('disabled', true);

                                var feedbackMsg;
                                if (parseFloat(respostaSelecionada.fraction) > 0) {
                                    containerRespostas.find('.btn-warning')
                                        .removeClass('btn-warning').addClass('btn-success text-white');
                                    ctx.aplicarDanoBoss(ctx.danoBase * 3 * ctx.alunoMultiplicador);
                                    me.ui.bossSprite.setTint(0x0088ff);
                                    me.tweens.add({
                                        targets: me.ui.bossSprite, y: me.ui.bossSprite.y - 20,
                                        yoyo: true, duration: 150,
                                        onComplete: function() {
                                            me.ui.bossSprite.clearTint();
                                        }
                                    });
                                    feedbackMsg = '<div class="alert alert-success mt-2 mb-0">'
                                        + '<strong>✓ Correct!</strong> The boss takes damage!</div>';
                                } else {
                                    containerRespostas.find('.btn-warning')
                                        .removeClass('btn-warning').addClass('btn-danger text-white');
                                    containerRespostas.find('[data-fraction]').each(function() {
                                        if (parseFloat($(this).data('fraction')) > 0) {
                                            $(this).removeClass('btn-outline-primary')
                                                .addClass('btn-success text-white');
                                        }
                                    });
                                    ctx.aplicarDanoAluno(30);
                                    ctx.alunoMultiplicador = 1;
                                    ctx.atualizarUI();
                                    feedbackMsg = '<div class="alert alert-danger mt-2 mb-0">'
                                        + '<strong>✗ Wrong!</strong> You take 30 damage!</div>';
                                }

                                containerRespostas.append(feedbackMsg);
                                $('#playerpuzzle-btn-fechar').text('Continuar')
                                    .prop('disabled', false).off('click').on('click', fecharModal);
                            });

                        } else {
                            perguntaSorteada.answers.forEach(function(resposta, idxHtml) {
                                var txtLimpo = resposta.answer.replace(/(<([^>]+)>)/gi, '');
                                var btnHTML = $('<button class="' + btnClass
                                    + '" data-fraction="' + resposta.fraction + '">'
                                    + txtLimpo + '</button>');

                                btnHTML.prop('disabled', true);
                                if (idxHtml === indexChefe) {
                                    if (chefeAcertou) {
                                        btnHTML.removeClass('btn-outline-primary')
                                            .addClass('btn-danger text-white');
                                        btnHTML.html('<strong>✓ ' + txtLimpo
                                            + ' (Boss answered correctly!)</strong>');
                                    } else {
                                        btnHTML.removeClass('btn-outline-primary')
                                            .addClass('btn-secondary text-white');
                                        btnHTML.html('<strong>✗ ' + txtLimpo
                                            + ' (Boss answered incorrectly!)</strong>');
                                    }
                                } else {
                                    btnHTML.removeClass('btn-outline-primary').addClass('btn-light');
                                }
                                containerRespostas.append(btnHTML);
                            });

                            var feedbackChefe;
                            if (chefeAcertou) {
                                ctx.aplicarDanoAluno(ctx.danoBase * 3);
                                feedbackChefe = '<div class="alert alert-danger mt-2 mb-0">'
                                    + '<strong>💥 The boss answered correctly!</strong> You take '
                                    + (ctx.danoBase * 3) + ' damage!</div>';
                            } else {
                                feedbackChefe = '<div class="alert alert-success mt-2 mb-0">'
                                    + '<strong>😅 The boss answered incorrectly!</strong>'
                                    + ' Lucky escape!</div>';
                            }
                            containerRespostas.append(feedbackChefe);
                            $('#playerpuzzle-btn-fechar').text('Continuar').show()
                                .off('click').on('click', fecharModal);
                        }

                    } else {
                        containerRespostas.append('<p class="text-danger">No answers available.</p>');
                        $('#playerpuzzle-btn-fechar').text('Continuar').show()
                            .off('click').on('click', fecharModal);
                    }

                    modalMoodle.addClass('show').css('display', 'block');
                } else {
                    me.scene.resume();
                    me.time.delayedCall(250, me.board.aplicarGravidade, [], me.board);
                }
            }, 250);
        }

        mostrarTelaFim(vitoria) {
            var me = this.scene;
            me.input.enabled = false;
            me.add.graphics().fillStyle(0x000000, 0.85).fillRect(0, 0, me.ui.L.w, me.ui.L.h).setDepth(99);

            var msg = vitoria ? '🌟 VICTORY! 🌟' : '💀 DEFEAT 💀';
            var cor = vitoria ? 'text-success' : 'text-danger';

            var statsStr = '<p class="lead text-white mb-4">Coins collected: ';
            statsStr += '<strong class="text-warning">' + this.alunoOuro + '</strong><br>';
            statsStr += 'Max multiplier: <strong class="text-info">x' + this.alunoMultiplicador.toFixed(1) + '</strong></p>';
            statsStr += '<p id="pp-save-status" class="text-muted small">Saving progress...</p>';

            var overlayHtml = '<div id="playerpuzzle-gameover" ' +
                'class="d-flex flex-column justify-content-center align-items-center" ' +
                'style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1000; text-align: center;">';
            overlayHtml += '<h1 class="display-4 fw-bold ' + cor + ' mb-3">' + msg + '</h1>' + statsStr;
            overlayHtml += '<div class="mt-2 d-flex flex-wrap justify-content-center gap-3">';
            overlayHtml += '<button id="btn-pp-restart" disabled ' +
                'class="btn btn-primary btn-lg fw-bold shadow">🎮 Play again</button>';
            overlayHtml += '<button id="btn-pp-exit" disabled ' +
                'class="btn btn-secondary btn-lg fw-bold shadow">🚪 Exit game</button>';
            overlayHtml += '</div></div>';

            $('#playerpuzzle-canvas-container').css('position', 'relative').append(overlayHtml);

            var postData = {
                cmid: this.gameConfig.cmid,
                sesskey: M.cfg.sesskey,
                ouro: this.alunoOuro,
                vitoria: vitoria ? 1 : 0,
                dano: this.maxBossHp - this.currentHp
            };

            $.post(M.cfg.wwwroot + '/mod/playerpuzzle/ajax.php', postData)
                .done(function(respostaStr) {
                    var res = JSON.parse(respostaStr);
                    if (res.status === 'success') {
                        var msgSucesso = 'Progress saved! (Total coins: ' + res.totalcoins + ')';
                        $('#pp-save-status').removeClass('text-muted').addClass('text-success').text(msgSucesso);
                        $('#btn-pp-restart, #btn-pp-exit').prop('disabled', false);
                    }
                })
                .fail(function() {
                    $('#pp-save-status').removeClass('text-muted').addClass('text-danger').text('Error saving to server.');
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
