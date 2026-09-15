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

define(['mod_playerpuzzle/accessibility'], function(Accessibility) {
    'use strict';

    // Maps a piece's numeric type (0-6) to the lang string key holding its accessible
    // name, read by the hidden <table role="grid"> the screen reader explores.
    const PIECE_NAME_KEYS = [
        'piece_star', 'piece_grimoire', 'piece_orb', 'piece_sword',
        'piece_shield', 'piece_potion', 'piece_coin'
    ];

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

            this.a11yCells = null;

            this.drawBackground();
            this.buildAccessibleGrid();
            this.initGrid();
            this.syncAccessibleGrid();
            this.setupInputs();

            // Moves real keyboard focus straight to the accessible board the moment it loads,
            // rather than leaving a keyboard/screen-reader user to tab past the rest of the
            // page chrome to find it. preventScroll avoids jumping the (invisible) page to this
            // element's position, which would otherwise disorient a sighted keyboard user.
            if (this.a11yCells) {
                this.a11yCells[0][0].focus({preventScroll: true});
            }

            if (this.scene.combat.currentTurn === 'player') {
                this.announceTurnStart();
            }

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
            // Bronze/gold accent line, sampled directly from panel_stone.png's own inlay
            // color — frames the board as a distinct object instead of blending into the
            // panels, which share a similarly dark palette now.
            graphics.lineStyle(2, 0x9c6b2e, 0.8).strokeRect(rx - 4, ry - 4, gridWidth + 8, gridHeight + 8);
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
            // A checkpointed fight reuses its exact saved piece types instead of rolling a
            // fresh board — the reload is meant to resume the same position, not hand the
            // player a new one (which could form matches, or remove ones already set up,
            // the moment the board loads).
            const combatstate = me.combat && me.combat.gameConfig.combatstate;
            const savedgrid = combatstate ? combatstate.boardgrid : null;

            for (let row = 0; row < this.rows; row++) {
                this.grid[row] = [];
                for (let col = 0; col < this.cols; col++) {
                    const randomType = savedgrid
                        ? savedgrid[(row * this.cols) + col]
                        : this.pickTypeAvoidingMatch(row, col);

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

        /**
         * Picks a random piece type for a freshly-generated cell (never used when resuming a
         * checkpointed board), re-rolling until it does not complete a 3-in-a-row with the
         * two cells already placed above it or to its left.
         *
         * @param {number} row Row being filled.
         * @param {number} col Column being filled.
         * @return {number} A piece type, 0-6.
         */
        pickTypeAvoidingMatch(row, col) {
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

            return randomType;
        }

        /**
         * Builds the hidden <table role="grid"> that mirrors the board for screen reader
         * users — a secondary exploration resource (arrow keys move a roving tabindex
         * cell to cell), not the main gameplay flow. Built once; syncAccessibleGrid()
         * keeps its cell text in step with the real board afterwards.
         *
         * @return {void}
         */
        buildAccessibleGrid() {
            const body = document.getElementById('pp-board-grid-body');
            if (!body) {
                return;
            }

            this.a11yCells = [];
            for (let row = 0; row < this.rows; row++) {
                const tr = document.createElement('tr');
                tr.setAttribute('role', 'row');
                this.a11yCells[row] = [];

                for (let col = 0; col < this.cols; col++) {
                    const td = document.createElement('td');
                    td.setAttribute('role', 'gridcell');
                    td.setAttribute('aria-rowindex', String(row + 1));
                    td.setAttribute('aria-colindex', String(col + 1));
                    td.tabIndex = (row === 0 && col === 0) ? 0 : -1;
                    td.dataset.row = String(row);
                    td.dataset.col = String(col);
                    td.addEventListener('keydown', this.handleGridKeydown.bind(this));
                    tr.appendChild(td);
                    this.a11yCells[row][col] = td;
                }

                body.appendChild(tr);
            }
        }

        /**
         * Identifies which arrow direction, if any, a keydown event represents. Checks
         * event.key first (both the modern 'ArrowX' names and the pre-standardization 'X'
         * ones some WebDriver implementations and older assistive tech still produce), falling
         * back to event.code (the physical key, tied to the USB HID usage table and far more
         * consistent across browsers/drivers) when event.key is something unrecognized.
         *
         * @param {KeyboardEvent} event The keydown event.
         * @return {string|null} One of 'up'/'down'/'left'/'right', or null when not an arrow key.
         */
        identifyArrowKey(event) {
            if (event.key === 'ArrowUp' || event.key === 'Up' || event.code === 'ArrowUp') {
                return 'up';
            }
            if (event.key === 'ArrowDown' || event.key === 'Down' || event.code === 'ArrowDown') {
                return 'down';
            }
            if (event.key === 'ArrowLeft' || event.key === 'Left' || event.code === 'ArrowLeft') {
                return 'left';
            }
            if (event.key === 'ArrowRight' || event.key === 'Right' || event.code === 'ArrowRight') {
                return 'right';
            }
            return null;
        }

        /**
         * Moves the roving tabindex focus between grid cells on arrow-key presses, and doubles
         * as the entry point for two other keyboard affordances scoped to the same accessible
         * grid: digits 1-9 execute the corresponding move from the list last read out by
         * announceTurnStart(), and Space re-reads that list without acting. Native browser
         * table navigation is unavailable once role="grid" opts the element out of browse-mode
         * reading (ARIA grid pattern), so this JS is what the APG spec expects a grid widget to
         * provide itself for the arrow-key part.
         *
         * @param {KeyboardEvent} event The keydown event, targeted at the currently focused cell.
         * @return {void}
         */
        handleGridKeydown(event) {
            if (event.key === ' ') {
                event.preventDefault();
                this.announceTurnStart();
                return;
            }

            const digit = parseInt(event.key, 10);
            if (!isNaN(digit) && digit >= 1 && digit <= 9 && String(digit) === event.key) {
                event.preventDefault();
                this.executeAnnouncedMove(digit - 1);
                return;
            }

            const cell = event.currentTarget;
            const row = parseInt(cell.dataset.row, 10);
            const col = parseInt(cell.dataset.col, 10);
            let newRow = row;
            let newCol = col;

            // Identifies the arrow pressed from whichever of event.key/event.code is
            // meaningful: event.key covers the modern DOM4 names plus the pre-standardization
            // ones ('Up'/'Down'/'Left'/'Right', without the 'Arrow' prefix) some WebDriver
            // implementations and older assistive tech still produce; event.code (the
            // physical key, tied to the USB HID usage table) is far more consistent across
            // browsers/drivers for navigation keys and serves as a fallback when event.key
            // comes through as something unexpected.
            const direction = this.identifyArrowKey(event);

            switch (direction) {
                case 'up':
                    newRow = Math.max(0, row - 1);
                    break;
                case 'down':
                    newRow = Math.min(this.rows - 1, row + 1);
                    break;
                case 'left':
                    newCol = Math.max(0, col - 1);
                    break;
                case 'right':
                    newCol = Math.min(this.cols - 1, col + 1);
                    break;
                default:
                    return;
            }

            event.preventDefault();
            if (newRow === row && newCol === col) {
                return;
            }

            this.a11yCells[row][col].tabIndex = -1;
            const target = this.a11yCells[newRow][newCol];
            target.tabIndex = 0;
            target.focus();
        }

        /**
         * Refreshes every accessible grid cell's text from the current board state. Called
         * once the board has settled into a new stable arrangement — after the initial
         * deal, after a swap, and after gravity refills empty cells — never mid-animation,
         * when this.grid can briefly hold nulls for pieces still fading out.
         *
         * @return {void}
         */
        syncAccessibleGrid() {
            if (!this.a11yCells) {
                return;
            }

            for (let row = 0; row < this.rows; row++) {
                for (let col = 0; col < this.cols; col++) {
                    const piece = this.grid[row][col];
                    if (!piece) {
                        continue;
                    }
                    this.a11yCells[row][col].textContent = this.strings[PIECE_NAME_KEYS[piece.type]];
                }
            }
        }

        setupInputs() {
            // Phaser's own touch.capture is off (game_boot.js) so this is the only place that
            // blocks the browser's default touch handling — and only for a touch that starts
            // on an actual board piece, so scrolling still works everywhere else on the canvas
            // (HUD, panels, margins). Added directly on the canvas element, not through
            // Phaser's input plugin, specifically so it stays non-passive and preventDefault()
            // has any effect (28/08/2026).
            this.scene.game.canvas.addEventListener(
                'touchstart', event => this.maybeBlockScroll(event), {passive: false}
            );

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

        /**
         * Cancels the browser's default touch handling (scroll, pinch-zoom) only when a touch
         * starts inside the board's own screen rectangle — anywhere else on the canvas keeps
         * native scrolling (see setupInputs()'s own comment for why this listener exists at
         * the DOM level instead of through Phaser). Reimplements the canvas' real-to-logical
         * pixel conversion directly from its bounding rect rather than going through Phaser's
         * pointer system, since this fires on the raw touchstart, before Phaser has processed
         * it into a Pointer object.
         *
         * @param {TouchEvent} event Native touchstart event from the canvas element.
         */
        maybeBlockScroll(event) {
            if (this.scene.combat.currentTurn !== 'player' || event.touches.length !== 1) {
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
                        this.syncAccessibleGrid();
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

        /**
         * Computes the length of the match line (horizontal or vertical) passing through the
         * given cell, for whichever piece type currently sits there. Same counting approach as
         * isMatchAt(), but returns the actual run length instead of a boolean, so evaluateSwap()
         * can report which piece type a candidate swap would match.
         *
         * @param {number} rowP Row index.
         * @param {number} colP Column index.
         * @returns {number} Length of the run at this cell, or 0 when no match exists.
         */
        matchRunLengthAt(rowP, colP) {
            const p = this.grid[rowP][colP];
            if (!p) {
                return 0;
            }

            const {type} = p;
            let countH = 1;
            let tc = colP - 1;
            while (tc >= 0 && this.grid[rowP][tc] && this.grid[rowP][tc].type === type) {
                countH++; tc--;
            }
            tc = colP + 1;
            while (tc < this.cols && this.grid[rowP][tc] && this.grid[rowP][tc].type === type) {
                countH++; tc++;
            }
            if (countH >= 3) {
                return countH;
            }

            let countV = 1;
            let tr = rowP - 1;
            while (tr >= 0 && this.grid[tr][colP] && this.grid[tr][colP].type === type) {
                countV++; tr--;
            }
            tr = rowP + 1;
            while (tr < this.rows && this.grid[tr][colP] && this.grid[tr][colP].type === type) {
                countV++; tr++;
            }
            return countV >= 3 ? countV : 0;
        }

        /**
         * Swaps two cells in-place, checks whether either resulting cell matches, then reverts
         * — the same temporary-mutate-and-revert pattern as findMove(), kept as separate code
         * to avoid touching that already-tested core logic. Used only for the accessible
         * turn-start announcement (announceTurnStart()), which needs the matched piece type,
         * not just whether a match exists.
         *
         * @param {number} r1 Row of the first cell.
         * @param {number} c1 Column of the first cell.
         * @param {number} r2 Row of the second cell.
         * @param {number} c2 Column of the second cell.
         * @returns {number|null} The piece type that would match, or null when this swap has no effect.
         */
        evaluateSwap(r1, c1, r2, c2) {
            const temp = this.grid[r1][c1].type;
            this.grid[r1][c1].type = this.grid[r2][c2].type;
            this.grid[r2][c2].type = temp;

            let matchedType = null;
            if (this.matchRunLengthAt(r1, c1) >= 3) {
                matchedType = this.grid[r1][c1].type;
            } else if (this.matchRunLengthAt(r2, c2) >= 3) {
                matchedType = this.grid[r2][c2].type;
            }

            const revert = this.grid[r1][c1].type;
            this.grid[r1][c1].type = this.grid[r2][c2].type;
            this.grid[r2][c2].type = revert;

            return matchedType;
        }

        /**
         * Enumerates the currently valid swaps, up to limit, for the accessible turn-start
         * announcement. Kept separate from findMove()/hasAvailableMove() (which only need the
         * first match, for the idle hint and the post-shuffle validity check) since this needs
         * every move up to a cap, not just one.
         *
         * @param {number} limit Maximum number of moves to collect.
         * @returns {Array} Each found move's coordinates and matched piece type.
         */
        findAllMoves(limit = 9) {
            const moves = [];
            for (let r = 0; r < this.rows && moves.length < limit; r++) {
                for (let c = 0; c < this.cols && moves.length < limit; c++) {
                    if (c < this.cols - 1) {
                        const type = this.evaluateSwap(r, c, r, c + 1);
                        if (type !== null) {
                            moves.push({r1: r, c1: c, r2: r, c2: c + 1, type});
                        }
                    }
                    if (moves.length < limit && r < this.rows - 1) {
                        const type = this.evaluateSwap(r, c, r + 1, c);
                        if (type !== null) {
                            moves.push({r1: r, c1: c, r2: r + 1, c2: c, type});
                        }
                    }
                }
            }
            return moves;
        }

        /**
         * Posts the accessible "your turn" announcement, enumerating up to 9 available moves by
         * piece type. Only called while it is actually the player's turn. The move list is
         * stored on this.announcedMoves so executeAnnouncedMove() (bound to keys 1-9 on the
         * accessible grid) can execute one of these moves directly instead of dragging/tapping.
         */
        announceTurnStart() {
            const moves = this.findAllMoves(9);
            this.announcedMoves = moves;

            const intro = this.strings.turnstart_intro.replace('{$a}', moves.length);
            const lines = moves.map((move, index) => this.strings.turnstart_move
                .replace('{$a->index}', String(index + 1))
                .replace('{$a->piece}', this.strings[`${PIECE_NAME_KEYS[move.type]}_plural`]));

            Accessibility.announce([intro, ...lines].join(' '));
        }

        /**
         * Executes the Nth move from the list last posted by announceTurnStart(), so a keyboard
         * user can act on what was just read out loud without needing precise pointer control
         * over the (visually-hidden, for this purpose) canvas board. A no-op outside the
         * player's own turn, once input is disabled mid-swap, or when the index is out of range
         * (e.g. the player pressed a digit higher than the announced move count).
         *
         * @param {number} index Zero-based index into this.announcedMoves.
         * @return {void}
         */
        executeAnnouncedMove(index) {
            if (this.scene.combat.currentTurn !== 'player' || !this.scene.input.enabled) {
                return;
            }
            if (!this.announcedMoves || !this.announcedMoves[index]) {
                return;
            }

            const move = this.announcedMoves[index];
            const p1 = this.grid[move.r1][move.c1];
            const p2 = this.grid[move.r2][move.c2];
            if (p1 && p2) {
                this.swapPieces(p1, p2);
            }
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

            this.syncAccessibleGrid();

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
                    this.announceTurnStart();
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

            this.syncAccessibleGrid();
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
                        this.announceTurnStart();
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
