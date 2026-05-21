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
        }

        setupLoader() {
            this.scene.load.on('progress', value => {
                const percent = parseInt(value * 100);
                $('#pp-progress-bar')
                    .css('width', `${percent}%`)
                    .attr('aria-valuenow', percent)
                    .text(`${percent}%`);
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

            me.add.image(L.bgX, L.bgY, 'bg').setDisplaySize(L.bgW, L.bgH);

            this.bossSprite = me.add.image(L.bossX, L.bossY, 'boss')
                .setDisplaySize(L.bossScale, L.bossScale);

            this.txtPoison = me.add.text(L.poisonX, L.bossTxtY, '☠️', {fontSize: '16px'})
                .setOrigin(0.5)
                .setAlpha(0);

            const styleShield = {fontSize: '16px', fill: '#aaaaff', fontStyle: 'bold'};
            const styleGold = {fontSize: '16px', fill: '#ffffaa', fontStyle: 'bold'};
            const styleStar = {fontSize: '16px', fill: '#ffddaa', fontStyle: 'bold'};

            this.txtShield = me.add.text(L.shieldX, L.playerHpY + 40, '🛡️: 0', styleShield);
            this.txtGold = me.add.text(L.goldX, L.playerHpY + 40, '🪙: 0', styleGold)
                .setOrigin(0.5, 0);
            this.txtStar = me.add.text(L.starX, L.playerHpY + 40, '⭐x1.0', styleStar)
                .setOrigin(1, 0);

            me.add.graphics().fillStyle(0x000000, 0.8).fillRect(L.bossUiX, L.bossHpY, 300, 22);
            this.bossHpBar = me.add.graphics();
            this.bossHpText = me.add.text(L.bossUiX + 150, L.bossTxtY, '', {
                fontSize: '15px',
                fill: '#ffffff',
                fontStyle: 'bold'
            }).setOrigin(0.5);

            me.add.graphics().fillStyle(0x000000, 0.8).fillRect(L.bossUiX, L.bossManaY, 300, 8);
            this.bossManaBar = me.add.graphics();

            me.add.graphics().fillStyle(0x000000, 0.8).fillRect(L.playerUiX, L.playerHpY, 300, 22);
            this.playerHpBar = me.add.graphics();
            this.playerHpText = me.add.text(L.playerUiX + 150, L.playerTxtY, '', {
                fontSize: '15px',
                fill: '#ffffff',
                fontStyle: 'bold'
            }).setOrigin(0.5);

            me.add.graphics().fillStyle(0x000000, 0.8).fillRect(L.playerUiX, L.playerManaY, 300, 8);
            this.playerManaBar = me.add.graphics();

            this.setupButtons();
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

        updateBossBar(currentHp, maxHp, mana, poisonTurns) {
            const pctHp = Math.max(0, currentHp / maxHp);
            this.bossHpBar.clear()
                .fillStyle(0xdd0000, 1)
                .fillRect(this.L.bossUiX + 2, this.L.bossHpY + 2, 296 * pctHp, 18);
            this.bossHpText.setText(`${this.strings.hpboss} ${Math.round(currentHp)}`);

            const pctMana = Math.min(1, mana / 100);
            this.bossManaBar.clear()
                .fillStyle(0x0088ff, 1)
                .fillRect(this.L.bossUiX + 2, this.L.bossManaY + 1, 296 * pctMana, 6);

            this.txtPoison.setAlpha(poisonTurns > 0 ? 1 : 0);
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

        updatePlayerBar(currentHp, maxHp, mana, shield, gold, multiplier) {
            const pctHp = Math.max(0, currentHp / maxHp);
            this.playerHpBar.clear()
                .fillStyle(0x00cc00, 1)
                .fillRect(this.L.playerUiX + 2, this.L.playerHpY + 2, 296 * pctHp, 18);
            this.playerHpText.setText(
                `${this.strings.hpyou} ${Math.round(currentHp)} / ${maxHp}`
            );

            const pctMana = Math.min(1, mana / 100);
            this.playerManaBar.clear()
                .fillStyle(0x0088ff, 1)
                .fillRect(this.L.playerUiX + 2, this.L.playerManaY + 1, 296 * pctMana, 6);

            this.txtShield.setText(`🛡️: ${shield}`);
            this.txtGold.setText(`🪙: ${gold}`);
            this.txtStar.setText(`⭐x${multiplier.toFixed(1)}`);
        }
    }

    return UIHandler;
});
