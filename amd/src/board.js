/**
 * Board and Match-3 Module for PlayerPuzzle
 *
 * @module     mod_playerpuzzle/board
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global Phaser */

define([], function() {
    'use strict';

    class BoardHandler {
        constructor(scene, layout) {
            this.scene = scene;
            this.L = layout;

            this.linhas = 8;
            this.colunas = 8;
            this.tamanhoPeca = 55;
            this.offsetX = layout.boardOffX;
            this.offsetY = layout.boardOffY;

            this.tabuleiro = [];
            this.pecaSelecionada = null;
            this.swipePeca = null;
            this.ultimaTroca = null;
            this.startX = 0;
            this.startY = 0;
            this.tempoUltimaAcao = 0;
            this.pecaComDica = null;

            this.desenharFundo();
            this.inicializarTabuleiro();
            this.configurarInputs();

            // Loop para verificar ociosidade e mostrar dicas
            this.scene.time.addEvent({
                delay: 1000,
                callback: this.verificarOciosidade,
                callbackScope: this,
                loop: true
            });
        }

        desenharFundo() {
            var graphics = this.scene.add.graphics();
            var largTab = this.colunas * this.tamanhoPeca;
            var altTab = this.linhas * this.tamanhoPeca;
            var rx = this.offsetX - (this.tamanhoPeca / 2);
            var ry = this.offsetY - (this.tamanhoPeca / 2);

            graphics.fillStyle(0x000000, 0.85).fillRect(rx, ry, largTab, altTab);
            graphics.lineStyle(6, 0x111111, 1).strokeRect(rx, ry, largTab, altTab);
            graphics.lineStyle(2, 0x333333, 0.4);
            graphics.beginPath();

            for (var i = 1; i < this.linhas; i++) {
                graphics.moveTo(rx, ry + (i * this.tamanhoPeca));
                graphics.lineTo(rx + largTab, ry + (i * this.tamanhoPeca));
                graphics.moveTo(rx + (i * this.tamanhoPeca), ry);
                graphics.lineTo(rx + (i * this.tamanhoPeca), ry + altTab);
            }
            graphics.strokePath();
        }

        inicializarTabuleiro() {
            var me = this.scene;
            for (var row = 0; row < this.linhas; row++) {
                this.tabuleiro[row] = [];
                for (var col = 0; col < this.colunas; col++) {
                    var itemAleatorio, temMatch;
                    do {
                        itemAleatorio = Math.floor(Math.random() * 7);
                        temMatch = false;

                        if (row >= 2 && this.tabuleiro[row - 1][col].tipo === itemAleatorio &&
                            this.tabuleiro[row - 2][col].tipo === itemAleatorio) {
                            temMatch = true;
                        }
                        if (col >= 2 && this.tabuleiro[row][col - 1].tipo === itemAleatorio &&
                            this.tabuleiro[row][col - 2].tipo === itemAleatorio) {
                            temMatch = true;
                        }
                    } while (temMatch);

                    var x = this.offsetX + (col * this.tamanhoPeca);
                    var y = this.offsetY + (row * this.tamanhoPeca);

                    var peca = me.add.image(x, y, 'item' + itemAleatorio);
                    peca.setDisplaySize(this.tamanhoPeca - 4, this.tamanhoPeca - 4);
                    peca.tipo = itemAleatorio;
                    peca.row = row;
                    peca.col = col;
                    peca.setInteractive();

                    peca.on('pointerdown', this.iniciarSwipe.bind(this, peca));
                    this.tabuleiro[row][col] = peca;
                }
            }
        }

        configurarInputs() {
            var ctx = this;
            this.scene.input.on('pointerup', function(pointer) {
                if (ctx.scene.combat.turnoAtual !== 'aluno' || ctx.swipePeca === null) {
                    return;
                }

                ctx.swipePeca.clearTint();
                var dx = pointer.x - ctx.startX;
                var dy = pointer.y - ctx.startY;
                var threshold = 20;

                if (Math.abs(dx) <= threshold && Math.abs(dy) <= threshold) {
                    ctx.lidarComClique(ctx.swipePeca);
                    ctx.swipePeca = null;
                    return;
                }

                var tRow = ctx.swipePeca.row;
                var tCol = ctx.swipePeca.col;

                if (Math.abs(dx) > Math.abs(dy)) {
                    tCol += (dx > 0) ? 1 : -1;
                } else {
                    tRow += (dy > 0) ? 1 : -1;
                }

                if (tRow >= 0 && tRow < ctx.linhas && tCol >= 0 && tCol < ctx.colunas) {
                    var pAlvo = ctx.tabuleiro[tRow][tCol];
                    if (pAlvo) {
                        ctx.trocarPecas(ctx.swipePeca, pAlvo);
                    }
                }
                ctx.swipePeca = null;
            });
        }

        iniciarSwipe(peca, pointer) {
            if (this.scene.combat.turnoAtual !== 'aluno') {
                return;
            }
            this.resetarDica();
            this.swipePeca = peca;
            this.startX = pointer.x;
            this.startY = pointer.y;
            peca.setTint(0xdddddd);
        }

        lidarComClique(pecaClicada) {
            if (this.scene.combat.turnoAtual !== 'aluno') {
                return;
            }

            if (this.pecaSelecionada === null) {
                this.pecaSelecionada = pecaClicada;
                pecaClicada.setTint(0xaaaaaa);
            } else {
                var p1 = this.pecaSelecionada;
                var p2 = pecaClicada;

                p1.clearTint();
                this.pecaSelecionada = null;

                var isAdjacente = Math.abs(p1.row - p2.row) + Math.abs(p1.col - p2.col) === 1;
                if (isAdjacente) {
                    this.trocarPecas(p1, p2);
                } else if (p1 !== p2) {
                    this.pecaSelecionada = p2;
                    p2.setTint(0xaaaaaa);
                }
            }
        }

        trocarPecas(peca1, peca2, isRevert) {
            var me = this.scene;
            me.input.enabled = false;
            me.sfxSwap.play();

            var tempRow = peca1.row;
            var tempCol = peca1.col;

            this.tabuleiro[peca1.row][peca1.col] = peca2;
            this.tabuleiro[peca2.row][peca2.col] = peca1;

            peca1.row = peca2.row;
            peca1.col = peca2.col;
            peca2.row = tempRow;
            peca2.col = tempCol;

            if (!isRevert) {
                this.ultimaTroca = {p1: peca1, p2: peca2};
            } else {
                this.ultimaTroca = null;
            }

            var ctx = this;
            me.tweens.add({targets: peca1, x: peca2.x, y: peca2.y, duration: 200});
            me.tweens.add({
                targets: peca2, x: peca1.x, y: peca1.y, duration: 200,
                onComplete: function() {
                    if (!isRevert) {
                        ctx.verificarCombinacoes();
                    } else {
                        me.input.enabled = true;
                        ctx.resetarDica();
                    }
                }
            });
        }

        verificarHorizontal(pecasDestruir) {
            for (var r = 0; r < this.linhas; r++) {
                for (var c = 0; c < this.colunas - 2; c++) {
                    var p1 = this.tabuleiro[r][c];
                    var p2 = this.tabuleiro[r][c + 1];
                    var p3 = this.tabuleiro[r][c + 2];
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
        }

        verificarVertical(pecasDestruir) {
            for (var c = 0; c < this.colunas; c++) {
                for (var r = 0; r < this.linhas - 2; r++) {
                    var p1 = this.tabuleiro[r][c];
                    var p2 = this.tabuleiro[r + 1][c];
                    var p3 = this.tabuleiro[r + 2][c];
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
        }

        encontrarJogada() {
            var ctx = this;
            var isMatch = function(rowP, colP) {
                var p = ctx.tabuleiro[rowP][colP];
                if (!p) {
 return false;
}

                var tipo = p.tipo,
countH = 1,
countV = 1,
tr, tc;

                tc = colP - 1;
                while (tc >= 0 && ctx.tabuleiro[rowP][tc] && ctx.tabuleiro[rowP][tc].tipo === tipo) {
                    countH++; tc--;
                }

                tc = colP + 1;
                while (tc < ctx.colunas && ctx.tabuleiro[rowP][tc] && ctx.tabuleiro[rowP][tc].tipo === tipo) {
                    countH++; tc++;
                }
                if (countH >= 3) {
 return true;
}

                tr = rowP - 1;
                while (tr >= 0 && ctx.tabuleiro[tr][colP] && ctx.tabuleiro[tr][colP].tipo === tipo) {
                    countV++; tr--;
                }

                tr = rowP + 1;
                while (tr < ctx.linhas && ctx.tabuleiro[tr][colP] && ctx.tabuleiro[tr][colP].tipo === tipo) {
                    countV++; tr++;
                }

                return countV >= 3;
            };

            for (var r = 0; r < this.linhas; r++) {
                for (var c = 0; c < this.colunas; c++) {
                    var temp;
                    if (c < this.colunas - 1) {
                        temp = this.tabuleiro[r][c].tipo;
                        this.tabuleiro[r][c].tipo = this.tabuleiro[r][c + 1].tipo;
                        this.tabuleiro[r][c + 1].tipo = temp;
                        var matchR = isMatch(r, c) || isMatch(r, c + 1);

                        temp = this.tabuleiro[r][c].tipo;
                        this.tabuleiro[r][c].tipo = this.tabuleiro[r][c + 1].tipo;
                        this.tabuleiro[r][c + 1].tipo = temp;

                        if (matchR) {
 return {p1: this.tabuleiro[r][c], p2: this.tabuleiro[r][c + 1]};
}
                    }
                    if (r < this.linhas - 1) {
                        temp = this.tabuleiro[r][c].tipo;
                        this.tabuleiro[r][c].tipo = this.tabuleiro[r + 1][c].tipo;
                        this.tabuleiro[r + 1][c].tipo = temp;
                        var matchD = isMatch(r, c) || isMatch(r + 1, c);

                        temp = this.tabuleiro[r][c].tipo;
                        this.tabuleiro[r][c].tipo = this.tabuleiro[r + 1][c].tipo;
                        this.tabuleiro[r + 1][c].tipo = temp;

                        if (matchD) {
 return {p1: this.tabuleiro[r][c], p2: this.tabuleiro[r + 1][c]};
}
                    }
                }
            }
            return null;
        }

        temJogadaPossivel() {
            return this.encontrarJogada() !== null;
        }

        resetarDica() {
            this.tempoUltimaAcao = this.scene.time.now;
            if (this.pecaComDica) {
                this.scene.tweens.killTweensOf([this.pecaComDica.p1, this.pecaComDica.p2]);
                this.pecaComDica.p1.setDisplaySize(this.tamanhoPeca - 4, this.tamanhoPeca - 4);
                this.pecaComDica.p2.setDisplaySize(this.tamanhoPeca - 4, this.tamanhoPeca - 4);
                this.pecaComDica = null;
            }
        }

        verificarOciosidade() {
            if (!this.scene.input.enabled || this.scene.combat.turnoAtual !== 'aluno') {
                this.tempoUltimaAcao = this.scene.time.now;
                return;
            }
            if (this.pecaComDica === null && (this.scene.time.now - this.tempoUltimaAcao > 5000)) {
                var jogadaDica = this.encontrarJogada();
                if (jogadaDica) {
                    this.pecaComDica = jogadaDica;
                    this.scene.tweens.add({
                        targets: [jogadaDica.p1, jogadaDica.p2],
                        displayWidth: this.tamanhoPeca + 6,
                        displayHeight: this.tamanhoPeca + 6,
                        yoyo: true, repeat: -1, duration: 400
                    });
                }
            }
        }

        embaralhar() {
            var me = this.scene;
            var aviso = me.add.text(270, 480, 'EMBARALHANDO...', {
                fontSize: '32px', fill: '#ffffff', backgroundColor: '#000000',
                align: 'center', fontStyle: 'bold', padding: {x: 20, y: 20}
            }).setOrigin(0.5).setDepth(100);

            var tipos = [];
            for (var r = 0; r < this.linhas; r++) {
                for (var c = 0; c < this.colunas; c++) {
                    tipos.push(this.tabuleiro[r][c].tipo);
                }
            }

            var ctx = this;
            var temMatchInicial = function() {
                var pecasDest = [];
                ctx.verificarHorizontal(pecasDest);
                ctx.verificarVertical(pecasDest);
                return pecasDest.length > 0;
            };

            do {
                Phaser.Utils.Array.Shuffle(tipos);
                var idx = 0;
                for (var r2 = 0; r2 < this.linhas; r2++) {
                    for (var c2 = 0; c2 < this.colunas; c2++) {
                        this.tabuleiro[r2][c2].tipo = tipos[idx];
                        this.tabuleiro[r2][c2].setTexture('item' + tipos[idx]);
                        idx++;
                    }
                }
            } while (!this.temJogadaPossivel() || temMatchInicial());

            for (var r3 = 0; r3 < this.linhas; r3++) {
                for (var c3 = 0; c3 < this.colunas; c3++) {
                    var pecaShuffle = this.tabuleiro[r3][c3];
                    pecaShuffle.alpha = 0;
                    me.tweens.add({
                        targets: pecaShuffle, alpha: 1, duration: 500, delay: Math.random() * 400
                    });
                }
            }

            me.time.delayedCall(1200, function() {
                aviso.destroy();
                if (me.combat.turnoAtual === 'aluno') {
                    me.input.enabled = true;
                    ctx.resetarDica();
                } else {
                    me.combat.executarTurnoChefe();
                }
            });
        }

        aplicarGravidade() {
            var me = this.scene;
            var col, row, r, pecaCaindo, peca, x, yInicio, yFim, itemAleatorio;

            for (col = 0; col < this.colunas; col++) {
                for (row = this.linhas - 1; row >= 0; row--) {
                    if (this.tabuleiro[row][col] !== null) {
 continue;
}

                    for (r = row - 1; r >= 0; r--) {
                        if (this.tabuleiro[r][col] !== null) {
                            pecaCaindo = this.tabuleiro[r][col];
                            this.tabuleiro[row][col] = pecaCaindo;
                            this.tabuleiro[r][col] = null;
                            pecaCaindo.row = row;
                            me.tweens.add({
                                targets: pecaCaindo,
                                y: this.offsetY + (row * this.tamanhoPeca),
                                duration: 250, ease: 'Quad.easeIn'
                            });
                            break;
                        }
                    }
                }
            }

            for (col = 0; col < this.colunas; col++) {
                for (row = 0; row < this.linhas; row++) {
                    if (this.tabuleiro[row][col] !== null) {
 continue;
}

                    itemAleatorio = Math.floor(Math.random() * 7);
                    x = this.offsetX + (col * this.tamanhoPeca);
                    yInicio = this.offsetY - (this.tamanhoPeca * (this.linhas - row));
                    yFim = this.offsetY + (row * this.tamanhoPeca);

                    peca = me.add.image(x, yInicio, 'item' + itemAleatorio);
                    peca.setDisplaySize(this.tamanhoPeca - 4, this.tamanhoPeca - 4);
                    peca.tipo = itemAleatorio;
                    peca.row = row;
                    peca.col = col;

                    peca.setInteractive();
                    peca.on('pointerdown', this.iniciarSwipe.bind(this, peca));

                    this.tabuleiro[row][col] = peca;
                    me.tweens.add({
                        targets: peca, y: yFim, duration: 400, ease: 'Bounce.easeOut'
                    });
                }
            }

            me.time.delayedCall(500, this.verificarCombinacoes, [], this);
        }

        verificarCombinacoes() {
            var me = this.scene;
            var pecasParaDestruir = [];

            this.verificarHorizontal(pecasParaDestruir);
            this.verificarVertical(pecasParaDestruir);

            if (pecasParaDestruir.length === 0) {
                if (this.ultimaTroca !== null) {
                    this.trocarPecas(this.ultimaTroca.p1, this.ultimaTroca.p2, true);
                    return;
                }

                if (me.combat.verificarFimDeJogo()) {
                    return;
                }

                if (me.combat.turnoAtual === 'aluno') {
                    me.combat.passarTurnoParaChefe();
                } else {
                    me.combat.passarTurnoParaAluno();
                    if (!this.temJogadaPossivel()) {
                        this.embaralhar();
                    } else {
                        me.input.enabled = true;
                        this.resetarDica();
                    }
                }
                return;
            }

            this.ultimaTroca = null;

            // Aqui o tabuleiro avisa o combate que houve combinação
            var efeitos = me.combat.processarEfeitos(pecasParaDestruir);
            var dCausado = efeitos.dano;

            if (dCausado > 0) {
                dCausado = Math.round(dCausado * (me.combat.turnoAtual === 'aluno' ? me.combat.alunoMultiplicador : 1));
                if (me.combat.turnoAtual === 'aluno') {
                    me.combat.aplicarDanoBoss(dCausado);
                } else {
                    me.combat.aplicarDanoAluno(dCausado);
                }
            }

            if (efeitos.pergunta) {
                me.combat.abrirModalPergunta(efeitos.quem);
            } else {
                me.time.delayedCall(250, this.aplicarGravidade, [], this);
            }
        }
    }

    return BoardHandler;
});
