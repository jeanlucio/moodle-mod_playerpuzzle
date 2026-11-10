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
 * Draws the match-3 board with elements on the stage: the frame and the grid, the pieces and their animations, and
 * the pointer and touch handling over them. board.js decides what happens on the board and tells this module what to
 * show; this module decides nothing and keeps no rules. It answers to the same calls as the canvas board view.
 *
 * Three things stand over each other, from the back: the frame (one drawing), the pieces (images moved by the stage's
 * tweens, hidden from assistive technology) and the grid of cells the board builds for the keyboard and the screen
 * reader, which is also what the pointer presses. So what is seen and what is operated are the same cells.
 *
 * @module     mod_playerpuzzle/board_view
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['mod_playerpuzzle/stage/stage'], (Stage) => {
    'use strict';

    // The board frame's bronze, the same tones as the panels' carved inlay and the HP bars' rim.
    const BRONZE = '#9c6b2e';
    const BRONZE_LIGHT = '#d9a653';
    const BRONZE_SHADOW = '#2a1b0c';
    const SVG_NS = 'http://www.w3.org/2000/svg';

    /**
     * Adds a shape to a drawing.
     *
     * @param {Element} parent The drawing.
     * @param {string} tag 'rect', 'circle' or 'path'.
     * @param {object} attributes Its attributes.
     */
    const shape = (parent, tag, attributes) => {
        const element = document.createElementNS(SVG_NS, tag);
        Object.keys(attributes).forEach(key => element.setAttribute(key, attributes[key]));
        parent.appendChild(element);
    };

    class BoardView {
        /**
         * @param {object} scene The scene: its stage, its tweens and whether its input is on.
         * @param {object} layout The layout: boardOffX, boardOffY, panelOverlap, w, h.
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
            this.drawnSize = this.pieceSize - 4;
            this.offsetX = layout.boardOffX;
            this.offsetY = layout.boardOffY;
            this.sprites = new Map();
            this.hintSprites = null;

            const middle = scene.stage.layers.middle;
            this.drawBackground(middle);
            this.layer = document.createElement('div');
            this.layer.className = 'pp-board-pieces';
            this.layer.setAttribute('aria-hidden', 'true');
            middle.appendChild(this.layer);
            this.setupInputs(middle);
        }

        /**
         * The frame of the board: a soft shadow around it, the dark plate the pieces stand on, the bronze frame with
         * a rivet at each corner, and the lines between the cells.
         *
         * @param {HTMLElement} parent The layer it goes into.
         */
        drawBackground(parent) {
            const gridWidth = this.cols * this.pieceSize;
            const gridHeight = this.rows * this.pieceSize;
            const rx = this.offsetX - (this.pieceSize / 2);
            const ry = this.offsetY - (this.pieceSize / 2);
            const overlap = this.L.panelOverlap || 0;
            const margin = 12;

            const drawing = document.createElementNS(SVG_NS, 'svg');
            drawing.setAttribute('class', 'pp-el pp-board-frame');
            drawing.setAttribute('aria-hidden', 'true');
            drawing.setAttribute('viewBox', [
                rx - margin, ry - margin, gridWidth + (2 * margin), gridHeight + (2 * margin) + overlap,
            ].join(' '));
            Stage.place(drawing, {
                x: rx - margin, y: ry - margin, w: gridWidth + (2 * margin), h: gridHeight + (2 * margin) + overlap,
            });
            const rect = (x, y, width, height, fill, opacity = 1) => shape(drawing, 'rect', {
                x, y, width, height, fill, 'fill-opacity': opacity,
            });

            // A soft shadow behind the board, a few rectangles growing and fading: it lets the board read as its own
            // object in front of the side panels, which are a busy texture as dark as it is.
            for (let i = 4; i >= 1; i--) {
                rect(rx - (i * 3), ry - (i * 3), gridWidth + (i * 6), gridHeight + (i * 6) + overlap, '#000', 0.08 * i);
            }

            // The plate goes on past the grid's bottom edge by the overlap, so the gap the raised board opens before the
            // bottom of the stage stays covered. A warm dark brown, dark enough for the pieces' own colours to keep
            // their contrast, that ties the board to the leather and stone of the panels.
            rect(rx, ry, gridWidth, gridHeight + overlap, '#2b1f16', 0.95);

            // The bronze frame, each band between two distances from the grid's edge, the later bands over the earlier
            // ones. The light band is the lit edge: it covers the top and the sides down to the grid's bottom and
            // leaves the bottom bar dark.
            const band = (from, to, color, lit = false) => {
                const height = gridHeight + (lit ? to : 2 * to);
                rect(rx - to, ry - to, gridWidth + (2 * to), to - from, color);
                rect(rx - to, ry - to, to - from, height, color);
                rect(rx + gridWidth + from, ry - to, to - from, height, color);
                if (!lit) {
                    rect(rx - to, ry + gridHeight + from, gridWidth + (2 * to), to - from, color);
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
                    shape(drawing, 'circle', {cx: studX, cy: studY, r: 6, fill: BRONZE_SHADOW});
                    shape(drawing, 'circle', {cx: studX, cy: studY, r: 4, fill: BRONZE_LIGHT});
                    shape(drawing, 'circle', {cx: studX + 1, cy: studY + shine, r: 2, fill: BRONZE});
                });
            });
            shape(drawing, 'rect', {
                x: rx, y: ry, width: gridWidth, height: gridHeight, fill: 'none', stroke: '#111', 'stroke-width': 6,
            });
            let lines = '';
            for (let i = 1; i < this.rows; i++) {
                lines += `M ${rx} ${ry + (i * this.pieceSize)} H ${rx + gridWidth} `;
                lines += `M ${rx + (i * this.pieceSize)} ${ry} V ${ry + gridHeight} `;
            }
            shape(drawing, 'path', {d: lines, fill: 'none', stroke: '#333', 'stroke-opacity': 0.4, 'stroke-width': 2});
            parent.appendChild(drawing);
        }

        /**
         * Where a cell is, as the centre of its piece.
         *
         * @param {number} row Row.
         * @param {number} col Column.
         * @return {{x: number, y: number}}
         */
        cellCenter(row, col) {
            return {x: this.offsetX + (col * this.pieceSize), y: this.offsetY + (row * this.pieceSize)};
        }

        /**
         * Puts a sprite's element where its numbers say: its centre at x and y, its size as a share of the drawn
         * size, and its opacity.
         *
         * @param {object} sprite The sprite.
         */
        draw(sprite) {
            const half = this.drawnSize / 2;
            sprite.element.style.transform = `translate(${sprite.x - half}px, ${sprite.y - half}px) scale(${sprite.size})`;
            sprite.element.style.opacity = String(sprite.alpha);
        }

        /**
         * Draws a piece at its cell, or, when startRow is given, in that row of the same column (above the board for
         * a piece that falls in).
         *
         * @param {{type: number, row: number, col: number}} piece The piece.
         * @param {number} [startRow] Row to draw it in, a cell the board may not have.
         */
        add(piece, startRow = piece.row) {
            const element = document.createElement('img');
            element.className = 'pp-board-piece';
            element.src = this.scene.imageUrl(`item${piece.type}`);
            element.alt = '';
            element.draggable = false;
            const sprite = {
                element,
                x: this.cellCenter(piece.row, piece.col).x,
                y: this.offsetY + (startRow * this.pieceSize),
                size: 1,
                alpha: 1,
            };
            this.draw(sprite);
            this.layer.appendChild(element);
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
            return {x: sprite.x, y: sprite.y, size: this.drawnSize * sprite.size};
        }

        /**
         * Darkens a piece by a grey, or clears it. A grey tint multiplies every colour of the picture by the same
         * share, which is what a brightness filter does.
         *
         * @param {object} piece The piece.
         * @param {?number} color The grey, as a number such as 0xaaaaaa, or null for none.
         */
        tint(piece, color) {
            const sprite = this.sprites.get(piece);
            if (!sprite) {
                return;
            }
            // The blue byte: a grey has the same three.
            sprite.element.style.filter = color === null ? '' : `brightness(${(color % 256) / 255})`;
        }

        /**
         * Starts a tween on sprites, which are redrawn as it moves them.
         *
         * @param {object|object[]} sprites One sprite or several.
         * @param {object} config The tween, without its targets.
         */
        tween(sprites, config) {
            const list = Array.isArray(sprites) ? sprites : [sprites];
            this.scene.tweens.add({...config, targets: sprites, onUpdate: () => list.forEach(sprite => this.draw(sprite))});
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
            const to = {a: {x: b.x, y: b.y}, b: {x: a.x, y: a.y}};
            this.tween(a, {...to.a, duration: 200});
            this.tween(b, {...to.b, duration: 200, onComplete: done});
        }

        /**
         * Shrinks a piece away and takes it off the board.
         *
         * @param {object} piece The piece.
         */
        pop(piece) {
            const sprite = this.sprites.get(piece);
            this.sprites.delete(piece);
            this.tween(sprite, {size: 0, duration: 200, onComplete: () => sprite.element.remove()});
        }

        /**
         * Lets a piece fall to the row it now has.
         *
         * @param {object} piece The piece.
         */
        fall(piece) {
            this.tween(this.sprites.get(piece), {
                y: this.offsetY + (piece.row * this.pieceSize), duration: 250, ease: 'Quad.easeIn',
            });
        }

        /**
         * Brings a new piece in from above the board and lets it bounce into its row.
         *
         * @param {object} piece The piece.
         */
        spawn(piece) {
            this.add(piece, piece.row - this.rows);
            this.tween(this.sprites.get(piece), {
                y: this.offsetY + (piece.row * this.pieceSize), duration: 400, ease: 'Bounce.easeOut',
            });
        }

        /**
         * Shows the piece's type again, after the board changed it.
         *
         * @param {object} piece The piece.
         */
        retype(piece) {
            this.sprites.get(piece).element.src = this.scene.imageUrl(`item${piece.type}`);
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
            this.draw(sprite);
            this.tween(sprite, {alpha: 1, duration, delay});
        }

        /**
         * Makes two pieces swell and shrink, to show a move that works.
         *
         * @param {object} first One piece.
         * @param {object} second The other.
         */
        startHint(first, second) {
            this.hintSprites = [this.sprites.get(first), this.sprites.get(second)];
            this.tween(this.hintSprites, {
                size: (this.pieceSize + 6) / this.drawnSize, yoyo: true, repeat: -1, duration: 400,
            });
        }

        /**
         * Stops the hint and puts the two pieces back at their size.
         */
        stopHint() {
            if (this.hintSprites === null) {
                return;
            }
            this.scene.tweens.killTweensOf(this.hintSprites);
            this.hintSprites.forEach(sprite => {
                sprite.size = 1;
                this.draw(sprite);
            });
            this.hintSprites = null;
        }

        /**
         * Puts a message over the middle of the stage.
         *
         * @param {string} text The message.
         * @return {{destroy: Function}} Something to take the message away with.
         */
        notice(text) {
            const element = document.createElement('div');
            element.className = 'pp-board-notice';
            element.textContent = text;
            Stage.place(element, {x: this.L.w / 2, y: this.L.h / 2});
            this.scene.stage.layers.overlay.appendChild(element);
            return {destroy: () => element.remove()};
        }

        /**
         * The piece standing in a cell.
         *
         * @param {number} row Row.
         * @param {number} col Column.
         * @return {?object}
         */
        pieceAt(row, col) {
            for (const piece of this.sprites.keys()) {
                if (piece.row === row && piece.col === col) {
                    return piece;
                }
            }
            return null;
        }

        /**
         * Puts the grid of cells over the pieces and listens to the pointer on it: a press on a cell, the release
         * anywhere, and a touch that must not scroll the page. Nothing is heard while the game has its input off.
         *
         * @param {HTMLElement} parent The layer the cells go into.
         */
        setupInputs(parent) {
            const table = document.getElementById('pp-board-grid');
            if (!table) {
                return;
            }
            const half = this.pieceSize / 2;
            table.classList.add('pp-board-cells');
            table.style.setProperty('--pp-board-rows', String(this.rows));
            table.style.setProperty('--pp-board-cols', String(this.cols));
            Stage.place(table, {
                x: this.offsetX - half, y: this.offsetY - half, w: this.cols * this.pieceSize, h: this.rows * this.pieceSize,
            });
            parent.appendChild(table);

            const point = event => this.scene.stage.toDesign(event.clientX, event.clientY);
            table.addEventListener('pointerdown', event => {
                const cell = event.target.closest('td');
                if (!cell || !event.isPrimary || !this.scene.input.enabled) {
                    return;
                }
                const piece = this.pieceAt(parseInt(cell.dataset.row, 10), parseInt(cell.dataset.col, 10));
                if (piece) {
                    this.handlers.onPress(piece, point(event));
                }
            });
            const release = event => {
                if (event.isPrimary && this.scene.input.enabled) {
                    this.handlers.onRelease(point(event));
                }
            };
            document.addEventListener('pointerup', release);
            document.addEventListener('pointercancel', release);

            // A touch that starts on the board in the student's turn must not scroll the page, or a swipe would move
            // the page instead of the piece; anywhere else on the stage, and out of the student's turn, it scrolls.
            // Not passive, so that preventing the default has any effect.
            table.addEventListener('touchstart', event => {
                if (this.handlers.isPlayersTurn() && event.touches.length === 1) {
                    event.preventDefault();
                }
            }, {passive: false});

            // The cell with the focus is outlined only while the keyboard is what is being used, so whoever plays with
            // the mouse or the finger never sees it.
            document.addEventListener('keydown', () => table.classList.add('pp-board-keyboard'));
            document.addEventListener('pointerdown', () => table.classList.remove('pp-board-keyboard'));
        }
    }

    return BoardView;
});
