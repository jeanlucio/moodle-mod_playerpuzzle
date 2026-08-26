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

            const styleGold = {fontSize: '16px', fill: '#ffffaa', fontStyle: 'bold'};
            const styleStar = {fontSize: '16px', fill: '#ffddaa', fontStyle: 'bold'};

            if (L.hasCharacterStage) {
                me.add.image(L.stageBgX, L.stageBgY, 'stagebg').setDisplaySize(L.stageBgW, L.stageBgH);

                // Side panel backing: a solid plate behind each player/boss resource cluster,
                // flush against the board's own edges (panelW is derived from board size).
                // Uses panelY, not boardOffX/Y — those are a piece-center reference for
                // board.js, not this rect's top-left corner (see the comment on panelY). Sits
                // flush at panelY, not overlapping — only the board itself (board.js) rises
                // into the stage band, on purpose: the board reads as a raised platform
                // between the two panels, matching the reference screenshot the plugin is
                // modeled after.
                me.add.graphics().fillStyle(0x1a1410, 0.88)
                    .fillRect(0, L.panelY, L.panelW, L.h - L.panelY);
                me.add.graphics().fillStyle(0x1a1410, 0.88)
                    .fillRect(L.w - L.panelW, L.panelY, L.panelW, L.h - L.panelY);

                me.add.image(L.playerX, L.playerY, 'player')
                    .setDisplaySize(L.playerScale, L.playerScale);
                this.bossSprite = me.add.image(L.bossX, L.bossY, 'boss')
                    .setDisplaySize(L.bossScale, L.bossScale);

                this.txtGold = this.addResourceChip(L.goldX, L.goldY, 'item6', '0');
                this.txtStar = this.addResourceChip(L.starX, L.starY, 'item0', 'x1.0');
                this.txtBossGold = this.addResourceChip(L.bossGoldX, L.bossGoldY, 'item6', '0');
                this.txtBossStar = this.addResourceChip(L.bossStarX, L.bossStarY, 'item0', 'x1.0');

                this.playerLogLines = this.createHistoryLog(L.playerUiX);
                this.bossLogLines = this.createHistoryLog(L.bossUiX);
            } else {
                me.add.image(L.bgX, L.bgY, 'bg').setDisplaySize(L.bgW, L.bgH);

                this.bossSprite = me.add.image(L.bossX, L.bossY, 'boss')
                    .setDisplaySize(L.bossScale, L.bossScale);

                this.txtGold = me.add.text(L.goldX, L.goldY, '🪙: 0', styleGold).setOrigin(0.5, 0);
                this.txtStar = me.add.text(L.starX, L.starY, '⭐x1.0', styleStar).setOrigin(1, 0);
                this.txtBossStar = me.add.text(L.bossStarX, L.bossStarY, '⭐x1.0', styleStar)
                    .setOrigin(0, 0.5);
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
         * @return {Phaser.GameObjects.Text} The text object, for later .setText() calls.
         */
        addResourceChip(x, y, spriteKey, initialText) {
            const size = this.L.resourceIconSize;
            this.scene.add.image(x, y, spriteKey).setDisplaySize(size, size);
            return this.scene.add.text(x + (size / 2) + 8, y, initialText, {
                fontSize: '18px',
                fill: '#ffffff',
                fontStyle: 'bold'
            }).setOrigin(0, 0.5);
        }

        /**
         * Creates the 5-line action history box for one side: a Grimoire icon + title, then
         * 5 empty text lines below it, top line pre-filled with the "no moves yet" string.
         * Returns the line objects for pushHistoryLog() to update later via .setText().
         *
         * @param {number} x Left edge X shared by the title and every line (matches the HP
         * bar's own left edge on that side, for a consistent left margin within the panel).
         * @return {Phaser.GameObjects.Text[]} The 5 line text objects, index 0 = most recent.
         */
        createHistoryLog(x) {
            const L = this.L;
            const me = this.scene;

            me.add.image(x + 8, L.historyTitleY, 'item1').setDisplaySize(16, 16);
            me.add.text(x + 20, L.historyTitleY, this.strings.historylogtitle, {
                fontSize: '12px',
                fill: '#b8a878',
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
         * Appends one line to a side's action history (newest first, oldest dropped past 5)
         * and re-renders that side's 5 line objects to match. No-ops on layouts without a
         * character stage (mobile), where the history box was never created.
         *
         * @param {string} side 'player' or 'boss' — picks which side's history to update.
         * @param {string} text The new line to show at the top.
         */
        pushHistoryLog(side, text) {
            if (!this.L.hasCharacterStage) {
                return;
            }
            const log = side === 'player' ? this.playerLog : this.bossLog;
            const lines = side === 'player' ? this.playerLogLines : this.bossLogLines;

            log.unshift(text);
            if (log.length > lines.length) {
                log.length = lines.length;
            }
            lines.forEach((line, i) => {
                line.setText(log[i] || '');
                line.setColor(i === 0 ? '#d9973f' : '#e9e6dd');
            });
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
         * no levels/phases, so the indicator is skipped entirely for it.
         */
        setupProgressIndicator() {
            if (this.gameConfig.gamemode !== 'campaign') {
                return;
            }

            const me = this.scene;
            const L = this.L;

            const text = this.strings.progressindicator
                .replace('{$a->level}', this.gameConfig.currentlevel)
                .replace('{$a->phase}', this.gameConfig.currentphase);

            this.progressText = me.add.text(L.w / 2, 20, text, {
                fontSize: '16px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
            }).setOrigin(0.5, 0).setDepth(10);
        }

        setupButtons() {
            const me = this.scene;
            const L = this.L;
            const strings = this.strings;

            if (this.gameConfig.mobile) {
                const btnExit = me.add.text(L.btnExpX, L.btnExpY, strings.btnexit, {
                    fontSize: '20px', fill: '#ffffff', backgroundColor: '#882222', padding: {x: 8, y: 8}
                }).setOrigin(1, 0).setInteractive().setDepth(10);
                btnExit.on('pointerdown', () => this.showExitConfirm());
            } else {
                const btnFullscreen = me.add.text(L.btnExpX, L.btnExpY, strings.expand, {
                    fontSize: '20px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
                }).setOrigin(1, 0).setInteractive().setDepth(10);

                btnFullscreen.on('pointerdown', () => {
                    me.cameras.main.fadeOut(200, 0, 0, 0);
                    me.time.delayedCall(200, () => {
                        if (me.scale.isFullscreen) {
                            me.scale.stopFullscreen();
                            btnFullscreen.setText(this.strings.expand);
                        } else {
                            me.scale.startFullscreen();
                            btnFullscreen.setText(this.strings.shrink);
                        }
                        me.cameras.main.fadeIn(200, 0, 0, 0);
                    });
                });
            }

            me.musicOn = true;
            me.sfxOn = true;

            // Explicit hit areas prevent emoji mismetrics from shrinking the tap zone on mobile.
            // pointerup is more reliable than pointerdown for button taps on touch devices.
            const hitRect = (w, h) => new Phaser.Geom.Rectangle(-4, -4, w, h);

            const btnMusic = me.add.text(20, 20, strings.musicon, {
                fontSize: '16px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
            }).setInteractive(hitRect(110, 44), Phaser.Geom.Rectangle.Contains).setDepth(10);

            const btnSfx = me.add.text(140, 20, strings.sfxon, {
                fontSize: '16px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
            }).setInteractive(hitRect(120, 44), Phaser.Geom.Rectangle.Contains).setDepth(10);

            btnMusic.on('pointerup', () => {
                if (me.board && me.board.swipePiece !== null) {
                    return;
                }
                me.musicOn = !me.musicOn;
                btnMusic.setText(me.musicOn ? strings.musicon : strings.musicoff);
                btnMusic.setStyle({fill: me.musicOn ? '#ffffff' : '#aaaaaa'});
                if (me.musicOn) {
                    me.bgMusic.resume();
                } else {
                    me.bgMusic.pause();
                }
            });

            btnSfx.on('pointerup', () => {
                if (me.board && me.board.swipePiece !== null) {
                    return;
                }
                me.sfxOn = !me.sfxOn;
                btnSfx.setText(me.sfxOn ? strings.sfxon : strings.sfxoff);
                btnSfx.setStyle({fill: me.sfxOn ? '#ffffff' : '#aaaaaa'});
                const vol = me.sfxOn ? 1 : 0;
                me.sfxSwap.setVolume(0.6 * vol);
                me.sfxMatch.setVolume(0.5 * vol);
                me.sfxHit.setVolume(0.8 * vol);
            });
        }

        updateBossBar(currentHp, maxHp, poisonMeter, poisonRounds, shieldMeter, shieldReady, mana, gold, multiplier) {
            const L = this.L;
            const pctHp = Math.max(0, currentHp / maxHp);
            this.drawHpFill(this.bossHpBar, L.bossUiX, L.bossHpY, pctHp, 0xdd0000);
            this.bossHpText.setText(`${this.strings.hpboss} ${Math.round(currentHp)}`);

            if (L.hasCharacterStage) {
                this.txtBossGold.setText(`${Math.round(gold)}`);
                this.txtBossStar.setText(`x${multiplier.toFixed(1)}`);
            } else {
                this.txtBossStar.setText(`⭐x${multiplier.toFixed(1)}`);
            }

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

        showExitConfirm() {
            if (this._confirmOpen) {
                return;
            }
            this._confirmOpen = true;
            const me = this.scene;
            const L = this.L;
            const cx = L.w / 2;
            const cy = L.h / 2;
            const boxW = Math.round(L.w * 0.78);
            const boxH = 200;
            const boxX = cx - boxW / 2;
            const boxY = cy - boxH / 2;

            const overlay = me.add.graphics().setDepth(20);
            overlay.fillStyle(0x000000, 0.75);
            overlay.fillRect(0, 0, L.w, L.h);
            overlay.fillStyle(0x222222, 1);
            overlay.fillRoundedRect(boxX, boxY, boxW, boxH, 16);

            const txtWarn = me.add.text(cx, boxY + 55, this.strings.exitwarning, {
                fontSize: '20px', fill: '#ffcc00', align: 'center'
            }).setOrigin(0.5).setDepth(21);

            const btnContinue = me.add.text(cx - 70, boxY + 145, this.strings.btncontinue, {
                fontSize: '18px', fill: '#ffffff', backgroundColor: '#224488', padding: {x: 14, y: 10}
            }).setOrigin(0.5).setInteractive().setDepth(21);

            const btnConfirmExit = me.add.text(cx + 70, boxY + 145, this.strings.btnquit, {
                fontSize: '18px', fill: '#ffffff', backgroundColor: '#882222', padding: {x: 22, y: 10}
            }).setOrigin(0.5).setInteractive().setDepth(21);

            const cleanup = () => {
                this._confirmOpen = false;
                overlay.destroy();
                txtWarn.destroy();
                btnContinue.destroy();
                btnConfirmExit.destroy();
            };

            btnContinue.on('pointerdown', cleanup);

            const viewurl = this.gameConfig.viewurl;
            btnConfirmExit.on('pointerdown', () => {
                window.close();
                setTimeout(() => {
                    window.location.href = viewurl;
                }, 300);
            });
        }

        updatePlayerBar(currentHp, maxHp, poisonMeter, poisonRounds, shieldMeter, shieldReady, mana, gold, multiplier) {
            const L = this.L;
            const pctHp = Math.max(0, currentHp / maxHp);
            this.drawHpFill(this.playerHpBar, L.playerUiX, L.playerHpY, pctHp, 0x00cc00);
            this.playerHpText.setText(
                `${this.strings.hpyou} ${Math.round(currentHp)} / ${maxHp}`
            );

            if (L.hasCharacterStage) {
                this.txtGold.setText(`${Math.round(gold)}`);
                this.txtStar.setText(`x${multiplier.toFixed(1)}`);
            } else {
                this.txtGold.setText(`🪙: ${Math.round(gold)}`);
                this.txtStar.setText(`⭐x${multiplier.toFixed(1)}`);
            }

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
