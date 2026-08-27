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
 * UI Module for PlayerPuzzle.
 *
 * @module     mod_playerpuzzle/ui
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global Phaser */

define(['jquery'], function($) {
    'use strict';

    class UIHandler {
        constructor(scene, layout, gameConfig, strings) {
            this.scene = scene;
            this.L = layout;
            this.gameConfig = gameConfig;
            this.strings = strings;
            this.rings = {};
            this.playerLog = [];
            this.bossLog = [];
        }

        setupLoader() {
            this.scene.load.on('progress', value => {
                const percent = parseInt(value * 100);
                const bar = document.getElementById('pp-progress-bar');
                bar.style.setProperty('--pp-bar-width', `${percent}%`);
                bar.setAttribute('aria-valuenow', percent);
                bar.textContent = `${percent}%`;
            });

            this.scene.load.on('complete', () => {
                $('#pp-bootstrap-loader').fadeOut(300, () => {
                    $('#pp-bootstrap-loader').remove();
                });
            });
        }

        setupStaticUI() {
            const me = this.scene;
            const L = this.L;

            if (L.hasCharacterStage) {
                me.add.image(L.stageBgX, L.stageBgY, 'stagebg').setDisplaySize(L.stageBgW, L.stageBgH);

                // Side panel backing: a carved stone tablet image behind each player/boss
                // resource cluster, flush against the board's own edges (panelW is derived
                // from board size). Uses panelY, not boardOffX/Y — those are a piece-center
                // reference for board.js, not this rect's top-left corner (see the comment on
                // panelY). Sits flush at panelY, not overlapping — only the board itself
                // (board.js) rises into the stage band, on purpose: the board reads as a
                // raised platform between the two panels, matching the reference screenshot
                // the plugin is modeled after. Boss side is the same image flipped
                // horizontally, so the carved border reads as a mirrored pair instead of two
                // identical copies.
                const panelH = L.h - L.panelY;
                me.add.image(L.panelW / 2, L.panelY + (panelH / 2), 'panelstone')
                    .setDisplaySize(L.panelW, panelH);
                me.add.image(L.w - (L.panelW / 2), L.panelY + (panelH / 2), 'panelstone')
                    .setDisplaySize(L.panelW, panelH)
                    .setFlipX(true);

                this.playerSprite = me.add.image(L.playerX, L.playerY, 'player')
                    .setDisplaySize(L.playerScale, L.playerScale);
                this.bossSprite = me.add.image(L.bossX, L.bossY, 'boss')
                    .setDisplaySize(L.bossScale, L.bossScale);

                // Consumables row: Poção + Espada — the two purchasable consumíveis with no
                // meter of their own (Escudo/Magia Rápida fill a ring, so their buy badges sit
                // on that ring). Layout placement only — buy_consumable doesn't exist yet; the
                // badge doubles as price label and, once it does, click target. Espada's own
                // effect is an extra attack worth a 3-piece combo (1x boss damage).
                me.add.image(L.potionX, L.potionY, 'item5')
                    .setDisplaySize(L.resourceIconSize, L.resourceIconSize);
                this.createPurchaseBadge(L.potionX, L.potionY, '8');
                me.add.image(L.swordX, L.swordY, 'item3')
                    .setDisplaySize(L.resourceIconSize, L.resourceIconSize);
                this.createPurchaseBadge(L.swordX, L.swordY, '10');

                // Coin/Star: passive quantity readouts, not buttons — sit beside the history
                // block (smaller icon than the consumable row), out of the action area.
                this.txtGold = this.addResourceChip(L.goldX, L.goldY, 'item6', '0', L.indicatorIconSize);
                this.txtStar = this.addResourceChip(L.starX, L.starY, 'item0', 'x1.0', L.indicatorIconSize);

                // Boss side: same layout translated by the panel offset. Potion/Espada shown
                // for visual parity only (no boss shop) — plain icon, never a purchase badge.
                me.add.image(L.bossPotionX, L.bossPotionY, 'item5')
                    .setDisplaySize(L.resourceIconSize, L.resourceIconSize);
                me.add.image(L.bossSwordX, L.bossSwordY, 'item3')
                    .setDisplaySize(L.resourceIconSize, L.resourceIconSize);
                this.txtBossGold = this.addResourceChip(
                    L.bossGoldX, L.bossGoldY, 'item6', '0', L.indicatorIconSize
                );
                this.txtBossStar = this.addResourceChip(
                    L.bossStarX, L.bossStarY, 'item0', 'x1.0', L.indicatorIconSize
                );

                // Purchase badges for the two consumíveis unified with their own board piece
                // (Escudo, Magia Rápida/Grimório). Not shown for the boss (no shop) or the mana
                // Orb ring (not a purchasable consumível).
                this.createPurchaseBadge(L.playerGrimoireX, L.playerRingY, '12');
                this.createPurchaseBadge(L.playerShieldRingX, L.playerRingY, '10');

                this.playerLogLines = this.createHistoryLog(L.playerUiX);
                this.bossLogLines = this.createHistoryLog(L.bossUiX);
            } else {
                me.add.image(L.bgX, L.bgY, 'bg').setDisplaySize(L.bgW, L.bgH);

                // Simplified panel backing — a plain translucent rounded rect per cluster, not
                // panel_stone.png/scroll_banner.png (those are sized for the desktop's wide
                // 16:9 stage band, which this 9:16 column doesn't have — every extra px of
                // chrome here is a px stolen from the board). Decided after prototyping the
                // whole column as an interactive artifact (26/08/2026).
                this.drawSimplePanel(L.panelTopBoss, L.panelBottomBoss);
                this.drawSimplePanel(L.panelTopPlayer, L.panelBottomPlayer);

                this.bossSprite = me.add.image(L.bossX, L.bossY, 'boss')
                    .setDisplaySize(L.bossScale, L.bossScale);
                // Beside the bar/resource/ring stack, in the panel's own left margin — not
                // centered above like the boss's sprite (see playerX/Y/Scale's own comment in
                // game_boot.js for why).
                this.playerSprite = me.add.image(L.playerX, L.playerY, 'player')
                    .setDisplaySize(L.playerScale, L.playerScale);

                // Icon chips (reused from the desktop redesign), not raw emoji text — the old
                // "🪙: 0" read poorly against the sky background at this size. Also fixes a
                // pre-existing gap: the boss's own coin count was never shown here at all.
                this.txtGold = this.addResourceChip(L.goldX, L.goldY, 'item6', '0');
                this.txtStar = this.addResourceChip(L.starX, L.starY, 'item0', 'x1.0');
                me.add.image(L.potionX, L.potionY, 'item5')
                    .setDisplaySize(L.resourceIconSize, L.resourceIconSize);
                this.createPurchaseBadge(L.potionX, L.potionY, '8', L.badgeScale);
                this.txtBossGold = this.addResourceChip(L.bossGoldX, L.bossGoldY, 'item6', '0');
                this.txtBossStar = this.addResourceChip(L.bossStarX, L.bossStarY, 'item0', 'x1.0');

                // Purchase badges for Escudo/Magia Rápida, scaled down (see
                // createPurchaseBadge()'s own docblock) — mobile's rings are themselves half
                // desktop's radius, and the WCAG 24x24 real-px floor is met here without the
                // desktop's full 46x34 size, since mobile's canvas has no 0.75 CSS shrink to
                // compensate for. Not shown for the boss (no shop) or the mana Orb ring.
                this.createPurchaseBadge(L.playerGrimoireX, L.playerRingY, '12', L.badgeScale);
                this.createPurchaseBadge(L.playerShieldRingX, L.playerRingY, '10', L.badgeScale);

                this.setupHistoryButtonMobile();
            }

            me.add.graphics().fillStyle(0x000000, 0.8)
                .fillRoundedRect(L.bossUiX, L.bossHpY, L.hpBarW, L.hpBarH, this.hpBarCorners());
            this.bossHpBar = me.add.graphics();
            this.bossHpText = me.add.text(L.bossUiX + (L.hpBarW / 2), L.bossTxtY, '', {
                fontSize: '15px',
                fill: '#ffffff',
                fontStyle: 'bold'
            }).setOrigin(0.5);

            me.add.graphics().fillStyle(0x000000, 0.8)
                .fillRoundedRect(L.playerUiX, L.playerHpY, L.hpBarW, L.hpBarH, this.hpBarCorners());
            this.playerHpBar = me.add.graphics();
            this.playerHpText = me.add.text(L.playerUiX + (L.hpBarW / 2), L.playerTxtY, '', {
                fontSize: '15px',
                fill: '#ffffff',
                fontStyle: 'bold'
            }).setOrigin(0.5);

            // Status badges (visual mockup) now sit on both layouts — mobile included since
            // 26/08/2026, no longer desktop-only.
            this.createStatusPreview(L.bossUiX + L.hpBarW, L.bossHpY + L.hpBarH);
            this.createStatusPreview(L.playerUiX + L.hpBarW, L.playerHpY + L.hpBarH);

            this.setupProgressIndicator();
            this.setupButtons();
        }

        /**
         * Creates an icon + text "chip" for a resource value (Coin/Star), replacing the flat
         * emoji-text these used before the character-stage redesign. Returns the text object
         * so callers can keep updating it with `.setText()`, same as the emoji-text version.
         *
         * @param {number} x Icon center X.
         * @param {number} y Icon center Y.
         * @param {string} spriteKey Texture key of the icon (item6 = Coin, item0 = Star).
         * @param {string} initialText Text shown next to the icon before the first update.
         * @param {number} [size] Icon size; defaults to L.resourceIconSize (the consumable-row
         * size). Callers beside the history block pass the smaller L.indicatorIconSize.
         * @return {Phaser.GameObjects.Text} The text object, for later .setText() calls.
         */
        addResourceChip(x, y, spriteKey, initialText, size) {
            const iconSize = size || this.L.resourceIconSize;
            this.scene.add.image(x, y, spriteKey).setDisplaySize(iconSize, iconSize);
            return this.scene.add.text(x + (iconSize / 2) + 8, y, initialText, {
                fontSize: '18px',
                fill: '#ffffff',
                fontStyle: 'bold'
            }).setOrigin(0, 0.5);
        }

        /**
         * Visual mockup only — the purchase badge for Escudo and Magia Rápida (both unified
         * with their own board piece), also reused for the Poção and Espada icons so every
         * purchasable consumível shares one price convention. Overlaps the target icon's own
         * bottom-right corner; not wired to buy_consumable() (doesn't exist yet) — meant to
         * double as the click target once it does.
         *
         * Sized at 46x34 logical units at scale 1 (desktop's default, every call site below
         * except the mobile branch of setupStaticUI) — with the game embed capped at 960px
         * (a6fb4ec), that's the smallest size clearing the 24x24px real touch-target minimum
         * (WCAG 2.5.8) at the embed's own maximum size (960/1280 scale = 0.75, so 24 / 0.75 =
         * 32 logical units is the floor). Mobile's canvas has no such shrink (its own
         * `.pp-canvas-container` caps at the same 540px as the logical design width, a true
         * 1:1 CSS scale — see styles.css), so the floor there is the raw 24 logical units;
         * more importantly, mobile's rings are themselves half desktop's radius (16 vs 32,
         * see game_boot.js's two L objects), and the default 46x34 badge is bigger than the
         * ring itself, swallowing its colored arc entirely. Mobile's three purchase-badge
         * calls pass a smaller scale for this reason — verified live (27/08/2026) that the
         * ring's own arc/icon stays visible next to the badge at that size.
         *
         * @param {number} iconX Target icon's center X (ring or resource chip).
         * @param {number} iconY Target icon's center Y.
         * @param {string} price Price text, e.g. "10".
         * @param {number} scale Size multiplier off the 46x34/20px-offset desktop baseline.
         */
        createPurchaseBadge(iconX, iconY, price, scale = 1) {
            const me = this.scene;
            const w = 46 * scale;
            const h = 34 * scale;
            const offset = 20 * scale;
            const cx = iconX + offset;
            const cy = iconY + offset;

            me.add.graphics()
                .fillStyle(0x1c1712, 1)
                .fillRoundedRect(cx - (w / 2), cy - (h / 2), w, h, 6 * scale)
                .lineStyle(2, 0x9c6b2e, 1)
                .strokeRoundedRect(cx - (w / 2), cy - (h / 2), w, h, 6 * scale)
                .setDepth(5);
            me.add.image(cx - (10 * scale), cy, 'item6').setDisplaySize(16 * scale, 16 * scale).setDepth(6);
            me.add.text(cx + (4 * scale), cy, price, {
                fontSize: `${Math.round(15 * scale)}px`,
                fill: '#ffffaa',
                fontStyle: 'bold'
            }).setOrigin(0.5).setDepth(6);
        }

        /**
         * Creates the 5-line action history box for one side: a parchment banner behind the
         * title (replacing the old Grimoire-icon-plus-plain-text title), then 5 empty text
         * lines below it, top line pre-filled with the "no moves yet" string. Returns the
         * line objects for pushHistoryLog() to update later via .setText().
         *
         * @param {number} x Left edge X shared by the title and every line (matches the HP
         * bar's own left edge on that side, for a consistent left margin within the panel).
         * @return {Phaser.GameObjects.Text[]} The 5 line text objects, index 0 = most recent.
         */
        createHistoryLog(x) {
            const L = this.L;
            const me = this.scene;

            // Sized to the scroll_banner.png source's own aspect ratio (~1.74:1) rather than
            // stretched to a specific width, so the parchment doesn't visibly distort. The gap
            // it leaves to its right is where the Coin/Star readouts now sit (see goldX/starX).
            const scrollH = 52;
            me.add.image(x, L.historyTitleY, 'scrollbanner')
                .setOrigin(0, 0.5)
                .setDisplaySize(scrollH * 1.738, scrollH);
            // Dark ink-brown, not the old tan/gold — that color relied on contrast against
            // the flat dark panel background and reads poorly against the parchment now
            // behind it.
            me.add.text(x + 14, L.historyTitleY, this.strings.historylogtitle, {
                fontSize: '12px',
                fill: '#4a2f16',
                fontStyle: 'bold'
            }).setOrigin(0, 0.5);

            const lines = [];
            for (let i = 0; i < 5; i++) {
                lines.push(me.add.text(x, L.historyLineY + (i * L.historyLineHeight), '', {
                    fontSize: '13px',
                    fill: '#e9e6dd'
                }).setOrigin(0, 0));
            }
            lines[0].setText(this.strings.historylogempty);
            return lines;
        }

        /**
         * Visual mockup only for the persistent "Status" panel (26/08/2026) — not wired to
         * real poison/shield state, purely to see the layout. Hangs off the HP bar's own
         * bottom-right corner (moved there from beside the history scroll banner, per user
         * feedback: it ties the effect to the character whose HP it is), one small badge per
         * active effect, side by side, never dividing shared space between them. Only the two
         * statuses that exist today (Veneno/Escudo) are mocked here; a third badge would just
         * repeat this same pattern one more time, reading further left.
         *
         * @param {number} cornerX HP bar's own right edge (playerUiX/bossUiX + hpBarW).
         * @param {number} cornerY HP bar's own bottom edge (playerHpY/bossHpY + hpBarH).
         */
        createStatusPreview(cornerX, cornerY) {
            const me = this.scene;
            const badgeR = 15;
            const gap = 34;

            const previewStatuses = [
                {icon: 'item1', count: '3'},
                {icon: 'item4', count: '✓'}
            ];

            previewStatuses.forEach((status, i) => {
                const cx = cornerX - badgeR - (i * gap);
                me.add.circle(cx, cornerY, badgeR, 0x1a1410, 0.85).setStrokeStyle(1, 0x6a4a2a);
                me.add.image(cx, cornerY, status.icon).setDisplaySize(badgeR * 1.3, badgeR * 1.3);
                me.add.circle(cx + badgeR - 4, cornerY + badgeR - 4, 8, 0x2a1a10)
                    .setStrokeStyle(1, 0xffffff);
                me.add.text(cx + badgeR - 4, cornerY + badgeR - 4, status.count, {
                    fontSize: '9px',
                    fill: '#ffffff',
                    fontStyle: 'bold'
                }).setOrigin(0.5);
            });
        }

        /**
         * Simplified panel backing for mobile (26/08/2026): a plain translucent
         * rounded rect spanning most of the column's width behind one HUD cluster, standing in
         * for the desktop's carved-stone artwork — that art is sized for a wide 16:9 stage band
         * this 9:16 column doesn't have, and every extra px of chrome here is a px stolen from
         * the board.
         *
         * @param {number} topY Panel top edge Y.
         * @param {number} bottomY Panel bottom edge Y.
         */
        drawSimplePanel(topY, bottomY) {
            const margin = 16;
            const L = this.L;
            this.scene.add.graphics()
                .fillStyle(0x1a1410, 0.55)
                .fillRoundedRect(margin, topY, L.w - (margin * 2), bottomY - topY, 12)
                .lineStyle(1, 0x6a4a2a, 0.6)
                .strokeRoundedRect(margin, topY, L.w - (margin * 2), bottomY - topY, 12);
        }

        /**
         * Mobile-only replacement for desktop's two always-visible 5-line history boxes — no
         * room for both at this aspect ratio. A single button opens a modal with Você/Chefe
         * tabs instead. See showHistoryModalMobile().
         */
        setupHistoryButtonMobile() {
            const me = this.scene;
            const L = this.L;

            const btn = me.add.text(L.w / 2, L.historyBtnY, this.strings.historylogtitle, {
                fontSize: '16px', fill: '#ffffff', backgroundColor: '#3a2a1a', padding: {x: 12, y: 8}
            }).setOrigin(0.5, 0).setInteractive().setDepth(10);

            btn.on('pointerdown', () => this.showHistoryModalMobile());
        }

        /**
         * Opens the mobile history modal: created fresh on open, fully destroyed on close.
         * Both sides' logs are shown at once, stacked (Você above Chefe) — an earlier version
         * used click-to-switch tabs instead, but the user only discovered the Chefe side
         * existed by accident and expected both visible without an extra tap (27/08/2026);
         * stacking, not side-by-side columns, keeps each line's own width close to the
         * modal's own 85%-of-canvas width, since Portuguese history strings ("💥 Crítico! -33
         * HP") don't comfortably fit a half-width column at this font size. Content is filled
         * by renderHistoryModalLines(), which pushHistoryLog() also calls while the modal
         * stays open so a new action shows up immediately without a close/reopen round-trip.
         */
        showHistoryModalMobile() {
            if (this._historyModalOpen) {
                return;
            }
            this._historyModalOpen = true;

            const me = this.scene;
            const L = this.L;
            const cx = L.w / 2;
            const boxW = Math.round(L.w * 0.85);
            const boxH = 460;
            const boxX = cx - (boxW / 2);
            const boxY = (L.h - boxH) / 2;
            const lineX = boxX + 20;

            const overlay = me.add.graphics().setDepth(20);
            overlay.fillStyle(0x000000, 0.75);
            overlay.fillRect(0, 0, L.w, L.h);
            overlay.fillStyle(0x222018, 1);
            overlay.fillRoundedRect(boxX, boxY, boxW, boxH, 16);

            // Section headers are plain labels now, not buttons — colored to match each side's
            // own HP bar (green/red) so the two blocks stay visually distinct at a glance.
            const headerYouY = boxY + 28;
            me.add.text(lineX, headerYouY, this.strings.hpyou, {
                fontSize: '15px', fill: '#5fd95f', fontStyle: 'bold'
            }).setOrigin(0, 0.5).setDepth(21);

            const playerLines = [];
            const linesYouStartY = headerYouY + 26;
            for (let i = 0; i < 5; i++) {
                playerLines.push(me.add.text(lineX, linesYouStartY + (i * 24), '', {
                    fontSize: '13px', fill: '#e9e6dd'
                }).setOrigin(0, 0).setDepth(21));
            }

            const headerChefeY = linesYouStartY + (5 * 24) + 30;
            me.add.text(lineX, headerChefeY, this.strings.hpboss, {
                fontSize: '15px', fill: '#ff6b6b', fontStyle: 'bold'
            }).setOrigin(0, 0.5).setDepth(21);

            const bossLines = [];
            const linesChefeStartY = headerChefeY + 26;
            for (let i = 0; i < 5; i++) {
                bossLines.push(me.add.text(lineX, linesChefeStartY + (i * 24), '', {
                    fontSize: '13px', fill: '#e9e6dd'
                }).setOrigin(0, 0).setDepth(21));
            }

            const btnClose = me.add.text(cx, boxY + boxH - 35, this.strings.btncontinue, {
                fontSize: '16px', fill: '#ffffff', backgroundColor: '#882222', padding: {x: 16, y: 8}
            }).setOrigin(0.5).setInteractive().setDepth(21);

            this._historyModal = {playerLines, bossLines};

            btnClose.on('pointerdown', () => {
                this._historyModalOpen = false;
                overlay.destroy();
                playerLines.forEach(line => line.destroy());
                bossLines.forEach(line => line.destroy());
                btnClose.destroy();
                this._historyModal = null;
            });

            this.renderHistoryModalLines();
        }

        /**
         * Fills the open mobile history modal's line objects from both sides' log arrays at
         * once — no active-tab state to track since both blocks are always visible. No-ops
         * when the modal isn't open — pushHistoryLog() calls this unconditionally so it
         * doesn't need to track modal state itself.
         *
         * An empty log shows the same "no moves yet" placeholder desktop's createHistoryLog()
         * pre-fills line 0 with — without it, an empty Chefe block (real: the boss hasn't
         * landed a loggable match yet, since its own turns share this same log path) looked
         * identical to a broken one (27/08/2026).
         */
        renderHistoryModalLines() {
            if (!this._historyModalOpen) {
                return;
            }
            const fill = (lines, log) => {
                lines.forEach((line, i) => {
                    line.setText(log[i] || (i === 0 ? this.strings.historylogempty : ''));
                    line.setColor(i === 0 ? '#d9973f' : '#e9e6dd');
                });
            };
            fill(this._historyModal.playerLines, this.playerLog);
            fill(this._historyModal.bossLines, this.bossLog);
        }

        /**
         * Appends one line to a side's action history (newest first, oldest dropped once the
         * side's display can no longer show it) and re-renders that side's display: desktop's
         * always-visible 5-line boxes, or the mobile history modal when it happens to be open.
         *
         * @param {string} side 'player' or 'boss' — picks which side's history to update.
         * @param {string} text The new line to show at the top.
         */
        pushHistoryLog(side, text) {
            const log = side === 'player' ? this.playerLog : this.bossLog;
            // 5 lines either way: desktop's own always-visible boxes, or the mobile modal's
            // per-side block (stacked Você/Chefe, 27/08/2026 — see showHistoryModalMobile()).
            const maxLines = this.L.hasCharacterStage ? this.playerLogLines.length : 5;

            log.unshift(text);
            if (log.length > maxLines) {
                log.length = maxLines;
            }

            if (this.L.hasCharacterStage) {
                const lines = side === 'player' ? this.playerLogLines : this.bossLogLines;
                lines.forEach((line, i) => {
                    line.setText(log[i] || '');
                    line.setColor(i === 0 ? '#d9973f' : '#e9e6dd');
                });
            } else {
                this.renderHistoryModalLines();
            }
        }

        /**
         * Corner-radius object for an HP bar's backdrop (all four corners rounded).
         *
         * @return {object} {tl, tr, bl, br} corner radii, all set to L.hpBarRadius.
         */
        hpBarCorners() {
            const r = this.L.hpBarRadius;
            return {tl: r, tr: r, bl: r, br: r};
        }

        /**
         * Draws an HP bar's colored fill, rounded on all four corners to match the backdrop
         * capsule. The radius shrinks with the fill itself (never exceeding half its own
         * width/height), so a nearly-empty bar degrades gracefully into a small rounded blob
         * instead of self-intersecting arcs. Adds a faint highlight along the top third for a
         * glossy look consistent with the game's icons.
         *
         * @param {Phaser.GameObjects.Graphics} bar The bar's own graphics object.
         * @param {number} x Backdrop left edge X.
         * @param {number} y Backdrop top edge Y.
         * @param {number} pct Fill progress, 0–1.
         * @param {number} color Fill color (hex).
         */
        drawHpFill(bar, x, y, pct, color) {
            const L = this.L;
            const inset = 3;
            const fillW = Math.max(0, (L.hpBarW - (inset * 2)) * Math.max(0, pct));
            const fillH = L.hpBarH - (inset * 2);
            const r = Math.max(0, Math.min(L.hpBarRadius - inset, fillW / 2, fillH / 2));

            bar.clear();
            if (fillW <= 0) {
                return;
            }

            bar.fillStyle(color, 1);
            bar.fillRoundedRect(x + inset, y + inset, fillW, fillH, r);

            bar.fillStyle(0xffffff, 0.22);
            bar.fillRoundedRect(x + inset, y + inset, fillW, fillH * 0.4, {tl: r, tr: r, bl: 0, br: 0});
        }

        /**
         * Creates (on first call) or updates (on later calls) a radial-ring meter: an arc
         * filling clockwise around a static piece-sprite icon, from 0% to 100% of `pct`. Used
         * for the Grimoire/Shield/Question-Orb meters, each of which already fills 0–100
         * (Fase 3.5 visual redesign) — replaces the earlier flat progress bar / text indicator
         * these three used before.
         *
         * @param {string} key Unique identifier for this ring instance (e.g. 'playerGrimoire').
         * @param {number} x Center X.
         * @param {number} y Center Y.
         * @param {string} spriteKey Texture key of the piece sprite centered inside the ring.
         * @param {number} pct Fill progress, 0–1.
         * @param {number} color Stroke color (hex) for the filled arc.
         */
        updateRing(key, x, y, spriteKey, pct, color) {
            const radius = this.L.ringRadius;
            const thickness = this.L.ringThickness;
            const iconSize = this.L.ringIconSize;

            if (!this.rings[key]) {
                // A solid backing plate is required here: the ring sits directly over the
                // battle background (grass, trees, sky), which bleeds through a stroke-only
                // circle and makes the icon inside unreadable at this size.
                this.scene.add.circle(x, y, radius + 3, 0x1a1a1a, 0.9);
                this.scene.add.graphics().lineStyle(thickness, 0x222222, 1).strokeCircle(x, y, radius);
                this.scene.add.image(x, y, spriteKey).setDisplaySize(iconSize, iconSize);
                this.rings[key] = this.scene.add.graphics();
            }

            const arc = this.rings[key];
            arc.clear();
            if (pct <= 0) {
                return;
            }
            arc.lineStyle(thickness, color, 1);
            arc.beginPath();
            arc.arc(
                x, y, radius,
                Phaser.Math.DegToRad(-90), Phaser.Math.DegToRad(-90 + (360 * pct)),
                false
            );
            arc.strokePath();
        }

        /**
         * Shows "Level X — Phase Y of 10" in the HUD for Campaign mode. Single Match has
         * no levels/phases, so the indicator is skipped entirely for it. Desktop keeps it on
         * the button row (y:20); mobile gives it a dedicated row below the buttons instead —
         * live measurement showed only ~104px free between Efeitos and Expandir there, not
         * enough at any readable size (26/08/2026).
         */
        setupProgressIndicator() {
            if (this.gameConfig.gamemode !== 'campaign') {
                return;
            }

            const me = this.scene;
            const L = this.L;
            const y = L.hasCharacterStage ? 20 : L.progressIndicatorY;

            const text = this.strings.progressindicator
                .replace('{$a->level}', this.gameConfig.currentlevel)
                .replace('{$a->phase}', this.gameConfig.currentphase);

            this.progressText = me.add.text(L.w / 2, y, text, {
                fontSize: '16px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
            }).setOrigin(0.5, 0).setDepth(10);
        }

        /**
         * Draws the shared dark-fill, bronze-bordered circular badge used by all 3 compact top
         * buttons (Música/Efeitos/Expandir) — replaces the old bracket-text "[ Word ]" style
         * (28/08/2026), which read as generic HTML/Bootstrap controls, with the same visual
         * language already used for rings and purchase badges. The circle itself is the
         * interactive target (Phaser auto-generates a matching circular hit area), not the
         * icon glyph on top of it — sidesteps the old workaround of an oversized rectangular
         * hit area to compensate for emoji bounding-box mismetrics.
         *
         * @param {number} x Center X.
         * @param {number} y Center Y.
         * @param {number} radius Badge radius, logical units.
         * @return {Phaser.GameObjects.Arc} The interactive badge circle.
         */
        createButtonBadge(x, y, radius) {
            return this.scene.add.circle(x, y, radius, 0x1c1712, 0.9)
                .setStrokeStyle(2, 0x9c6b2e)
                .setInteractive()
                .setDepth(10);
        }

        /**
         * Draws the Expandir/Encolher button's own icon: four corner brackets pointing outward
         * from near the badge's edge (tap to enter fullscreen), or pulled toward the center and
         * pointing outward from there (already fullscreen, tap to exit) — redrawn on each
         * toggle since the shape itself changes, unlike Música/Efeitos which just dim.
         *
         * @param {Phaser.GameObjects.Graphics} g Graphics object to draw into (cleared first).
         * @param {number} cx Center X.
         * @param {number} cy Center Y.
         * @param {number} r Badge radius — the icon is sized relative to it.
         * @param {boolean} expanded True once fullscreen is active (draws the "exit" variant).
         */
        drawFullscreenIcon(g, cx, cy, r, expanded) {
            g.clear();
            g.lineStyle(3, 0xe8dcc8, 1);
            // CornerRadius is the elbow point's true distance from center; elbowDist is that
            // same distance split evenly across both axes (divided by sqrt(2)) so the elbow
            // actually lands cornerRadius away, not cornerRadius on EACH axis — using the raw
            // per-axis value as if it were the radial distance let both offsets compound
            // diagonally past the circle's own edge, drawing the brackets outside the badge
            // instead of inside it. armLen is kept well under elbowDist, not close to it —
            // first attempt used ~1.1x elbowDist, which let each arm cross the center axis and
            // overlap its mirrored twin from the opposite corner, fusing all 4 brackets into
            // what read as one solid square instead of 4 separate corner marks.
            const cornerRadius = expanded ? r * 0.3 : r * 0.62;
            const elbowDist = cornerRadius / Math.SQRT2;
            const armLen = elbowDist * 0.65;
            const armSign = expanded ? 1 : -1;
            [[-1, -1], [1, -1], [-1, 1], [1, 1]].forEach(([sx, sy]) => {
                const ex = cx + (sx * elbowDist);
                const ey = cy + (sy * elbowDist);
                g.beginPath();
                g.moveTo(ex + (sx * armSign * armLen), ey);
                g.lineTo(ex, ey);
                g.lineTo(ex, ey + (sy * armSign * armLen));
                g.strokePath();
            });
        }

        setupButtons() {
            const me = this.scene;
            const L = this.L;
            const strings = this.strings;

            me.musicOn = true;
            me.sfxOn = true;

            // Row of 3 compact icon buttons, right-aligned — rightmost is Expandir, matching
            // where the old bracket-text button always sat; Efeitos and Música step leftward
            // from there by the same gap.
            const y = L.topBtnY;
            const r = L.topBtnRadius;
            const gap = L.topBtnGap;
            const xExpand = L.w - 30;
            const xEffects = xExpand - gap;
            const xMusic = xEffects - gap;
            const iconStyle = {fontSize: `${Math.round(r * 1.15)}px`};

            const badgeMusic = this.createButtonBadge(xMusic, y, r);
            const iconMusic = me.add.text(xMusic, y, strings.musicon, iconStyle)
                .setOrigin(0.5).setDepth(11);
            badgeMusic.on('pointerup', () => {
                if (me.board && me.board.swipePiece !== null) {
                    return;
                }
                me.musicOn = !me.musicOn;
                iconMusic.setText(me.musicOn ? strings.musicon : strings.musicoff);
                if (me.musicOn) {
                    me.bgMusic.resume();
                } else {
                    me.bgMusic.pause();
                }
            });

            const badgeEffects = this.createButtonBadge(xEffects, y, r);
            const iconEffects = me.add.text(xEffects, y, strings.iconeffects, iconStyle)
                .setOrigin(0.5).setDepth(11);
            badgeEffects.on('pointerup', () => {
                if (me.board && me.board.swipePiece !== null) {
                    return;
                }
                me.sfxOn = !me.sfxOn;
                // No natural "muted gear" glyph, unlike Música's own 🔊/🔇 pair — dims the icon
                // instead, mirroring the fill-color dim the old text buttons used for the same
                // purpose.
                iconEffects.setAlpha(me.sfxOn ? 1 : 0.4);
                const vol = me.sfxOn ? 1 : 0;
                me.sfxSwap.setVolume(0.6 * vol);
                me.sfxMatch.setVolume(0.5 * vol);
                me.sfxHit.setVolume(0.8 * vol);
            });

            const badgeExpand = this.createButtonBadge(xExpand, y, r);
            const iconExpand = me.add.graphics().setDepth(11);
            this.drawFullscreenIcon(iconExpand, xExpand, y, r, false);
            badgeExpand.on('pointerdown', () => {
                me.cameras.main.fadeOut(200, 0, 0, 0);
                me.time.delayedCall(200, () => {
                    if (me.scale.isFullscreen) {
                        me.scale.stopFullscreen();
                    } else {
                        me.scale.startFullscreen();
                    }
                    this.drawFullscreenIcon(iconExpand, xExpand, y, r, me.scale.isFullscreen);
                    me.cameras.main.fadeIn(200, 0, 0, 0);
                });
            });
        }

        updateBossBar(currentHp, maxHp, poisonMeter, poisonRounds, shieldMeter, shieldReady, mana, gold, multiplier) {
            const L = this.L;
            const pctHp = Math.max(0, currentHp / maxHp);
            this.drawHpFill(this.bossHpBar, L.bossUiX, L.bossHpY, pctHp, 0xdd0000);
            this.bossHpText.setText(`${this.strings.hpboss} ${Math.round(currentHp)} / ${maxHp}`);

            this.txtBossGold.setText(`${Math.round(gold)}`);
            this.txtBossStar.setText(`x${multiplier.toFixed(1)}`);

            // Poison ring: purple while filling toward the next trigger; turns red whenever a
            // damage round is currently pending, regardless of the meter's own fresh progress.
            this.updateRing(
                'bossGrimoire', L.bossGrimoireX, L.bossRingY, 'item1',
                poisonRounds > 0 ? 1 : (poisonMeter / 100), poisonRounds > 0 ? 0xff3333 : 0x9933cc
            );
            // Shield ring: blue while filling; forced to a full gold ring while a block is
            // armed, since the meter itself already reset to 0 the instant it triggered.
            this.updateRing(
                'bossShieldRing', L.bossShieldRingX, L.bossRingY, 'item4',
                shieldReady ? 1 : (shieldMeter / 100), shieldReady ? 0xffcc00 : 0x3388ff
            );
            this.updateRing('bossOrb', L.bossOrbX, L.bossRingY, 'item2', mana / 100, 0x0088ff);
        }

        updatePlayerBar(currentHp, maxHp, poisonMeter, poisonRounds, shieldMeter, shieldReady, mana, gold, multiplier) {
            const L = this.L;
            const pctHp = Math.max(0, currentHp / maxHp);
            this.drawHpFill(this.playerHpBar, L.playerUiX, L.playerHpY, pctHp, 0x00cc00);
            this.playerHpText.setText(
                `${this.strings.hpyou} ${Math.round(currentHp)} / ${maxHp}`
            );

            this.txtGold.setText(`${Math.round(gold)}`);
            this.txtStar.setText(`x${multiplier.toFixed(1)}`);

            this.updateRing(
                'playerGrimoire', L.playerGrimoireX, L.playerRingY, 'item1',
                poisonRounds > 0 ? 1 : (poisonMeter / 100), poisonRounds > 0 ? 0xff3333 : 0x9933cc
            );
            this.updateRing(
                'playerShieldRing', L.playerShieldRingX, L.playerRingY, 'item4',
                shieldReady ? 1 : (shieldMeter / 100), shieldReady ? 0xffcc00 : 0x3388ff
            );
            this.updateRing('playerOrb', L.playerOrbX, L.playerRingY, 'item2', mana / 100, 0x0088ff);
        }
    }

    return UIHandler;
});
