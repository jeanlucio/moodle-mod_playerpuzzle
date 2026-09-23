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

define(['mod_playerpuzzle/accessibility', 'mod_playerpuzzle/engine/board_rules'], function(Accessibility, BoardRules) {
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
            const types = BoardRules.generateGrid(this.rows, this.cols, savedgrid, me.combat.rng);

            for (let row = 0; row < this.rows; row++) {
                this.grid[row] = [];
                for (let col = 0; col < this.cols; col++) {
                    const randomType = types[row][col];

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
         * Builds a plain rows x cols array of piece types from the current Phaser grid — the
         * shape every mod_playerpuzzle/engine/board_rules function expects, since it never
         * touches Phaser objects. A destroyed-but-not-yet-refilled cell (null in this.grid
         * mid-cascade) stays null in the extracted grid too.
         *
         * @return {Array} The extracted types grid.
         */
        extractTypesGrid() {
            const types = [];
            for (let row = 0; row < this.rows; row++) {
                types[row] = [];
                for (let col = 0; col < this.cols; col++) {
                    const piece = this.grid[row][col];
                    types[row][col] = piece ? piece.type : null;
                }
            }
            return types;
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
                // Same gate as executeAnnouncedMove(): outside the player's own turn the move
                // list is not actionable, and reading "your turn" there would be a lie.
                if (this.scene.combat.currentTurn === 'player' && this.scene.input.enabled) {
                    this.announceTurnStart();
                }
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
            // has any effect.
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

            // A plain array-position swap works the same whether the grid holds piece types
            // (mod_playerpuzzle/engine/board_rules' own tests) or the real Phaser objects it
            // holds here — swapInGrid() never inspects what it is moving.
            BoardRules.swapInGrid(this.grid, piece1.row, piece1.col, piece2.row, piece2.col);

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
         * Runs both match scans against the current board, returning the same {toDestroy,
         * matchGroups} shape checkMatches() and shuffle()'s own retry condition both need.
         * Coordinates only (as mod_playerpuzzle/engine/board_rules always returns) — mapping
         * a coordinate back to the real Phaser piece it names is the caller's job.
         *
         * @return {{toDestroy: Array, matchGroups: Array}}
         */
        findMatches() {
            const types = this.extractTypesGrid();
            const toDestroy = [];
            const matchGroups = [];
            BoardRules.checkHorizontal(types, this.rows, this.cols, toDestroy, matchGroups);
            BoardRules.checkVertical(types, this.rows, this.cols, toDestroy, matchGroups);
            return {toDestroy, matchGroups};
        }

        /**
         * Finds a valid swap that produces a match, optionally restricted to a piece type.
         *
         * @param {number|null} onlyType When set, only returns a swap whose resulting match uses this piece type.
         * @returns {{p1: object, p2: object}|null} The two pieces to swap, or null when none exist.
         */
        findMove(onlyType = null) {
            const types = this.extractTypesGrid();
            const move = BoardRules.findMove(types, this.rows, this.cols, onlyType);
            if (!move) {
                return null;
            }
            return {p1: this.grid[move.r1][move.c1], p2: this.grid[move.r2][move.c2]};
        }

        hasAvailableMove() {
            return this.findMove() !== null;
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
            const types = this.extractTypesGrid();
            const moves = [];
            for (let r = 0; r < this.rows && moves.length < limit; r++) {
                for (let c = 0; c < this.cols && moves.length < limit; c++) {
                    if (c < this.cols - 1) {
                        const type = BoardRules.evaluateSwap(types, this.rows, this.cols, r, c, r, c + 1);
                        if (type !== null) {
                            moves.push({r1: r, c1: c, r2: r, c2: c + 1, type});
                        }
                    }
                    if (moves.length < limit && r < this.rows - 1) {
                        const type = BoardRules.evaluateSwap(types, this.rows, this.cols, r, c, r + 1, c);
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

            // Retries entirely on a plain types grid (never touching the real Phaser pieces
            // until a valid arrangement is found) — the pure retry loop itself moved to
            // engine/board_rules.js::shuffleUntilValid(), which a future server-side replay
            // reproduces against the same rng draws to arrive at the same arrangement.
            const types = this.extractTypesGrid();
            BoardRules.shuffleUntilValid(types, this.rows, this.cols, me.combat.rng);

            for (let r = 0; r < this.rows; r++) {
                for (let c = 0; c < this.cols; c++) {
                    this.grid[r][c].type = types[r][c];
                    this.grid[r][c].setTexture(`item${types[r][c]}`);
                }
            }

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

            // Runs directly on the real Phaser grid, not an extracted types grid: the
            // "fall" pass only ever moves existing references and checks null, never
            // compares piece identity/type, so it works unchanged on real objects. The
            // "spawn" pass writes a raw type number into each newly-emptied cell as a
            // placeholder — replaced with the real Phaser image in the loop right below,
            // before anything else reads this.grid again.
            const result = BoardRules.applyGravityToGrid(this.grid, this.rows, this.cols, me.combat.rng);

            for (const {toRow, col} of result.fell) {
                const falling = this.grid[toRow][col];
                falling.row = toRow;
                me.tweens.add({
                    targets: falling,
                    y: this.offsetY + (toRow * this.pieceSize),
                    duration: 250, ease: 'Quad.easeIn'
                });
            }

            for (const {row, col, type} of result.spawned) {
                const x = this.offsetX + (col * this.pieceSize);
                const yStart = this.offsetY - (this.pieceSize * (this.rows - row));
                const yEnd = this.offsetY + (row * this.pieceSize);

                const piece = me.add.image(x, yStart, `item${type}`);
                piece.setDisplaySize(this.pieceSize - 4, this.pieceSize - 4);
                piece.type = type;
                piece.row = row;
                piece.col = col;

                piece.setInteractive();
                piece.on('pointerdown', this.startSwipe.bind(this, piece));

                this.grid[row][col] = piece;
                me.tweens.add({
                    targets: piece, y: yEnd, duration: 400, ease: 'Bounce.easeOut'
                });
            }

            me.time.delayedCall(500, this.checkMatches, [], this);
        }

        checkMatches() {
            const me = this.scene;
            this.syncAccessibleGrid();
            const {toDestroy, matchGroups} = this.findMatches();

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

            // A real, confirmed player swap — record it before clearing lastSwap, so a
            // future server-side replay can reproduce this same match. The boss's own
            // moves are never recorded: executeBossTurn() is a deterministic scan the
            // server can always recompute independently, with no RNG involved. Reading the
            // two pieces' current (post-swap) row/col here still names the same two cells
            // that were exchanged either way — swapping (A,B) and swapping (B,A) mean the
            // same operation to engine/board_rules.js::swapInGrid().
            if (this.lastSwap !== null && me.combat.currentTurn === 'player') {
                me.combat.recordMove(
                    this.lastSwap.p1.row, this.lastSwap.p1.col,
                    this.lastSwap.p2.row, this.lastSwap.p2.col
                );
            }
            this.lastSwap = null;

            // Combat.js still works with real Phaser pieces (it destroys them, tweens them,
            // reads .row/.col off them) — mapping findMatches()'s coordinates back to the
            // pieces they name here keeps that contract unchanged. Combat's own turn to move
            // off Phaser objects is a later phase of this same refactor, not this one.
            const destroyedPieces = toDestroy.map(cell => this.grid[cell.row][cell.col]);
            const pieceMatchGroups = matchGroups.map(group => ({
                type: group.type,
                pieces: group.cells.map(cell => this.grid[cell.row][cell.col]),
            }));

            const effects = me.combat.processEffects(destroyedPieces, pieceMatchGroups);
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
