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
            this.currentHp = gameConfig.bosshp;

            this.bossSprite = this.add.image(270, 120, 'boss');
            this.bossSprite.setDisplaySize(160, 160);

            this.hpText = this.add.text(270, 230, 'Boss HP: ' + this.currentHp, {
                fontSize: '34px', fill: '#ff0000', fontStyle: 'bold'
            }).setOrigin(0.5);

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
            var offsetY = 320;

            // --- NOVO: DESENHANDO A GRADE VISUAL DO TABULEIRO ---
            var graphics = this.add.graphics();
            var larguraTabuleiro = colunas * tamanhoPeca;
            var alturaTabuleiro = linhas * tamanhoPeca;

            // 1. Fundo do tabuleiro (Preto translúcido para destacar as pedras)
            graphics.fillStyle(0x000000, 0.4);
            graphics.fillRect(offsetX - (tamanhoPeca / 2), offsetY - (tamanhoPeca / 2), larguraTabuleiro, alturaTabuleiro);

            // 2. Borda externa do tabuleiro
            graphics.lineStyle(4, 0x444444, 0.8);
            graphics.strokeRect(offsetX - (tamanhoPeca / 2), offsetY - (tamanhoPeca / 2), larguraTabuleiro, alturaTabuleiro);

            // 3. Linhas internas (A grelha)
            graphics.lineStyle(2, 0x333333, 0.5);
            graphics.beginPath(); // Segurança para evitar falhas de renderização

            var gridX = offsetX - (tamanhoPeca / 2);
            var gridY = offsetY - (tamanhoPeca / 2);

            for (var iGrade = 1; iGrade < linhas; iGrade++) {
                // Linhas horizontais
                graphics.moveTo(gridX, gridY + (iGrade * tamanhoPeca));
                graphics.lineTo(gridX + larguraTabuleiro, gridY + (iGrade * tamanhoPeca));
                // Linhas verticais
                graphics.moveTo(gridX + (iGrade * tamanhoPeca), gridY);
                graphics.lineTo(gridX + (iGrade * tamanhoPeca), gridY + alturaTabuleiro);
            }
            graphics.strokePath();
            // ---------------------------------------------------

            this.tabuleiro = [];
            this.pecaSelecionada = null;
            this.swipePeca = null;
            this.startX = 0;
            this.startY = 0;

            this.tempoUltimaAcao = 0;
            this.pecaComDica = null;

            var r, c, p1, p2, p3, iLoop, peca, row, col, itemAleatorio, x, y, pecaCaindo, yInicio, yFim;

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

            this.obterDicaDeJogada = function() {
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
 return me.tabuleiro[scanR][scanC];
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
 return me.tabuleiro[scanR][scanC];
}
                        }
                    }
                }
                return null;
            };

            this.temJogadaPossivel = function() {
                return me.obterDicaDeJogada() !== null;
            };

            this.resetarDica = function() {
                me.tempoUltimaAcao = me.time.now;
                if (me.pecaComDica) {
                    me.tweens.killTweensOf(me.pecaComDica);
                    me.pecaComDica.setDisplaySize(tamanhoPeca - 4, tamanhoPeca - 4);
                    me.pecaComDica = null;
                }
            };

            this.verificarOciosidade = function() {
                if (!me.input.enabled) {
                    me.tempoUltimaAcao = me.time.now;
                    return;
                }
                if (me.pecaComDica === null && (me.time.now - me.tempoUltimaAcao > 5000)) {
                    var pecaDica = me.obterDicaDeJogada();
                    if (pecaDica) {
                        me.pecaComDica = pecaDica;
                        me.tweens.add({
                            targets: pecaDica,
                            displayWidth: tamanhoPeca + 6,
                            displayHeight: tamanhoPeca + 6,
                            yoyo: true,
                            repeat: -1,
                            duration: 400
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
                    me.input.enabled = true;
                    me.resetarDica();
                });
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

            this.verificarCombinacoes = function() {
                var pecasParaDestruir = [];
                me.verificarHorizontal(pecasParaDestruir);
                me.verificarVertical(pecasParaDestruir);

                if (pecasParaDestruir.length > 0) {
                    var ativouPergunta = false;
                    var danoCausado = 0;

                    for (iLoop = 0; iLoop < pecasParaDestruir.length; iLoop++) {
                        peca = pecasParaDestruir[iLoop];
                        if (peca.tipo === 2) {
 ativouPergunta = true;
} else if (peca.tipo === 3) {
 danoCausado += (gameConfig.bossdamage / 3);
}

                        me.tabuleiro[peca.row][peca.col] = null;
                        me.tweens.add({
                            targets: peca, scaleX: 0, scaleY: 0, duration: 200,
                            onComplete: function(tween, targets) {
 targets[0].destroy();
}
                        });
                    }

                    if (danoCausado > 0) {
                        danoCausado = Math.round(danoCausado);
                        me.currentHp -= danoCausado;
                        if (me.currentHp < 0) {
 me.currentHp = 0;
}
                        me.hpText.setText('Boss HP: ' + me.currentHp);

                        me.bossSprite.setTint(0xff0000);
                        me.time.delayedCall(200, function() {
 me.bossSprite.clearTint();
});

                        me.tweens.add({
                            targets: me.bossSprite, x: me.bossSprite.x + 10, yoyo: true, repeat: 3,
                            duration: 40, onComplete: function() {
 me.bossSprite.x = 270;
}
                        });
                    }

                    if (ativouPergunta) {
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

                                $('#playerpuzzle-pergunta-texto').html(textoDaPergunta);
                                var containerRespostas = $('#playerpuzzle-respostas-container');
                                containerRespostas.empty();

                                var fecharModal = function() {
                                    modalMoodle.removeClass('show').css('display', 'none');
                                    me.scene.resume();
                                    me.time.delayedCall(250, me.aplicarGravidade, [], me);
                                };

                                if (perguntaSorteada.answers && perguntaSorteada.answers.length > 0) {
                                    perguntaSorteada.answers.forEach(function(resposta) {
                                        var textoLimpo = resposta.answer.replace(/(<([^>]+)>)/gi, "");
                                        var btnStr = '<button class="btn btn-outline-primary btn-lg mb-3 w-100">';
                                        var btnHTML = $(btnStr + textoLimpo + '</button>');

                                        btnHTML.on('click', function() {
                                            if (parseFloat(resposta.fraction) > 0) {
                                                me.currentHp -= (gameConfig.bossdamage * 2);
                                                if (me.currentHp < 0) {
 me.currentHp = 0;
}
                                                me.hpText.setText('Boss HP: ' + me.currentHp);
                                                me.bossSprite.setTint(0x0088ff);

                                                me.tweens.add({
                                                    targets: me.bossSprite, y: me.bossSprite.y - 20,
                                                    yoyo: true, duration: 150,
                                                    onComplete: function() {
 me.bossSprite.clearTint();
}
                                                });
                                            }
                                            fecharModal();
                                        });
                                        containerRespostas.append(btnHTML);
                                    });
                                } else {
                                    containerRespostas.append('<p class="text-danger">Vazio</p>');
                                    $('#playerpuzzle-btn-fechar').off('click').on('click', fecharModal);
                                }

                                modalMoodle.addClass('show').css('display', 'block');
                                $('#playerpuzzle-btn-fechar').off('click').on('click', fecharModal);

                            } else {
                                me.scene.resume();
                                me.time.delayedCall(250, me.aplicarGravidade, [], me);
                            }
                        }, 250);
                    } else {
                        me.time.delayedCall(250, me.aplicarGravidade, [], me);
                    }
                } else {
                    if (!me.temJogadaPossivel()) {
                        me.input.enabled = false;
                        me.embaralhar();
                    } else {
                        me.input.enabled = true;
                        me.resetarDica();
                    }
                }
            };

            this.trocarPecas = function(peca1, peca2) {
                me.input.enabled = false;

                var tempRow = peca1.row;
                var tempCol = peca1.col;

                me.tabuleiro[peca1.row][peca1.col] = peca2;
                me.tabuleiro[peca2.row][peca2.col] = peca1;

                peca1.row = peca2.row;
                peca1.col = peca2.col;
                peca2.row = tempRow;
                peca2.col = tempCol;

                me.tweens.add({targets: peca1, x: peca2.x, y: peca2.y, duration: 200});
                me.tweens.add({
                    targets: peca2, x: peca1.x, y: peca1.y, duration: 200,
                    onComplete: function() {
 me.verificarCombinacoes();
}
                });
            };

            this.lidarComClique = function(pecaClicada) {
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
                if (me.swipePeca !== null) {
                    me.swipePeca.clearTint();

                    var dx = pointer.x - me.startX;
                    var dy = pointer.y - me.startY;
                    var threshold = 20;

                    if (Math.abs(dx) > threshold || Math.abs(dy) > threshold) {
                        var tRow = me.swipePeca.row;
                        var tCol = me.swipePeca.col;

                        if (Math.abs(dx) > Math.abs(dy)) {
                            if (dx > 0) {
 tCol++;
} else {
 tCol--;
}
                        } else {
                            if (dy > 0) {
 tRow++;
} else {
 tRow--;
}
                        }

                        if (tRow >= 0 && tRow < linhas && tCol >= 0 && tCol < colunas) {
                            var pAlvo = me.tabuleiro[tRow][tCol];
                            if (pAlvo) {
 me.trocarPecas(me.swipePeca, pAlvo);
}
                        }
                    } else {
                        me.lidarComClique(me.swipePeca);
                    }

                    me.swipePeca = null;
                }
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

                    // --- A SOLUÇÃO DEFINITIVA PARA O SEQUESTRO DO AMD ---
                    // Como vimos no seu print, o Phaser usa um "UMD wrapper" que diz: define("Phaser", ...)
                    // Nós usamos a função require() do próprio Moodle para resgatá-lo pelo nome oficial!
                    require(['Phaser'], function(PhaserObj) {

                        window.console.log("Phaser resgatado com sucesso do cofre do Moodle!");

                        // Devolvemos o Phaser ao escopo Global do navegador!
                        if (PhaserObj) {
                            window.Phaser = PhaserObj;
                        }

                        // Iniciamos o jogo imediatamente
                        startPhaser(config);

                    }, function(erro) {
                        // Se o RequireJS não o encontrar, mostramos na tela
                        var erroMsg = '<p class="text-center text-danger p-5 mt-5">';
                        erroMsg += 'Erro crítico: RequireJS não conseguiu encontrar o módulo Phaser. ';
                        erroMsg += 'Verifique o console (F12).</p>';
                        $('#playerpuzzle-canvas-container').html(erroMsg);
                        window.console.error("RequireJS Error:", erro);
                    });
                    // -----------------------------------------------------

                } catch (error) {
                    notification.exception(error);
                }
            });
        }
    };
});
