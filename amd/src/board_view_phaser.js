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
/**
 * Draws the match-3 board on the Phaser scene: the frame and the grid, the piece sprites and their animations, and the
 * pointer and touch handling over them. board.js decides what happens on the board and tells this module what to
 * show; this module decides nothing and keeps no rules.
 *
 * The board keeps its pieces as plain models ({type, row, col}); this module owns the sprite of each one. Every
 * animation method takes the models it moves, and the ones the board waits for take a callback.
 *
 * @module     mod_playerpuzzle/board_view_phaser
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['mod_playerpuzzle/depth'], (Depth) => {
    'use strict';

    // The board frame's bronze, the same tones as the panels' carved inlay and the HP bars' rim.
    const BRONZE = 0x9c6b2e;
    const BRONZE_LIGHT = 0xd9a653;
    const BRONZE_SHADOW = 0x2a1b0c;

    class BoardViewPhaser {
        /**
         * @param {Phaser.Scene} scene The scene the board is drawn on.
         * @param {object} layout The layout: boardOffX, boardOffY, panelOverlap, w.
         * @param {object} handlers What the view tells the board.
         * @param {Function} handlers.onPress Called with (piece, {x, y}) when a piece is pressed; x and y are in the
         *  coordinates of the game's own layout.
         * @param {Function} handlers.onRelease Called with ({x, y}) when the pointer is released anywhere.
         * @param {Function} handlers.isPlayersTurn Whether it is the student's turn, which is when a touch on the
         *  board must not scroll the page.
         * @param {number} rows Board rows.
         * @param {number} cols Board columns.
         */
        constructor(scene, layout, handlers, rows, cols) {
            this.scene = scene;
            this.L = layout;
            this.handlers = handlers;
            this.rows = rows;
            this.cols = cols;
            this.pieceSize = 55;
            this.offsetX = layout.boardOffX;
            this.offsetY = layout.boardOffY;
            this.sprites = new WeakMap();
            this.hintTweens = null;

            this.drawBackground();
            this.setupInputs();
        }

        drawBackground() {
            const graphics = this.scene.add.graphics().setDepth(Depth.MIDDLE_SHAPES);
            const gridWidth = this.cols * this.pieceSize;
            const gridHeight = this.rows * this.pieceSize;
            const rx = this.offsetX - (this.pieceSize / 2);
            const ry = this.offsetY - (this.pieceSize / 2);
            const overlap = this.L.panelOverlap || 0;

            // Soft shadow halo behind the board — a few expanding, fading rects standing in
            // for a blur (Phaser Graphics has no native blur filter) — helps the board read as
            // its own object in front of the side panels now that panel_stone.png is a busy,
            // similarly dark texture there, instead of the flat contrast a plain dark panel
            // background gave for free.
            for (let i = 4; i >= 1; i--) {
                graphics.fillStyle(0x000000, 0.08 * i).fillRect(
                    rx - (i * 3), ry - (i * 3), gridWidth + (i * 6), gridHeight + (i * 6) + overlap
                );
            }

            // The offsetY passed in (boardOffY) is already shifted up by panelOverlap at the
            // source, in game_boot.js — the grid itself rises into the stage band above, not
            // just a decorative strip glued on top of it, so real pieces sit in the overlap
            // and the border/grid lines below trace their true position with no extra math
            // here. The fill extends panelOverlap further down past the grid's real bottom
            // edge, so the gap that shift opens up before the true panel/canvas bottom (720)
            // stays covered instead of showing bare canvas. Alpha bumped from the original
            // 0.85 to 0.95: at 0.85, the pieces now sitting in the overlap read against the
            // bright stage_bg image behind them, and enough of it bled through the gaps
            // between pieces to look like a brown stripe instead of a solid platform. Warm
            // dark brown (was pure black) — ties the board into the same leather/stone palette
            // as panel_stone.png instead of reading as a flat black cutout, while staying dark
            // enough for the piece icons' own colors to keep full contrast.
            graphics.fillStyle(0x2b1f16, 0.95).fillRect(rx, ry, gridWidth, gridHeight + overlap);
            // A bronze frame around the board, drawn over the panels' inner edge (the board's depth is above
            // the panel art's) so it reads as a raised plate between them. It closes under the grid with
            // a bar as thick as the top one, ending just above the canvas bottom, where the fill above
            // goes on for the last few pixels. Each band lies between two distances from the grid's
            // edge; the later bands sit on the earlier ones. The light band is the lit edge, so it
            // covers the top and the sides down to the grid's bottom and leaves the bottom bar dark.
            const band = (from, to, color, lit = false) => {
                const height = gridHeight + (lit ? to : 2 * to);
                graphics.fillStyle(color, 1);
                graphics.fillRect(rx - to, ry - to, gridWidth + (2 * to), to - from);
                graphics.fillRect(rx - to, ry - to, to - from, height);
                graphics.fillRect(rx + gridWidth + from, ry - to, to - from, height);
                if (!lit) {
                    graphics.fillRect(rx - to, ry + gridHeight + from, gridWidth + (2 * to), to - from);
                }
            };
            band(0, 9, BRONZE_SHADOW);
            band(1, 8, BRONZE);
            band(1, 3, BRONZE_LIGHT, true);
            band(7, 8, BRONZE_SHADOW);
            // A rivet at each corner, the bottom pair mirroring the top one.
            [ry - 4, ry + gridHeight + 4].forEach((studY, row) => {
                [rx - 4, rx + gridWidth + 4].forEach(studX => {
                    const shine = row === 0 ? 1 : -1;
                    graphics.fillStyle(BRONZE_SHADOW, 1).fillCircle(studX, studY, 6);
                    graphics.fillStyle(BRONZE_LIGHT, 1).fillCircle(studX, studY, 4);
                    graphics.fillStyle(BRONZE, 1).fillCircle(studX + 1, studY + shine, 2);
                });
            });
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

        /**
         * Where a cell is, as the center of its piece.
         *
         * @param {number} row Row.
         * @param {number} col Column.
         * @return {{x: number, y: number}}
         */
        cellCenter(row, col) {
            return {x: this.offsetX + (col * this.pieceSize), y: this.offsetY + (row * this.pieceSize)};
        }

        /**
         * Draws a piece at its cell, or, when startRow is given, in that row of the same column (above the board for
         * a piece that falls in).
         *
         * @param {{type: number, row: number, col: number}} piece The piece.
         * @param {number} [startRow] Row to draw it in, a cell the board may not have.
         */
        add(piece, startRow = piece.row) {
            const {x} = this.cellCenter(piece.row, piece.col);
            const y = this.offsetY + (startRow * this.pieceSize);
            const sprite = this.scene.add.image(x, y, `item${piece.type}`).setDepth(Depth.MIDDLE_IMAGES);
            sprite.setDisplaySize(this.pieceSize - 4, this.pieceSize - 4);
            sprite.setInteractive();
            sprite.on('pointerdown', pointer => this.handlers.onPress(piece, {x: pointer.worldX, y: pointer.worldY}));
            this.sprites.set(piece, sprite);
        }

        /**
         * Where a piece is drawn and how big, for what is thrown off it when it goes.
         *
         * @param {object} piece The piece.
         * @return {{x: number, y: number, size: number}}
         */
        metrics(piece) {
            const sprite = this.sprites.get(piece);
            return {x: sprite.x, y: sprite.y, size: sprite.displayWidth};
        }

        /**
         * Tints a piece, or clears the tint.
         *
         * @param {object} piece The piece.
         * @param {?number} color The tint, or null for none.
         */
        tint(piece, color) {
            const sprite = this.sprites.get(piece);
            if (color === null) {
                sprite.clearTint();
            } else {
                sprite.setTint(color);
            }
        }

        /**
         * Slides two pieces into each other's places.
         *
         * @param {object} first One piece.
         * @param {object} second The other.
         * @param {Function} done Called when they have arrived.
         */
        swap(first, second, done) {
            const a = this.sprites.get(first);
            const b = this.sprites.get(second);
            this.scene.tweens.add({targets: a, x: b.x, y: b.y, duration: 200});
            this.scene.tweens.add({targets: b, x: a.x, y: a.y, duration: 200, onComplete: done});
        }

        /**
         * Shrinks a piece away and takes it off the board.
         *
         * @param {object} piece The piece.
         */
        pop(piece) {
            const sprite = this.sprites.get(piece);
            this.sprites.delete(piece);
            this.scene.tweens.add({
                targets: sprite, scaleX: 0, scaleY: 0, duration: 200,
                onComplete: (tween, targets) => {
                    targets[0].destroy();
                }
            });
        }

        /**
         * Lets a piece fall to the row it now has.
         *
         * @param {object} piece The piece.
         */
        fall(piece) {
            this.scene.tweens.add({
                targets: this.sprites.get(piece),
                y: this.offsetY + (piece.row * this.pieceSize),
                duration: 250, ease: 'Quad.easeIn'
            });
        }

        /**
         * Brings a new piece in from above the board and lets it bounce into its row.
         *
         * @param {object} piece The piece.
         */
        spawn(piece) {
            this.add(piece, piece.row - this.rows);
            this.scene.tweens.add({
                targets: this.sprites.get(piece), y: this.offsetY + (piece.row * this.pieceSize),
                duration: 400, ease: 'Bounce.easeOut'
            });
        }

        /**
         * Shows the piece's type again, after the board changed it.
         *
         * @param {object} piece The piece.
         */
        retype(piece) {
            this.sprites.get(piece).setTexture(`item${piece.type}`);
        }

        /**
         * Hides a piece and fades it back in.
         *
         * @param {object} piece The piece.
         * @param {number} duration Milliseconds of the fade.
         * @param {number} delay Milliseconds before it starts.
         */
        fadeIn(piece, duration, delay) {
            const sprite = this.sprites.get(piece);
            sprite.alpha = 0;
            this.scene.tweens.add({targets: sprite, alpha: 1, duration, delay});
        }

        /**
         * Makes two pieces swell and shrink, to show a move that works.
         *
         * @param {object} first One piece.
         * @param {object} second The other.
         */
        startHint(first, second) {
            const targets = [this.sprites.get(first), this.sprites.get(second)];
            this.hintTweens = targets;
            this.scene.tweens.add({
                targets,
                displayWidth: this.pieceSize + 6,
                displayHeight: this.pieceSize + 6,
                yoyo: true, repeat: -1, duration: 400
            });
        }

        /**
         * Stops the hint and puts the two pieces back at their size.
         */
        stopHint() {
            if (this.hintTweens === null) {
                return;
            }
            this.scene.tweens.killTweensOf(this.hintTweens);
            this.hintTweens.forEach(sprite => sprite.setDisplaySize(this.pieceSize - 4, this.pieceSize - 4));
            this.hintTweens = null;
        }

        /**
         * Puts a message over the middle of the board.
         *
         * @param {string} text The message.
         * @return {{destroy: Function}} Something to take the message away with.
         */
        notice(text) {
            return this.scene.add.text(this.L.w / 2, this.L.h / 2, text, {
                fontSize: '32px', fill: '#ffffff', backgroundColor: '#000000',
                align: 'center', fontStyle: 'bold', padding: {x: 20, y: 20}
            }).setOrigin(0.5).setDepth(100);
        }

        /**
         * Listens to the pointer: its release anywhere, and a touch on the canvas.
         */
        setupInputs() {
            // Phaser's own touch.capture is off, so this is the only place that blocks the browser's default touch
            // handling, and only for a touch that starts on the board, so scrolling still works everywhere else on
            // the canvas (HUD, panels, margins). Added directly on the canvas element, not through Phaser's input
            // plugin, so it stays non-passive and preventDefault() has any effect.
            this.scene.game.canvas.addEventListener(
                'touchstart', event => this.maybeBlockScroll(event), {passive: false}
            );

            // The world coordinates, not the raw x and y: those are canvas pixels, which the camera zoom no longer
            // maps 1:1 to the layout's own coordinates.
            this.scene.input.on('pointerup', pointer => {
                this.handlers.onRelease({x: pointer.worldX, y: pointer.worldY});
            });
        }

        /**
         * Cancels the browser's default touch handling (scroll, pinch-zoom) only when a touch starts inside the
         * board's own screen rectangle; anywhere else on the canvas keeps native scrolling. Works the canvas's real
         * pixels out to the layout's own directly from its bounding rect, since this fires on the raw touchstart,
         * before Phaser has turned it into a pointer.
         *
         * @param {TouchEvent} event Native touchstart event from the canvas element.
         */
        maybeBlockScroll(event) {
            if (!this.handlers.isPlayersTurn() || event.touches.length !== 1) {
                return;
            }

            const rect = this.scene.game.canvas.getBoundingClientRect();
            const touch = event.touches[0];
            const scale = this.L.w / rect.width;
            const lx = (touch.clientX - rect.x) * scale;
            const ly = (touch.clientY - rect.y) * scale;

            const left = this.offsetX - (this.pieceSize / 2);
            const top = this.offsetY - (this.pieceSize / 2);
            const width = this.cols * this.pieceSize;
            const height = this.rows * this.pieceSize;

            if (lx >= left && lx <= left + width && ly >= top && ly <= top + height) {
                event.preventDefault();
            }
        }
    }

    return BoardViewPhaser;
});
