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
        constructor(scene, layout, strings) {
            this.scene = scene;
            this.L = layout;
            this.strings = strings;

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
            const graphics = this.scene.add.graphics();
            const gridWidth = this.cols * this.pieceSize;
            const gridHeight = this.rows * this.pieceSize;
            const rx = this.offsetX - (this.pieceSize / 2);
            const ry = this.offsetY - (this.pieceSize / 2);

            // The offsetY passed in (boardOffY) is already shifted up by panelOverlap at the
            // source, in game_boot.js — the grid itself rises into the stage band above, not
            // just a decorative strip glued on top of it, so real pieces sit in the overlap
            // and the border/grid lines below trace their true position with no extra math
            // here. The fill extends panelOverlap further down past the grid's real bottom
            // edge, so the gap that shift opens up before the true panel/canvas bottom (720)
            // stays covered instead of showing bare canvas. Alpha bumped from the original
            // 0.85 to 0.95: at 0.85, the pieces now sitting in the overlap read against the
            // bright stage_bg image behind them, and enough of it bled through the gaps
            // between pieces to look like a brown stripe instead of a solid platform.
            const overlap = this.L.panelOverlap || 0;
            graphics.fillStyle(0x000000, 0.95).fillRect(rx, ry, gridWidth, gridHeight + overlap);
            graphics.lineStyle(6, 0x111111, 1).strokeRect(rx, ry, gridWidth, gridHeight);
            graphics.lineStyle(2, 0x333333, 0.4);
            graphics.beginPath();

            for (let i = 1; i < this.rows; i++) {
                graphics.moveTo(rx, ry + (i * this.pieceSize));
                graphics.lineTo(rx + gridWidth, ry + (i * this.pieceSize));
                graphics.moveTo(rx + (i * this.pieceSize), ry);
                graphics.lineTo(rx + (i * this.pieceSize), ry + gridHeight);
            }
            graphics.strokePath();
        }

        initGrid() {
            const me = this.scene;
            for (let row = 0; row < this.rows; row++) {
                this.grid[row] = [];
                for (let col = 0; col < this.cols; col++) {
                    let randomType, hasMatch;
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

                    const x = this.offsetX + (col * this.pieceSize);
                    const y = this.offsetY + (row * this.pieceSize);

                    const piece = me.add.image(x, y, `item${randomType}`);
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
            this.scene.input.on('pointerup', pointer => {
                if (this.scene.combat.currentTurn !== 'player' || this.swipePiece === null) {
                    return;
                }

                this.swipePiece.clearTint();
                // Uses worldX/worldY, not the raw x/y: those are canvas-backing-store pixels,
                // which the camera zoom (see game_boot.js SUPERSAMPLE) no longer maps 1:1 to
                // this board's own 1280x720-space coordinates. worldX/worldY already divide
                // that zoom back out, keeping the threshold below meaningful regardless of it.
                const dx = pointer.worldX - this.startX;
                const dy = pointer.worldY - this.startY;
                const threshold = 20;

                if (Math.abs(dx) <= threshold && Math.abs(dy) <= threshold) {
                    this.handleClick(this.swipePiece);
                    this.swipePiece = null;
                    return;
                }

                let tRow = this.swipePiece.row;
                let tCol = this.swipePiece.col;

                if (Math.abs(dx) > Math.abs(dy)) {
                    tCol += (dx > 0) ? 1 : -1;
                } else {
                    tRow += (dy > 0) ? 1 : -1;
                }

                if (tRow >= 0 && tRow < this.rows && tCol >= 0 && tCol < this.cols) {
                    const target = this.grid[tRow][tCol];
                    if (target) {
                        this.swapPieces(this.swipePiece, target);
                    }
                }
                this.swipePiece = null;
            });
        }

        startSwipe(piece, pointer) {
            if (this.scene.combat.currentTurn !== 'player') {
                return;
            }
            this.resetHint();
            this.swipePiece = piece;
            this.startX = pointer.worldX;
            this.startY = pointer.worldY;
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
                const p1 = this.selectedPiece;
                const p2 = clickedPiece;

                p1.clearTint();
                this.selectedPiece = null;

                const isAdjacent = Math.abs(p1.row - p2.row) + Math.abs(p1.col - p2.col) === 1;
                if (isAdjacent) {
                    this.swapPieces(p1, p2);
                } else if (p1 !== p2) {
                    this.selectedPiece = p2;
                    p2.setTint(0xaaaaaa);
                }
            }
        }

        swapPieces(piece1, piece2, isRevert) {
            const me = this.scene;
            me.input.enabled = false;
            me.sfxSwap.play();

            const tempRow = piece1.row;
            const tempCol = piece1.col;

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

            me.tweens.add({targets: piece1, x: piece2.x, y: piece2.y, duration: 200});
            me.tweens.add({
                targets: piece2, x: piece1.x, y: piece1.y, duration: 200,
                onComplete: () => {
                    if (!isRevert) {
                        this.checkMatches();
                    } else {
                        me.input.enabled = true;
                        this.resetHint();
                    }
                }
            });
        }

        /**
         * Registers one detected run as a match group, adding its pieces to the flat,
         * deduplicated destroy list too. Shared by checkHorizontal()/checkVertical() to keep
         * both scans within the project's max block-nesting depth.
         *
         * @param {Array} pieces Pieces belonging to this run, in order.
         * @param {Array} toDestroy Flat, deduplicated list of pieces to destroy (mutated in place).
         * @param {Array} matchGroups List of {type, pieces} match groups (mutated in place).
         */
        registerRun(pieces, toDestroy, matchGroups) {
            for (const piece of pieces) {
                if (toDestroy.indexOf(piece) === -1) {
                    toDestroy.push(piece);
                }
            }
            matchGroups.push({type: pieces[0].type, pieces});
        }

        /**
         * Scans every row for contiguous same-type runs of 3+ pieces, each pushed as its own
         * match group (with the exact run length) alongside the flat, deduplicated destroy list
         * — combo-size-aware effects (Sword/Coin) read group sizes; every other piece effect
         * still reads the flat list exactly as before this refactor.
         *
         * @param {Array} toDestroy Flat, deduplicated list of pieces to destroy (mutated in place).
         * @param {Array} matchGroups List of {type, pieces} match groups (mutated in place).
         */
        checkHorizontal(toDestroy, matchGroups) {
            for (let r = 0; r < this.rows; r++) {
                let c = 0;
                while (c < this.cols) {
                    const p = this.grid[r][c];
                    if (!p) {
                        c++;
                        continue;
                    }
                    let runEnd = c;
                    while (runEnd + 1 < this.cols && this.grid[r][runEnd + 1] &&
                            this.grid[r][runEnd + 1].type === p.type) {
                        runEnd++;
                    }
                    if (runEnd - c + 1 >= 3) {
                        const pieces = [];
                        for (let i = c; i <= runEnd; i++) {
                            pieces.push(this.grid[r][i]);
                        }
                        this.registerRun(pieces, toDestroy, matchGroups);
                    }
                    c = runEnd + 1;
                }
            }
        }

        /**
         * Same as checkHorizontal(), scanning columns instead of rows.
         *
         * @param {Array} toDestroy Flat, deduplicated list of pieces to destroy (mutated in place).
         * @param {Array} matchGroups List of {type, pieces} match groups (mutated in place).
         */
        checkVertical(toDestroy, matchGroups) {
            for (let c = 0; c < this.cols; c++) {
                let r = 0;
                while (r < this.rows) {
                    const p = this.grid[r][c];
                    if (!p) {
                        r++;
                        continue;
                    }
                    let runEnd = r;
                    while (runEnd + 1 < this.rows && this.grid[runEnd + 1][c] &&
                            this.grid[runEnd + 1][c].type === p.type) {
                        runEnd++;
                    }
                    if (runEnd - r + 1 >= 3) {
                        const pieces = [];
                        for (let i = r; i <= runEnd; i++) {
                            pieces.push(this.grid[i][c]);
                        }
                        this.registerRun(pieces, toDestroy, matchGroups);
                    }
                    r = runEnd + 1;
                }
            }
        }

        /**
         * Checks whether the piece at the given cell is part of a match, optionally
         * restricted to a single piece type (used by findMove() to hunt for a specific
         * type of match, e.g. the boss prioritising damage-dealing pieces).
         *
         * @param {number} rowP Row index.
         * @param {number} colP Column index.
         * @param {number|null} onlyType When set, only counts as a match if the piece type equals this value.
         * @returns {boolean} Whether a match of at least 3 exists at this cell.
         */
        isMatchAt(rowP, colP, onlyType = null) {
            const p = this.grid[rowP][colP];
            if (!p) {
                return false;
            }

            const {type} = p;
            if (onlyType !== null && type !== onlyType) {
                return false;
            }

            let countH = 1;
            let countV = 1;
            let tr, tc;

            tc = colP - 1;
            while (tc >= 0 && this.grid[rowP][tc] && this.grid[rowP][tc].type === type) {
                countH++; tc--;
            }

            tc = colP + 1;
            while (tc < this.cols && this.grid[rowP][tc] && this.grid[rowP][tc].type === type) {
                countH++; tc++;
            }
            if (countH >= 3) {
                return true;
            }

            tr = rowP - 1;
            while (tr >= 0 && this.grid[tr][colP] && this.grid[tr][colP].type === type) {
                countV++; tr--;
            }

            tr = rowP + 1;
            while (tr < this.rows && this.grid[tr][colP] && this.grid[tr][colP].type === type) {
                countV++; tr++;
            }

            return countV >= 3;
        }

        /**
         * Finds a valid swap that produces a match, optionally restricted to a piece type.
         *
         * @param {number|null} onlyType When set, only returns a swap whose resulting match uses this piece type.
         * @returns {{p1: object, p2: object}|null} The two pieces to swap, or null when none exist.
         */
        findMove(onlyType = null) {
            for (let r = 0; r < this.rows; r++) {
                for (let c = 0; c < this.cols; c++) {
                    let temp;
                    if (c < this.cols - 1) {
                        temp = this.grid[r][c].type;
                        this.grid[r][c].type = this.grid[r][c + 1].type;
                        this.grid[r][c + 1].type = temp;
                        const matchR = this.isMatchAt(r, c, onlyType) || this.isMatchAt(r, c + 1, onlyType);

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
                        const matchD = this.isMatchAt(r, c, onlyType) || this.isMatchAt(r + 1, c, onlyType);

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
                const hintMove = this.findMove();
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
            const me = this.scene;
            const notice = me.add.text(this.L.w / 2, this.L.h / 2, this.strings.shuffling, {
                fontSize: '32px', fill: '#ffffff', backgroundColor: '#000000',
                align: 'center', fontStyle: 'bold', padding: {x: 20, y: 20}
            }).setOrigin(0.5).setDepth(100);

            const types = [];
            for (let r = 0; r < this.rows; r++) {
                for (let c = 0; c < this.cols; c++) {
                    types.push(this.grid[r][c].type);
                }
            }

            const hasInitialMatch = () => {
                const toDestroy = [];
                this.checkHorizontal(toDestroy, []);
                this.checkVertical(toDestroy, []);
                return toDestroy.length > 0;
            };

            do {
                Phaser.Utils.Array.Shuffle(types);
                let idx = 0;
                for (let r2 = 0; r2 < this.rows; r2++) {
                    for (let c2 = 0; c2 < this.cols; c2++) {
                        this.grid[r2][c2].type = types[idx];
                        this.grid[r2][c2].setTexture(`item${types[idx]}`);
                        idx++;
                    }
                }
            } while (!this.hasAvailableMove() || hasInitialMatch());

            for (let r3 = 0; r3 < this.rows; r3++) {
                for (let c3 = 0; c3 < this.cols; c3++) {
                    const shufflePiece = this.grid[r3][c3];
                    shufflePiece.alpha = 0;
                    me.tweens.add({
                        targets: shufflePiece, alpha: 1, duration: 500, delay: Math.random() * 400
                    });
                }
            }

            me.time.delayedCall(1200, () => {
                notice.destroy();
                if (me.combat.currentTurn === 'player') {
                    me.input.enabled = true;
                    this.resetHint();
                } else {
                    me.combat.executeBossTurn();
                }
            });
        }

        applyGravity() {
            const me = this.scene;
            let col, row, r, falling, piece, x, yStart, yEnd, randomType;

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

                    piece = me.add.image(x, yStart, `item${randomType}`);
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
            const me = this.scene;
            const toDestroy = [];
            const matchGroups = [];

            this.checkHorizontal(toDestroy, matchGroups);
            this.checkVertical(toDestroy, matchGroups);

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
                    if (me.combat.passTurnToPlayer()) {
                        return;
                    }
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

            const effects = me.combat.processEffects(toDestroy, matchGroups);
            const damage = effects.damage;

            if (damage > 0) {
                const scaledDamage = Math.round(
                    damage * (me.combat.currentTurn === 'player' ? me.combat.playerMultiplier : 1)
                );
                if (me.combat.currentTurn === 'player') {
                    me.combat.applyDamageToBoss(scaledDamage);
                } else {
                    me.combat.applyDamageToPlayer(scaledDamage);
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
