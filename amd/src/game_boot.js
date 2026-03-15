/**
 * Main bootloader for the PlayerPuzzle Phaser game.
 *
 * @module     mod_playerpuzzle/game_boot
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global Phaser */

define([
    'jquery',
    'core/notification'
], function($, notification) {
    'use strict';

    var startPhaser = function(gameConfig) {

        var preload = function() {
            this.load.image('bg', gameConfig.bgurl);
            this.load.image('boss', gameConfig.bossurl);
            for (var i = 0; i < 7; i++) {
                this.load.image('item' + i, gameConfig.spriteurls[i]);
            }
        };

        var create = function() {
            var containerDOM = $('#playerpuzzle-canvas-container');
            containerDOM.find('p').remove();

            containerDOM.css({
                'aspect-ratio': '9/16',
                'max-width': '540px',
                'margin': '0 auto'
            });

            var modalMoodle = $('#playerpuzzle-modal');
            containerDOM.append(modalMoodle);

            var me = this;
            this.add.image(270, 480, 'bg').setDisplaySize(540, 960);

            // --- SISTEMA DE TURNOS E STATUS ---
            this.turnoAtual = 'aluno';
            var danoBase = parseInt(gameConfig.bossdamage) || 10;

            // Status do Aluno
            this.alunoOuro = 0;
            this.alunoEscudo = 0;
            this.alunoMultiplicador = 1;
            this.alunoMana = 0; // Nova Barra Azul

            // Status do Chefe
            this.chefeEnvenenadoTurnos = 0;
            this.chefeMana = 0; // Nova Barra Azul

            this.maxBossHp = gameConfig.bosshp;
            this.currentHp = this.maxBossHp;

            this.bossSprite = this.add.image(270, 75, 'boss');
            this.bossSprite.setDisplaySize(100, 100);

            // Interface do Chefe
            this.bossHpBg = this.add.graphics().fillStyle(0x000000, 0.8).fillRect(120, 135, 300, 22);
            this.bossHpBar = this.add.graphics();
            this.bossHpText = this.add.text(270, 146, '', {fontSize: '15px', fill: '#ffffff', fontStyle: 'bold'}).setOrigin(0.5);

            this.bossManaBg = this.add.graphics().fillStyle(0x000000, 0.8).fillRect(120, 158, 300, 8);
            this.bossManaBar = this.add.graphics();

            this.txtVeneno = this.add.text(430, 146, '☠️', {fontSize: '16px'}).setOrigin(0.5).setAlpha(0);

            this.atualizarBarraBoss = function() {
                var pctHp = Math.max(0, me.currentHp / me.maxBossHp);
                me.bossHpBar.clear();
                me.bossHpBar.fillStyle(0xdd0000, 1).fillRect(122, 137, 296 * pctHp, 18);
                me.bossHpText.setText('Chefe: ' + Math.round(me.currentHp));

                var pctMana = Math.min(1, me.chefeMana / 100);
                me.bossManaBar.clear();
                me.bossManaBar.fillStyle(0x0088ff, 1).fillRect(122, 159, 296 * pctMana, 6);

                if (me.chefeEnvenenadoTurnos > 0) {
                    me.txtVeneno.setAlpha(1);
                } else {
                    me.txtVeneno.setAlpha(0);
                }
            };
            this.atualizarBarraBoss();

            // Interface do Aluno
            this.maxPlayerHp = 100;
            this.currentPlayerHp = this.maxPlayerHp;

            this.playerHpBg = this.add.graphics().fillStyle(0x000000, 0.8).fillRect(120, 175, 300, 22);
            this.playerHpBar = this.add.graphics();
            this.playerHpText = this.add.text(270, 186, '', {fontSize: '15px', fill: '#ffffff', fontStyle: 'bold'}).setOrigin(0.5);

            this.playerManaBg = this.add.graphics().fillStyle(0x000000, 0.8).fillRect(120, 198, 300, 8);
            this.playerManaBar = this.add.graphics();

            // Status Visuais do Aluno (Embaixo da barra de vida)
            var styleEscudo = {fontSize: '16px', fill: '#aaaaff', fontStyle: 'bold'};
            var styleOuro = {fontSize: '16px', fill: '#ffffaa', fontStyle: 'bold'};
            var styleEstrela = {fontSize: '16px', fill: '#ffddaa', fontStyle: 'bold'};

            this.txtEscudo = this.add.text(120, 215, '🛡️: 0', styleEscudo);
            this.txtOuro = this.add.text(270, 215, '🪙: 0', styleOuro).setOrigin(0.5, 0);
            this.txtEstrela = this.add.text(420, 215, '⭐x1.0', styleEstrela).setOrigin(1, 0);

            this.atualizarBarraAluno = function() {
                var pctHp = Math.max(0, me.currentPlayerHp / me.maxPlayerHp);
                me.playerHpBar.clear();
                me.playerHpBar.fillStyle(0x00cc00, 1).fillRect(122, 177, 296 * pctHp, 18);
                me.playerHpText.setText('Você: ' + Math.round(me.currentPlayerHp) + ' / ' + me.maxPlayerHp);

                var pctMana = Math.min(1, me.alunoMana / 100);
                me.playerManaBar.clear();
                me.playerManaBar.fillStyle(0x0088ff, 1).fillRect(122, 199, 296 * pctMana, 6);

                me.txtEscudo.setText('🛡️: ' + me.alunoEscudo);
                me.txtOuro.setText('🪙: ' + me.alunoOuro);
                me.txtEstrela.setText('⭐x' + me.alunoMultiplicador.toFixed(1));
            };
            this.atualizarBarraAluno();

            var btnFullscreen = this.add.text(520, 20, '[ Expandir ]', {
                fontSize: '20px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
            }).setOrigin(1, 0);

            btnFullscreen.setInteractive();
            btnFullscreen.on('pointerdown', function() {
                me.cameras.main.fadeOut(200, 0, 0, 0);
                me.time.delayedCall(200, function() {
                    if (me.scale.isFullscreen) {
                        me.scale.stopFullscreen();
                        btnFullscreen.setText('[ Expandir ]');
                    } else {
                        me.scale.startFullscreen();
                        btnFullscreen.setText('[ Encolher ]');
                    }
                    me.cameras.main.fadeIn(200, 0, 0, 0);
                });
            });

            var linhas = 8;
            var colunas = 8;
            var tamanhoPeca = 55;
            var offsetX = 77.5;
            var offsetY = 280;

            var graphics = this.add.graphics();
            var largTab = colunas * tamanhoPeca;
            var altTab = linhas * tamanhoPeca;

            var rx = offsetX - (tamanhoPeca / 2);
            var ry = offsetY - (tamanhoPeca / 2);

            graphics.fillStyle(0x000000, 0.85);
            graphics.fillRect(rx, ry, largTab, altTab);
            graphics.lineStyle(6, 0x111111, 1);
            graphics.strokeRect(rx, ry, largTab, altTab);

            graphics.lineStyle(2, 0x333333, 0.4);
            graphics.beginPath();

            for (var iGrade = 1; iGrade < linhas; iGrade++) {
                graphics.moveTo(rx, ry + (iGrade * tamanhoPeca));
                graphics.lineTo(rx + largTab, ry + (iGrade * tamanhoPeca));
                graphics.moveTo(rx + (iGrade * tamanhoPeca), ry);
                graphics.lineTo(rx + (iGrade * tamanhoPeca), ry + altTab);
            }
            graphics.strokePath();

            this.tabuleiro = [];
            this.pecaSelecionada = null;
            this.swipePeca = null;
            this.ultimaTroca = null;
            this.startX = 0;
            this.startY = 0;
            this.tempoUltimaAcao = 0;
            this.pecaComDica = null;

            var r, c, p1, p2, p3, peca, row, col, itemAleatorio, x, y, pecaCaindo, yInicio, yFim;

            this.verificarHorizontal = function(pecasDestruir) {
                for (r = 0; r < linhas; r++) {
                    for (c = 0; c < colunas - 2; c++) {
                        p1 = me.tabuleiro[r][c];
                        p2 = me.tabuleiro[r][c + 1];
                        p3 = me.tabuleiro[r][c + 2];

                        if (p1 && p2 && p3 && p1.tipo === p2.tipo && p2.tipo === p3.tipo) {
                            if (pecasDestruir.indexOf(p1) === -1) {
 pecasDestruir.push(p1);
}
                            if (pecasDestruir.indexOf(p2) === -1) {
 pecasDestruir.push(p2);
}
                            if (pecasDestruir.indexOf(p3) === -1) {
 pecasDestruir.push(p3);
}
                        }
                    }
                }
            };

            this.verificarVertical = function(pecasDestruir) {
                for (c = 0; c < colunas; c++) {
                    for (r = 0; r < linhas - 2; r++) {
                        p1 = me.tabuleiro[r][c];
                        p2 = me.tabuleiro[r + 1][c];
                        p3 = me.tabuleiro[r + 2][c];

                        if (p1 && p2 && p3 && p1.tipo === p2.tipo && p2.tipo === p3.tipo) {
                            if (pecasDestruir.indexOf(p1) === -1) {
 pecasDestruir.push(p1);
}
                            if (pecasDestruir.indexOf(p2) === -1) {
 pecasDestruir.push(p2);
}
                            if (pecasDestruir.indexOf(p3) === -1) {
 pecasDestruir.push(p3);
}
                        }
                    }
                }
            };

            this.encontrarJogada = function() {
                var isMatch = function(rowP, colP) {
                    var p = me.tabuleiro[rowP][colP];
                    if (!p) {
 return false;
}
                    var tipo = p.tipo,
countH = 1,
countV = 1,
tr, tc;

                    tc = colP - 1; while (tc >= 0 && me.tabuleiro[rowP][tc] && me.tabuleiro[rowP][tc].tipo === tipo) {
 countH++; tc--;
}
                    tc = colP + 1; while (tc < colunas && me.tabuleiro[rowP][tc] && me.tabuleiro[rowP][tc].tipo === tipo) {
 countH++; tc++;
}
                    if (countH >= 3) {
 return true;
}

                    tr = rowP - 1; while (tr >= 0 && me.tabuleiro[tr][colP] && me.tabuleiro[tr][colP].tipo === tipo) {
 countV++; tr--;
}
                    tr = rowP + 1; while (tr < linhas && me.tabuleiro[tr][colP] && me.tabuleiro[tr][colP].tipo === tipo) {
 countV++; tr++;
}
                    if (countV >= 3) {
 return true;
}
                    return false;
                };

                for (var scanR = 0; scanR < linhas; scanR++) {
                    for (var scanC = 0; scanC < colunas; scanC++) {
                        var temp;
                        if (scanC < colunas - 1) {
                            temp = me.tabuleiro[scanR][scanC].tipo;
                            me.tabuleiro[scanR][scanC].tipo = me.tabuleiro[scanR][scanC + 1].tipo;
                            me.tabuleiro[scanR][scanC + 1].tipo = temp;
                            var matchR = isMatch(scanR, scanC) || isMatch(scanR, scanC + 1);
                            temp = me.tabuleiro[scanR][scanC].tipo;
                            me.tabuleiro[scanR][scanC].tipo = me.tabuleiro[scanR][scanC + 1].tipo;
                            me.tabuleiro[scanR][scanC + 1].tipo = temp;
                            if (matchR) {
 return {p1: me.tabuleiro[scanR][scanC], p2: me.tabuleiro[scanR][scanC + 1]};
}
                        }
                        if (scanR < linhas - 1) {
                            temp = me.tabuleiro[scanR][scanC].tipo;
                            me.tabuleiro[scanR][scanC].tipo = me.tabuleiro[scanR + 1][scanC].tipo;
                            me.tabuleiro[scanR + 1][scanC].tipo = temp;
                            var matchD = isMatch(scanR, scanC) || isMatch(scanR + 1, scanC);
                            temp = me.tabuleiro[scanR][scanC].tipo;
                            me.tabuleiro[scanR][scanC].tipo = me.tabuleiro[scanR + 1][scanC].tipo;
                            me.tabuleiro[scanR + 1][scanC].tipo = temp;
                            if (matchD) {
 return {p1: me.tabuleiro[scanR][scanC], p2: me.tabuleiro[scanR + 1][scanC]};
}
                        }
                    }
                }
                return null;
            };

            this.temJogadaPossivel = function() {
                return me.encontrarJogada() !== null;
            };

            this.resetarDica = function() {
                me.tempoUltimaAcao = me.time.now;
                if (me.pecaComDica) {
                    me.tweens.killTweensOf([me.pecaComDica.p1, me.pecaComDica.p2]);
                    me.pecaComDica.p1.setDisplaySize(tamanhoPeca - 4, tamanhoPeca - 4);
                    me.pecaComDica.p2.setDisplaySize(tamanhoPeca - 4, tamanhoPeca - 4);
                    me.pecaComDica = null;
                }
            };

            this.verificarOciosidade = function() {
                if (!me.input.enabled || me.turnoAtual !== 'aluno') {
                    me.tempoUltimaAcao = me.time.now;
                    return;
                }
                if (me.pecaComDica === null && (me.time.now - me.tempoUltimaAcao > 5000)) {
                    var jogadaDica = me.encontrarJogada();
                    if (jogadaDica) {
                        me.pecaComDica = jogadaDica; // Agora o jogo guarda as duas peças!

                        // Faz as DUAS peças piscarem juntas
                        me.tweens.add({
                            targets: [jogadaDica.p1, jogadaDica.p2],
                            displayWidth: tamanhoPeca + 6, displayHeight: tamanhoPeca + 6,
                            yoyo: true, repeat: -1, duration: 400
                        });
                    }
                }
            };

            me.time.addEvent({delay: 1000, callback: me.verificarOciosidade, callbackScope: me, loop: true});

            this.embaralhar = function() {
                var aviso = me.add.text(270, 480, 'EMBARALHANDO...', {
                    fontSize: '32px', fill: '#ffffff', backgroundColor: '#000000',
                    align: 'center', fontStyle: 'bold', padding: {x: 20, y: 20}
                }).setOrigin(0.5).setDepth(100);

                var tipos = [];
                for (var scanR = 0; scanR < linhas; scanR++) {
                    for (var scanC = 0; scanC < colunas; scanC++) {
                        tipos.push(me.tabuleiro[scanR][scanC].tipo);
                    }
                }

                var temMatchInicial = function() {
                    var pecasDest = [];
                    me.verificarHorizontal(pecasDest);
                    me.verificarVertical(pecasDest);
                    return pecasDest.length > 0;
                };

                do {
                    Phaser.Utils.Array.Shuffle(tipos);
                    var idx = 0;
                    for (var r2 = 0; r2 < linhas; r2++) {
                        for (var c2 = 0; c2 < colunas; c2++) {
                            me.tabuleiro[r2][c2].tipo = tipos[idx];
                            me.tabuleiro[r2][c2].setTexture('item' + tipos[idx]);
                            idx++;
                        }
                    }
                } while (!me.temJogadaPossivel() || temMatchInicial());

                for (var r3 = 0; r3 < linhas; r3++) {
                    for (var c3 = 0; c3 < colunas; c3++) {
                        var pecaShuffle = me.tabuleiro[r3][c3];
                        pecaShuffle.alpha = 0;
                        me.tweens.add({
                            targets: pecaShuffle, alpha: 1, duration: 500, delay: Math.random() * 400
                        });
                    }
                }

                me.time.delayedCall(1200, function() {
                    aviso.destroy();
                    if (me.turnoAtual === 'aluno') {
                        me.input.enabled = true;
                        me.resetarDica();
                    } else {
                        me.executarTurnoChefe();
                    }
                });
            };

            this.mostrarTelaFim = function(vitoria) {
                me.input.enabled = false;
                me.add.graphics().fillStyle(0x000000, 0.8).fillRect(0, 0, 540, 960).setDepth(99);

                var msg = vitoria ? '🌟 VITÓRIA! 🌟' : '💀 DERROTA 💀';
                var cor = vitoria ? '#00ff00' : '#ff0000';

                var txt = me.add.text(270, 300, msg, {
                    fontSize: '46px', fill: cor, fontStyle: 'bold', align: 'center'
                }).setOrigin(0.5).setDepth(100);
                me.tweens.add({targets: txt, scaleX: 1.1, scaleY: 1.1, yoyo: true, repeat: -1, duration: 800});

                var statsStr = 'Ouro Coletado: ' + me.alunoOuro;
                statsStr += '\nMultiplicador Máx: x' + me.alunoMultiplicador.toFixed(1);

                me.add.text(270, 400, statsStr, {
                    fontSize: '24px', fill: '#ffffff', align: 'center'
                }).setOrigin(0.5).setDepth(100);

                var btnRestart = me.add.text(270, 550, '🎮 JOGAR NOVAMENTE', {
                    fontSize: '24px', fill: '#ffffff', backgroundColor: '#3366cc', padding: {x: 20, y: 15}, fontStyle: 'bold'
                }).setOrigin(0.5).setDepth(100).setInteractive();

                btnRestart.on('pointerdown', function() {
 me.scene.restart();
});

                var btnSair = me.add.text(270, 650, '🚪 SAIR DO JOGO', {
                    fontSize: '20px', fill: '#aaaaaa', backgroundColor: '#333333', padding: {x: 20, y: 15}
                }).setOrigin(0.5).setDepth(100).setInteractive();

                btnSair.on('pointerdown', function() {
 window.location.href = M.cfg.wwwroot;
});
            };

            this.verificarFimDeJogo = function() {
                if (me.currentHp <= 0) {
 me.mostrarTelaFim(true); return true;
}
                if (me.currentPlayerHp <= 0) {
 me.mostrarTelaFim(false); return true;
}
                return false;
            };

            this.executarTurnoChefe = function() {
                var jogada = me.encontrarJogada();
                if (jogada) {
                    me.tweens.add({
                        targets: me.bossSprite,
                        scaleX: 1.2, scaleY: 1.2, yoyo: true, duration: 300,
                        onComplete: function() {
 me.trocarPecas(jogada.p1, jogada.p2);
}
                    });
                } else {
                    me.embaralhar();
                }
            };

            // --- A FUNÇÃO ISOLADA DO MODAL DE PERGUNTA (Resolve a Complexidade) ---
            this.abrirModalPergunta = function(quemAtivou) {
                me.input.enabled = false;
                setTimeout(function() {
                    me.scene.pause();
                    var modalMoodle = $('#playerpuzzle-modal');

                    if (modalMoodle.length > 0) {
                        var perguntaSorteada = {name: "Aviso", questiontext: "Erro de perguntas."};
                        if (gameConfig.questions && gameConfig.questions.length > 0) {
                            var idx = Math.floor(Math.random() * gameConfig.questions.length);
                            perguntaSorteada = gameConfig.questions[idx];
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
                        }

                        if (quemAtivou === 'chefe') {
                            var avisoC = '<span class="text-danger fw-bold">👹 O Chefe ativou a pergunta!</span><br><br>';
                            textoDaPergunta = avisoC + textoDaPergunta;
                        }

                        $('#playerpuzzle-pergunta-texto').html(textoDaPergunta);
                        var containerRespostas = $('#playerpuzzle-respostas-container');
                        containerRespostas.empty();

                        var fecharModal = function() {
                            modalMoodle.removeClass('show').css('display', 'none');
                            me.scene.resume();
                            me.time.delayedCall(250, me.aplicarGravidade, [], me);
                        };

                        if (perguntaSorteada.answers && perguntaSorteada.answers.length > 0) {
                            perguntaSorteada.answers.forEach(function(resposta, idxHtml) {
                                var txtLimpo = resposta.answer.replace(/(<([^>]+)>)/gi, "");
                                var btnStr = '<button class="btn btn-outline-primary btn-lg mb-3 w-100">';
                                var btnHTML = $(btnStr + txtLimpo + '</button>');

                                if (quemAtivou === 'aluno') {
                                    btnHTML.on('click', function() {
                                        if (parseFloat(resposta.fraction) > 0) {
                                            me.currentHp -= (danoBase * 3 * me.alunoMultiplicador); // Dano massivo da magia
                                            if (me.currentHp < 0) {
 me.currentHp = 0;
}
                                            me.atualizarBarraBoss();
                                            me.bossSprite.setTint(0x0088ff);
                                            me.tweens.add({
                                                targets: me.bossSprite, y: me.bossSprite.y - 20,
                                                yoyo: true, duration: 150,
                                                onComplete: function() {
 me.bossSprite.clearTint();
}
                                            });
                                        } else {
                                            var danoErro = 30;
                                            if (me.alunoEscudo > 0) {
                                                if (danoErro >= me.alunoEscudo) {
                                                    danoErro -= me.alunoEscudo;
                                                    me.alunoEscudo = 0;
                                                } else {
                                                    me.alunoEscudo -= danoErro;
                                                    danoErro = 0;
                                                }
                                            }
                                            me.currentPlayerHp -= danoErro;
                                            if (me.currentPlayerHp < 0) {
 me.currentPlayerHp = 0;
}
                                            me.alunoMultiplicador = 1;
                                            me.atualizarBarraAluno();
                                            me.cameras.main.shake(250, 0.015);
                                        }
                                        $('#playerpuzzle-btn-fechar').text('Fechar');
                                        fecharModal();
                                    });
                                } else {
                                    btnHTML.prop('disabled', true);
                                    if (idxHtml === indexChefe) {
                                        if (chefeAcertou) {
                                            btnHTML.removeClass('btn-outline-primary').addClass('btn-danger text-white');
                                            btnHTML.html('<strong>✓ ' + txtLimpo + ' (O Chefe acertou!)</strong>');
                                        } else {
                                            btnHTML.removeClass('btn-outline-primary').addClass('btn-secondary text-white');
                                            btnHTML.html('<strong>✗ ' + txtLimpo + ' (O Chefe errou!)</strong>');
                                        }
                                    } else {
                                        btnHTML.removeClass('btn-outline-primary').addClass('btn-light');
                                    }
                                }
                                containerRespostas.append(btnHTML);
                            });

                            if (quemAtivou === 'chefe') {
                                var btnFechar = $('#playerpuzzle-btn-fechar');
                                var tempoRestante = 15;
                                btnFechar.show().text('Continuar (' + tempoRestante + 's)');

                                var executouAcao = false;
                                var timerChefe = setInterval(function() {
                                    if (executouAcao) {
 return;
}
                                    tempoRestante--;
                                    if (tempoRestante > 0) {
                                        btnFechar.text('Continuar (' + tempoRestante + 's)');
                                    } else {
                                        executarAcaoChefe();
                                    }
                                }, 1000);

                                var executarAcaoChefe = function() {
                                    if (executouAcao) {
 return;
}
                                    executouAcao = true;
                                    clearInterval(timerChefe);
                                    if (chefeAcertou) {
                                        var dChefe = danoBase * 3;
                                        if (me.alunoEscudo > 0) {
                                            if (dChefe >= me.alunoEscudo) {
                                                dChefe -= me.alunoEscudo;
                                                me.alunoEscudo = 0;
                                            } else {
                                                me.alunoEscudo -= dChefe;
                                                dChefe = 0;
                                            }
                                        }
                                        me.currentPlayerHp -= dChefe;
                                        if (me.currentPlayerHp < 0) {
 me.currentPlayerHp = 0;
}
                                        me.atualizarBarraAluno();
                                        setTimeout(function() {
 me.cameras.main.shake(300, 0.02);
}, 100);
                                    }
                                    btnFechar.text('Fechar');
                                    fecharModal();
                                };

                                btnFechar.off('click').on('click', executarAcaoChefe);

                            } else {
                                $('#playerpuzzle-btn-fechar').text('Fechar').show().off('click').on('click', fecharModal);
                            }

                        } else {
                            containerRespostas.append('<p class="text-danger">Vazio</p>');
                            $('#playerpuzzle-btn-fechar').text('Fechar').show().off('click').on('click', fecharModal);
                        }

                        modalMoodle.addClass('show').css('display', 'block');

                    } else {
                        me.scene.resume();
                        me.time.delayedCall(250, me.aplicarGravidade, [], me);
                    }
                }, 250);
            };

            this.aplicarGravidade = function() {
                for (col = 0; col < colunas; col++) {
                    for (row = linhas - 1; row >= 0; row--) {
                        if (me.tabuleiro[row][col] !== null) {
 continue;
}

                        for (r = row - 1; r >= 0; r--) {
                            if (me.tabuleiro[r][col] !== null) {
                                pecaCaindo = me.tabuleiro[r][col];
                                me.tabuleiro[row][col] = pecaCaindo;
                                me.tabuleiro[r][col] = null;
                                pecaCaindo.row = row;

                                me.tweens.add({
                                    targets: pecaCaindo, y: offsetY + (row * tamanhoPeca),
                                    duration: 250, ease: 'Quad.easeIn'
                                });
                                break;
                            }
                        }
                    }
                }

                for (col = 0; col < colunas; col++) {
                    for (row = 0; row < linhas; row++) {
                        if (me.tabuleiro[row][col] !== null) {
 continue;
}

                        itemAleatorio = Math.floor(Math.random() * 7);
                        x = offsetX + (col * tamanhoPeca);
                        yInicio = offsetY - (tamanhoPeca * (linhas - row));
                        yFim = offsetY + (row * tamanhoPeca);

                        peca = me.add.image(x, yInicio, 'item' + itemAleatorio);
                        peca.setDisplaySize(tamanhoPeca - 4, tamanhoPeca - 4);
                        peca.tipo = itemAleatorio;
                        peca.row = row;
                        peca.col = col;

                        peca.setInteractive();
                        peca.on('pointerdown', me.iniciarSwipe);

                        me.tabuleiro[row][col] = peca;

                        me.tweens.add({
                            targets: peca, y: yFim, duration: 400, ease: 'Bounce.easeOut'
                        });
                    }
                }

                me.time.delayedCall(500, me.verificarCombinacoes, [], me);
            };

            // FUNÇÃO AUXILIAR PARA LIMPAR A COMPLEXIDADE
            this.processarEfeitos = function(pecasDestruidas) {
                var ativouPergunta = false;
                var quemAtivou = null;
                var danoCausado = 0;

                for (var i = 0; i < pecasDestruidas.length; i++) {
                    var peca = pecasDestruidas[i];

                   if (me.turnoAtual === 'aluno') {
                        if (peca.tipo === 6) {
                            me.alunoOuro += 10;
                        } else if (peca.tipo === 5) {
                            me.currentPlayerHp = Math.min(me.maxPlayerHp, me.currentPlayerHp + 5);
                        } else if (peca.tipo === 0) {
                            me.alunoMultiplicador += 0.1;
                        } else if (peca.tipo === 4) {
                            me.alunoEscudo += 5;
                        } else if (peca.tipo === 1) {
                            me.chefeEnvenenadoTurnos += 3;
                        } else if (peca.tipo === 2) {
                            me.alunoMana += 20;
                        }
                    } else {
                        if (peca.tipo === 2) {
                            me.chefeMana += 20;
                        }
                    }

                    if (peca.tipo === 3) {
 danoCausado += (danoBase / 3);
} // 3: Espadas (Dano direto)

                    me.tabuleiro[peca.row][peca.col] = null;
                    me.tweens.add({
                        targets: peca, scaleX: 0, scaleY: 0, duration: 200,
                        onComplete: function(tween, targets) {
 targets[0].destroy();
}
                    });
                }

                me.alunoMultiplicador = Math.round(me.alunoMultiplicador * 10) / 10;

                if (me.alunoMana >= 100) {
                    me.alunoMana -= 100;
                    ativouPergunta = true;
                    quemAtivou = 'aluno';
                } else if (me.chefeMana >= 100) {
                    me.chefeMana -= 100;
                    ativouPergunta = true;
                    quemAtivou = 'chefe';
                }

                me.atualizarBarraAluno();
                me.atualizarBarraBoss();

                return {dano: danoCausado, pergunta: ativouPergunta, quem: quemAtivou};
            };

            this.verificarCombinacoes = function() {
                var pecasParaDestruir = [];
                me.verificarHorizontal(pecasParaDestruir);
                me.verificarVertical(pecasParaDestruir);

                // SE NÃO HOUVER COMBINAÇÃO NENHUMA
                if (pecasParaDestruir.length === 0) {
                    // MÁGICA: A jogada foi do jogador e falhou? Reverte!
                    if (me.ultimaTroca !== null) {
                        me.trocarPecas(me.ultimaTroca.p1, me.ultimaTroca.p2, true);
                        return;
                    }

                    if (me.verificarFimDeJogo()) {
 return;
}

                    if (me.turnoAtual === 'aluno') {
                        me.turnoAtual = 'chefe';
                        me.input.enabled = false;

                        if (me.chefeEnvenenadoTurnos > 0) {
                            me.currentHp = Math.max(0, me.currentHp - 5);
                            me.chefeEnvenenadoTurnos--;
                            me.atualizarBarraBoss();
                            me.bossSprite.setTint(0xff00ff);
                            me.time.delayedCall(300, function() {
 me.bossSprite.clearTint();
});
                        }

                        me.time.delayedCall(800, me.executarTurnoChefe, [], me);
                    } else {
                        me.turnoAtual = 'aluno';
                        if (!me.temJogadaPossivel()) {
                            me.embaralhar();
                        } else {
                            me.input.enabled = true;
                            me.resetarDica();
                        }
                    }
                    return;
                }

                // SE HOUVER COMBINAÇÃO VÁLIDA: Limpa a memória para não reverter
                me.ultimaTroca = null;
                me.turnoDoChefePendente = true;

                var efeitos = me.processarEfeitos(pecasParaDestruir);
                var dCausado = efeitos.dano;

                // ... (O resto do código que já lá está continua igual para baixo) ...

                if (dCausado > 0) {
                    dCausado = Math.round(dCausado * (me.turnoAtual === 'aluno' ? me.alunoMultiplicador : 1));

                    if (me.turnoAtual === 'aluno') {
                        // Mágica Matemática 2: Sem 'if' para vida negativa!
                        me.currentHp = Math.max(0, me.currentHp - dCausado);
                        me.atualizarBarraBoss();
                        me.bossSprite.setTint(0xff0000);
                        me.time.delayedCall(200, function() {
 me.bossSprite.clearTint();
});
                    } else {
                        var danoBloqueado = Math.min(dCausado, me.alunoEscudo);
                        me.alunoEscudo -= danoBloqueado;
                        dCausado -= danoBloqueado;

                        // Mágica Matemática 3
                        me.currentPlayerHp = Math.max(0, me.currentPlayerHp - dCausado);
                        me.atualizarBarraAluno();
                        me.cameras.main.shake(250, 0.015);
                    }
                }

                if (efeitos.pergunta) {
                    me.abrirModalPergunta(efeitos.quem);
                } else {
                    me.time.delayedCall(250, me.aplicarGravidade, [], me);
                }
            };

            this.trocarPecas = function(peca1, peca2, isRevert) {
                me.input.enabled = false;

                var tempRow = peca1.row;
                var tempCol = peca1.col;

                me.tabuleiro[peca1.row][peca1.col] = peca2;
                me.tabuleiro[peca2.row][peca2.col] = peca1;

                peca1.row = peca2.row;
                peca1.col = peca2.col;
                peca2.row = tempRow;
                peca2.col = tempCol;

                // Guarda a jogada na memória (a não ser que já estejamos a reverter!)
                if (!isRevert) {
                    me.ultimaTroca = {p1: peca1, p2: peca2};
                } else {
                    me.ultimaTroca = null;
                }

                me.tweens.add({targets: peca1, x: peca2.x, y: peca2.y, duration: 200});
                me.tweens.add({
                    targets: peca2, x: peca1.x, y: peca1.y, duration: 200,
                    onComplete: function() {
                        if (!isRevert) {
                            me.verificarCombinacoes();
                        } else {
                            // Se foi uma reversão, devolve o controlo ao aluno!
                            me.input.enabled = true;
                            me.resetarDica();
                        }
                    }
                });
            };

            this.lidarComClique = function(pecaClicada) {
                if (me.turnoAtual !== 'aluno') {
 return;
}

                if (me.pecaSelecionada === null) {
                    me.pecaSelecionada = pecaClicada;
                    pecaClicada.setTint(0xaaaaaa);
                } else {
                    var p1 = me.pecaSelecionada;
                    var p2 = pecaClicada;

                    p1.clearTint();
                    me.pecaSelecionada = null;

                    var isAdjacente = Math.abs(p1.row - p2.row) + Math.abs(p1.col - p2.col) === 1;

                    if (isAdjacente) {
                        me.trocarPecas(p1, p2);
                    } else if (p1 !== p2) {
                        me.pecaSelecionada = p2;
                        p2.setTint(0xaaaaaa);
                    }
                }
            };

            this.iniciarSwipe = function(pointer) {
                if (me.turnoAtual !== 'aluno') {
 return;
}
                me.resetarDica();
                me.swipePeca = this;
                me.startX = pointer.x;
                me.startY = pointer.y;
                this.setTint(0xdddddd);
            };

            for (row = 0; row < linhas; row++) {
                this.tabuleiro[row] = [];
                for (col = 0; col < colunas; col++) {
                    itemAleatorio = Math.floor(Math.random() * 7);
                    x = offsetX + (col * tamanhoPeca);
                    y = offsetY + (row * tamanhoPeca);

                    peca = this.add.image(x, y, 'item' + itemAleatorio);
                    peca.setDisplaySize(tamanhoPeca - 4, tamanhoPeca - 4);
                    peca.tipo = itemAleatorio;
                    peca.row = row;
                    peca.col = col;

                    peca.setInteractive();
                    peca.on('pointerdown', me.iniciarSwipe);

                    this.tabuleiro[row][col] = peca;
                }
            }

            me.input.on('pointerup', function(pointer) {
                // Se não for o turno do aluno ou não houver peça, sai imediatamente (achatamento)
                if (me.turnoAtual !== 'aluno' || me.swipePeca === null) {
 return;
}

                me.swipePeca.clearTint();

                var dx = pointer.x - me.startX;
                var dy = pointer.y - me.startY;
                var threshold = 20;

                // Se o movimento for muito curto, trata como CLIQUE normal e sai
                if (Math.abs(dx) <= threshold && Math.abs(dy) <= threshold) {
                    me.lidarComClique(me.swipePeca);
                    me.swipePeca = null;
                    return;
                }

                // Se chegou aqui, foi um SWIPE válido
                var tRow = me.swipePeca.row;
                var tCol = me.swipePeca.col;

                if (Math.abs(dx) > Math.abs(dy)) {
                    // Operador ternário substitui o if/else aninhado
                    tCol += (dx > 0) ? 1 : -1;
                } else {
                    tRow += (dy > 0) ? 1 : -1;
                }

                if (tRow >= 0 && tRow < linhas && tCol >= 0 && tCol < colunas) {
                    var pAlvo = me.tabuleiro[tRow][tCol];
                    if (pAlvo) {
 me.trocarPecas(me.swipePeca, pAlvo);
}
                }

                me.swipePeca = null;
            });

            me.input.enabled = false;
            me.time.delayedCall(500, me.verificarCombinacoes, [], me);
            me.tempoUltimaAcao = me.time.now;
        };

        var config = {
            type: Phaser.AUTO,
            parent: 'playerpuzzle-canvas-container',
            backgroundColor: '#1a1a1a',
            scale: {
                mode: Phaser.Scale.FIT,
                autoCenter: Phaser.Scale.CENTER_BOTH,
                width: 540,
                height: 960,
                fullscreenTarget: document.getElementById('playerpuzzle-canvas-container')
            },
            scene: {
                preload: preload,
                create: create
            }
        };

        new Phaser.Game(config);
    };

    return {
        init: function() {
            $(document).ready(function() {
                try {
                    var container = document.getElementById('playerpuzzle-canvas-container');
                    var configStr = container.getAttribute('data-config');
                    if (!configStr) {
 throw new Error('Game configuration is missing from HTML.');
}
                    var config = JSON.parse(configStr);

                    require(['Phaser'], function(PhaserObj) {
                        if (PhaserObj) {
 window.Phaser = PhaserObj;
}
                        startPhaser(config);
                    }, function(erro) {
                        var eMsg = '<p class="text-center text-danger p-5 mt-5">';
                        eMsg += 'Erro crítico: RequireJS não encontrou o Phaser. ';
                        eMsg += 'Verifique o console.</p>';
                        $('#playerpuzzle-canvas-container').html(eMsg);
                        window.console.error("RequireJS Error:", erro);
                    });

                } catch (error) {
                    notification.exception(error);
                }
            });
        }
    };
});
