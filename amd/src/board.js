/**
 * Board and Match-3 Module for PlayerPuzzle.
 *
 * @module     mod_playerpuzzle/board
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global Phaser */

define([], function() {
    'use strict';

    class BoardHandler {
        constructor(scene, layout) {
            this.scene = scene;
            this.L = layout;

            this.rows = 8;
            this.cols = 8;
            this.pieceSize = 55;
            this.offsetX = layout.boardOffX;
            this.offsetY = layout.boardOffY;

            this.grid = [];
            this.selectedPiece = null;
            this.swipePiece = null;
            this.lastSwap = null;
            this.startX = 0;
            this.startY = 0;
            this.lastActionTime = 0;
            this.hintPiece = null;

            this.drawBackground();
            this.initGrid();
            this.setupInputs();

            // Show a move hint after 5 s of player inactivity.
            this.scene.time.addEvent({
                delay: 1000,
                callback: this.checkIdle,
                callbackScope: this,
                loop: true
            });
        }

        drawBackground() {
            var graphics = this.scene.add.graphics();
            var gridWidth = this.cols * this.pieceSize;
            var gridHeight = this.rows * this.pieceSize;
            var rx = this.offsetX - (this.pieceSize / 2);
            var ry = this.offsetY - (this.pieceSize / 2);

            graphics.fillStyle(0x000000, 0.85).fillRect(rx, ry, gridWidth, gridHeight);
            graphics.lineStyle(6, 0x111111, 1).strokeRect(rx, ry, gridWidth, gridHeight);
            graphics.lineStyle(2, 0x333333, 0.4);
            graphics.beginPath();

            for (var i = 1; i < this.rows; i++) {
                graphics.moveTo(rx, ry + (i * this.pieceSize));
                graphics.lineTo(rx + gridWidth, ry + (i * this.pieceSize));
                graphics.moveTo(rx + (i * this.pieceSize), ry);
                graphics.lineTo(rx + (i * this.pieceSize), ry + gridHeight);
            }
            graphics.strokePath();
        }

        initGrid() {
            var me = this.scene;
            for (var row = 0; row < this.rows; row++) {
                this.grid[row] = [];
                for (var col = 0; col < this.cols; col++) {
                    var randomType, hasMatch;
                    do {
                        randomType = Math.floor(Math.random() * 7);
                        hasMatch = false;

                        if (row >= 2 && this.grid[row - 1][col].type === randomType &&
                            this.grid[row - 2][col].type === randomType) {
                            hasMatch = true;
                        }
                        if (col >= 2 && this.grid[row][col - 1].type === randomType &&
                            this.grid[row][col - 2].type === randomType) {
                            hasMatch = true;
                        }
                    } while (hasMatch);

                    var x = this.offsetX + (col * this.pieceSize);
                    var y = this.offsetY + (row * this.pieceSize);

                    var piece = me.add.image(x, y, 'item' + randomType);
                    piece.setDisplaySize(this.pieceSize - 4, this.pieceSize - 4);
                    piece.type = randomType;
                    piece.row = row;
                    piece.col = col;
                    piece.setInteractive();

                    piece.on('pointerdown', this.startSwipe.bind(this, piece));
                    this.grid[row][col] = piece;
                }
            }
        }

        setupInputs() {
            var ctx = this;
            this.scene.input.on('pointerup', function(pointer) {
                if (ctx.scene.combat.currentTurn !== 'player' || ctx.swipePiece === null) {
                    return;
                }

                ctx.swipePiece.clearTint();
                var dx = pointer.x - ctx.startX;
                var dy = pointer.y - ctx.startY;
                var threshold = 20;

                if (Math.abs(dx) <= threshold && Math.abs(dy) <= threshold) {
                    ctx.handleClick(ctx.swipePiece);
                    ctx.swipePiece = null;
                    return;
                }

                var tRow = ctx.swipePiece.row;
                var tCol = ctx.swipePiece.col;

                if (Math.abs(dx) > Math.abs(dy)) {
                    tCol += (dx > 0) ? 1 : -1;
                } else {
                    tRow += (dy > 0) ? 1 : -1;
                }

                if (tRow >= 0 && tRow < ctx.rows && tCol >= 0 && tCol < ctx.cols) {
                    var target = ctx.grid[tRow][tCol];
                    if (target) {
                        ctx.swapPieces(ctx.swipePiece, target);
                    }
                }
                ctx.swipePiece = null;
            });
        }

        startSwipe(piece, pointer) {
            if (this.scene.combat.currentTurn !== 'player') {
                return;
            }
            this.resetHint();
            this.swipePiece = piece;
            this.startX = pointer.x;
            this.startY = pointer.y;
            piece.setTint(0xdddddd);
        }

        handleClick(clickedPiece) {
            if (this.scene.combat.currentTurn !== 'player') {
                return;
            }

            if (this.selectedPiece === null) {
                this.selectedPiece = clickedPiece;
                clickedPiece.setTint(0xaaaaaa);
            } else {
                var p1 = this.selectedPiece;
                var p2 = clickedPiece;

                p1.clearTint();
                this.selectedPiece = null;

                var isAdjacent = Math.abs(p1.row - p2.row) + Math.abs(p1.col - p2.col) === 1;
                if (isAdjacent) {
                    this.swapPieces(p1, p2);
                } else if (p1 !== p2) {
                    this.selectedPiece = p2;
                    p2.setTint(0xaaaaaa);
                }
            }
        }

        swapPieces(piece1, piece2, isRevert) {
            var me = this.scene;
            me.input.enabled = false;
            me.sfxSwap.play();

            var tempRow = piece1.row;
            var tempCol = piece1.col;

            this.grid[piece1.row][piece1.col] = piece2;
            this.grid[piece2.row][piece2.col] = piece1;

            piece1.row = piece2.row;
            piece1.col = piece2.col;
            piece2.row = tempRow;
            piece2.col = tempCol;

            if (!isRevert) {
                this.lastSwap = {p1: piece1, p2: piece2};
            } else {
                this.lastSwap = null;
            }

            var ctx = this;
            me.tweens.add({targets: piece1, x: piece2.x, y: piece2.y, duration: 200});
            me.tweens.add({
                targets: piece2, x: piece1.x, y: piece1.y, duration: 200,
                onComplete: function() {
                    if (!isRevert) {
                        ctx.checkMatches();
                    } else {
                        me.input.enabled = true;
                        ctx.resetHint();
                    }
                }
            });
        }

        checkHorizontal(toDestroy) {
            for (var r = 0; r < this.rows; r++) {
                for (var c = 0; c < this.cols - 2; c++) {
                    var p1 = this.grid[r][c];
                    var p2 = this.grid[r][c + 1];
                    var p3 = this.grid[r][c + 2];
                    if (p1 && p2 && p3 && p1.type === p2.type && p2.type === p3.type) {
                        if (toDestroy.indexOf(p1) === -1) {
                            toDestroy.push(p1);
                        }
                        if (toDestroy.indexOf(p2) === -1) {
                            toDestroy.push(p2);
                        }
                        if (toDestroy.indexOf(p3) === -1) {
                            toDestroy.push(p3);
                        }
                    }
                }
            }
        }

        checkVertical(toDestroy) {
            for (var c = 0; c < this.cols; c++) {
                for (var r = 0; r < this.rows - 2; r++) {
                    var p1 = this.grid[r][c];
                    var p2 = this.grid[r + 1][c];
                    var p3 = this.grid[r + 2][c];
                    if (p1 && p2 && p3 && p1.type === p2.type && p2.type === p3.type) {
                        if (toDestroy.indexOf(p1) === -1) {
                            toDestroy.push(p1);
                        }
                        if (toDestroy.indexOf(p2) === -1) {
                            toDestroy.push(p2);
                        }
                        if (toDestroy.indexOf(p3) === -1) {
                            toDestroy.push(p3);
                        }
                    }
                }
            }
        }

        findMove() {
            var ctx = this;
            var isMatch = function(rowP, colP) {
                var p = ctx.grid[rowP][colP];
                if (!p) {
                    return false;
                }

                var type = p.type,
                    countH = 1,
                    countV = 1,
                    tr, tc;

                tc = colP - 1;
                while (tc >= 0 && ctx.grid[rowP][tc] && ctx.grid[rowP][tc].type === type) {
                    countH++; tc--;
                }

                tc = colP + 1;
                while (tc < ctx.cols && ctx.grid[rowP][tc] && ctx.grid[rowP][tc].type === type) {
                    countH++; tc++;
                }
                if (countH >= 3) {
                    return true;
                }

                tr = rowP - 1;
                while (tr >= 0 && ctx.grid[tr][colP] && ctx.grid[tr][colP].type === type) {
                    countV++; tr--;
                }

                tr = rowP + 1;
                while (tr < ctx.rows && ctx.grid[tr][colP] && ctx.grid[tr][colP].type === type) {
                    countV++; tr++;
                }

                return countV >= 3;
            };

            for (var r = 0; r < this.rows; r++) {
                for (var c = 0; c < this.cols; c++) {
                    var temp;
                    if (c < this.cols - 1) {
                        temp = this.grid[r][c].type;
                        this.grid[r][c].type = this.grid[r][c + 1].type;
                        this.grid[r][c + 1].type = temp;
                        var matchR = isMatch(r, c) || isMatch(r, c + 1);

                        temp = this.grid[r][c].type;
                        this.grid[r][c].type = this.grid[r][c + 1].type;
                        this.grid[r][c + 1].type = temp;

                        if (matchR) {
                            return {p1: this.grid[r][c], p2: this.grid[r][c + 1]};
                        }
                    }
                    if (r < this.rows - 1) {
                        temp = this.grid[r][c].type;
                        this.grid[r][c].type = this.grid[r + 1][c].type;
                        this.grid[r + 1][c].type = temp;
                        var matchD = isMatch(r, c) || isMatch(r + 1, c);

                        temp = this.grid[r][c].type;
                        this.grid[r][c].type = this.grid[r + 1][c].type;
                        this.grid[r + 1][c].type = temp;

                        if (matchD) {
                            return {p1: this.grid[r][c], p2: this.grid[r + 1][c]};
                        }
                    }
                }
            }
            return null;
        }

        hasAvailableMove() {
            return this.findMove() !== null;
        }

        resetHint() {
            this.lastActionTime = this.scene.time.now;
            if (this.hintPiece) {
                this.scene.tweens.killTweensOf([this.hintPiece.p1, this.hintPiece.p2]);
                this.hintPiece.p1.setDisplaySize(this.pieceSize - 4, this.pieceSize - 4);
                this.hintPiece.p2.setDisplaySize(this.pieceSize - 4, this.pieceSize - 4);
                this.hintPiece = null;
            }
        }

        checkIdle() {
            if (!this.scene.input.enabled || this.scene.combat.currentTurn !== 'player') {
                this.lastActionTime = this.scene.time.now;
                return;
            }
            if (this.hintPiece === null && (this.scene.time.now - this.lastActionTime > 5000)) {
                var hintMove = this.findMove();
                if (hintMove) {
                    this.hintPiece = hintMove;
                    this.scene.tweens.add({
                        targets: [hintMove.p1, hintMove.p2],
                        displayWidth: this.pieceSize + 6,
                        displayHeight: this.pieceSize + 6,
                        yoyo: true, repeat: -1, duration: 400
                    });
                }
            }
        }

        shuffle() {
            var me = this.scene;
            var notice = me.add.text(270, 480, 'SHUFFLING...', {
                fontSize: '32px', fill: '#ffffff', backgroundColor: '#000000',
                align: 'center', fontStyle: 'bold', padding: {x: 20, y: 20}
            }).setOrigin(0.5).setDepth(100);

            var types = [];
            for (var r = 0; r < this.rows; r++) {
                for (var c = 0; c < this.cols; c++) {
                    types.push(this.grid[r][c].type);
                }
            }

            var ctx = this;
            var hasInitialMatch = function() {
                var toDestroy = [];
                ctx.checkHorizontal(toDestroy);
                ctx.checkVertical(toDestroy);
                return toDestroy.length > 0;
            };

            do {
                Phaser.Utils.Array.Shuffle(types);
                var idx = 0;
                for (var r2 = 0; r2 < this.rows; r2++) {
                    for (var c2 = 0; c2 < this.cols; c2++) {
                        this.grid[r2][c2].type = types[idx];
                        this.grid[r2][c2].setTexture('item' + types[idx]);
                        idx++;
                    }
                }
            } while (!this.hasAvailableMove() || hasInitialMatch());

            for (var r3 = 0; r3 < this.rows; r3++) {
                for (var c3 = 0; c3 < this.cols; c3++) {
                    var shufflePiece = this.grid[r3][c3];
                    shufflePiece.alpha = 0;
                    me.tweens.add({
                        targets: shufflePiece, alpha: 1, duration: 500, delay: Math.random() * 400
                    });
                }
            }

            me.time.delayedCall(1200, function() {
                notice.destroy();
                if (me.combat.currentTurn === 'player') {
                    me.input.enabled = true;
                    ctx.resetHint();
                } else {
                    me.combat.executeBossTurn();
                }
            });
        }

        applyGravity() {
            var me = this.scene;
            var col, row, r, falling, piece, x, yStart, yEnd, randomType;

            for (col = 0; col < this.cols; col++) {
                for (row = this.rows - 1; row >= 0; row--) {
                    if (this.grid[row][col] !== null) {
                        continue;
                    }

                    for (r = row - 1; r >= 0; r--) {
                        if (this.grid[r][col] !== null) {
                            falling = this.grid[r][col];
                            this.grid[row][col] = falling;
                            this.grid[r][col] = null;
                            falling.row = row;
                            me.tweens.add({
                                targets: falling,
                                y: this.offsetY + (row * this.pieceSize),
                                duration: 250, ease: 'Quad.easeIn'
                            });
                            break;
                        }
                    }
                }
            }

            for (col = 0; col < this.cols; col++) {
                for (row = 0; row < this.rows; row++) {
                    if (this.grid[row][col] !== null) {
                        continue;
                    }

                    randomType = Math.floor(Math.random() * 7);
                    x = this.offsetX + (col * this.pieceSize);
                    yStart = this.offsetY - (this.pieceSize * (this.rows - row));
                    yEnd = this.offsetY + (row * this.pieceSize);

                    piece = me.add.image(x, yStart, 'item' + randomType);
                    piece.setDisplaySize(this.pieceSize - 4, this.pieceSize - 4);
                    piece.type = randomType;
                    piece.row = row;
                    piece.col = col;

                    piece.setInteractive();
                    piece.on('pointerdown', this.startSwipe.bind(this, piece));

                    this.grid[row][col] = piece;
                    me.tweens.add({
                        targets: piece, y: yEnd, duration: 400, ease: 'Bounce.easeOut'
                    });
                }
            }

            me.time.delayedCall(500, this.checkMatches, [], this);
        }

        checkMatches() {
            var me = this.scene;
            var toDestroy = [];

            this.checkHorizontal(toDestroy);
            this.checkVertical(toDestroy);

            if (toDestroy.length === 0) {
                if (this.lastSwap !== null) {
                    this.swapPieces(this.lastSwap.p1, this.lastSwap.p2, true);
                    return;
                }

                if (me.combat.checkGameOver()) {
                    return;
                }

                if (me.combat.currentTurn === 'player') {
                    me.combat.passTurnToBoss();
                } else {
                    me.combat.passTurnToPlayer();
                    if (!this.hasAvailableMove()) {
                        this.shuffle();
                    } else {
                        me.input.enabled = true;
                        this.resetHint();
                    }
                }
                return;
            }

            this.lastSwap = null;

            var effects = me.combat.processEffects(toDestroy);
            var damage = effects.damage;

            if (damage > 0) {
                damage = Math.round(
                    damage * (me.combat.currentTurn === 'player' ? me.combat.playerMultiplier : 1)
                );
                if (me.combat.currentTurn === 'player') {
                    me.combat.applyDamageToBoss(damage);
                } else {
                    me.combat.applyDamageToPlayer(damage);
                }
            }

            if (effects.question) {
                me.combat.openQuestionModal(effects.trigger);
            } else {
                me.time.delayedCall(250, this.applyGravity, [], this);
            }
        }
    }

    return BoardHandler;
});
