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
 * Main bootloader for the PlayerPuzzle Phaser game.
 *
 * @module     mod_playerpuzzle/game_boot
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global Phaser */

define([
    'jquery',
    'core/notification',
    'core/str',
    'mod_playerpuzzle/ui',
    'mod_playerpuzzle/combat',
    'mod_playerpuzzle/board'
], function($, notification, Str, UIHandler, CombatHandler, BoardHandler) {
    'use strict';

    // Phaser requires regular functions for preload/create so it can bind `this` to the scene.
    const startPhaser = (gameConfig, strings) => {

        let onExitConfirm = null;

        if (gameConfig.mobile) {
            history.pushState({ppgame: true}, '');
            window.addEventListener('popstate', () => {
                history.pushState({ppgame: true}, '');
                if (onExitConfirm) {
                    onExitConfirm();
                }
            });
        }

        // Must be regular function: Phaser binds `this` to the scene instance.
        const preload = function() {
            this.ui = new UIHandler(this, null, gameConfig, strings);
            this.ui.setupLoader();

            this.load.image('bg', gameConfig.bgurl);
            this.load.image('boss', gameConfig.bossurl);
            for (let i = 0; i < 7; i++) {
                this.load.image(`item${i}`, gameConfig.spriteurls[i]);
            }

            const urlPix = `${M.cfg.wwwroot}/mod/playerpuzzle/pix/`;
            this.load.audio('bg_music', `${urlPix}music.mp3`);
            this.load.audio('sfx_swap', `${urlPix}swap.mp3`);
            this.load.audio('sfx_match', `${urlPix}match.mp3`);
            this.load.audio('sfx_hit', `${urlPix}hit.mp3`);
        };

        // Must be regular function: Phaser binds `this` to the scene instance.
        const create = function() {
            const isDesk = window.innerWidth > window.innerHeight;

            const L = isDesk ? {
                w: 1280, h: 720, aspect: '16/9', maxW: '100%',
                bgX: 640, bgY: 360, bgW: 1280, bgH: 720,
                bossX: 1040, bossY: 260, bossScale: 180,
                bossUiX: 890, bossHpY: 380, bossManaY: 405, bossTxtY: 391, poisonX: 1200,
                playerUiX: 90, playerHpY: 380, playerManaY: 405, playerTxtY: 391,
                shieldX: 90, goldX: 240, starX: 390,
                boardOffX: 447.5, boardOffY: 167.5, btnExpX: 1260, btnExpY: 20
            } : {
                w: 540, h: 960, aspect: '9/16', maxW: '540px',
                bgX: 270, bgY: 480, bgW: 540, bgH: 960,
                bossX: 270, bossY: 75, bossScale: 100,
                bossUiX: 120, bossHpY: 135, bossManaY: 158, bossTxtY: 146, poisonX: 430,
                playerUiX: 120, playerHpY: 175, playerManaY: 198, playerTxtY: 186,
                shieldX: 120, goldX: 270, starX: 420,
                boardOffX: 77.5, boardOffY: 280, btnExpX: 520, btnExpY: 20
            };

            const containerDOM = $('#playerpuzzle-canvas-container');
            containerDOM.find('p').remove();
            if (!gameConfig.mobile) {
                containerDOM.toggleClass('pp-canvas-desktop', isDesk);
            }
            containerDOM.append($('#playerpuzzle-modal'));

            this.ui.L = L;
            this.ui.setupStaticUI();

            this.sfxSwap = this.sound.add('sfx_swap', {volume: 0.6});
            this.sfxMatch = this.sound.add('sfx_match', {volume: 0.5});
            this.sfxHit = this.sound.add('sfx_hit', {volume: 0.8});
            this.bgMusic = this.sound.add('bg_music', {volume: 0.3, loop: true});

            const me = this;
            const startMusic = () => {
                if (!me.bgMusic.isPlaying) {
                    me.bgMusic.play();
                }
            };
            if (!this.sound.locked) {
                startMusic();
            } else {
                this.sound.once(Phaser.Sound.Events.UNLOCKED, startMusic);
                // Android Chrome may not fire UNLOCKED via Phaser's internal mechanism.
                // Watch the AudioContext state directly as a fallback.
                if (this.sound.context) {
                    const ctx = this.sound.context;
                    const onCtxState = () => {
                        if (ctx.state === 'running') {
                            ctx.removeEventListener('statechange', onCtxState);
                            me.sound.locked = false;
                            startMusic();
                        }
                    };
                    ctx.addEventListener('statechange', onCtxState);
                }
            }

            this.combat = new CombatHandler(this, gameConfig, strings);
            this.board = new BoardHandler(this, L, strings);

            this.combat.updateUI();

            if (gameConfig.mobile) {
                onExitConfirm = () => {
                    me.ui.showExitConfirm();
                };
            }
        };

        const isDesk = window.innerWidth > window.innerHeight;
        const config = {
            type: Phaser.AUTO,
            parent: 'playerpuzzle-canvas-container',
            backgroundColor: '#1a1a1a',
            scale: {
                mode: Phaser.Scale.FIT,
                autoCenter: Phaser.Scale.CENTER_BOTH,
                width: isDesk ? 1280 : 540,
                height: isDesk ? 720 : 960,
                fullscreenTarget: document.getElementById('playerpuzzle-canvas-container')
            },
            input: {
                mouse: {
                    preventDefaultWheel: false
                },
                touch: {
                    capture: true
                }
            },
            scene: {preload: preload, create: create}
        };
        new Phaser.Game(config);
    };

    return {
        init() {
            $(document).ready(async() => {
                try {
                    const container = document.getElementById('playerpuzzle-canvas-container');
                    const configStr = container.getAttribute('data-config');
                    if (!configStr) {
                        throw new Error('Game configuration is missing from HTML.');
                    }

                    const config = JSON.parse(configStr);

                    const strKeys = [
                        'bossansweredcorrect', 'bossansweredwrong', 'bosscorrectfeedback',
                        'bosstrigger', 'bosswrongfeedback', 'btnattack', 'btncontinue',
                        'btnexit', 'btnexitgame', 'btnplayagain', 'btnquit',
                        'coinscollected', 'defeat', 'exitwarning', 'expand',
                        'hpboss', 'hpyou', 'loading', 'maxmultiplier',
                        'musicoff', 'musicon', 'noanswers', 'playercorrect',
                        'playerwrong', 'progresssaved', 'questionerror', 'requirejserror',
                        'saveerror', 'savingprogress', 'sfxoff', 'sfxon', 'shrink',
                        'shuffling', 'victory'
                    ];

                    const values = await Str.get_strings(
                        strKeys.map(key => ({key, component: 'mod_playerpuzzle'}))
                    );
                    const strings = {};
                    strKeys.forEach((key, i) => {
                        strings[key] = values[i];
                    });

                    require(['Phaser'], PhaserObj => {
                        if (PhaserObj) {
                            window.Phaser = PhaserObj;
                        }
                        startPhaser(config, strings);
                    }, err => {
                        window.console.error('RequireJS error:', err);
                        $('#playerpuzzle-canvas-container').html(
                            `<p class="text-danger">${strings.requirejserror}</p>`
                        );
                    });
                } catch (error) {
                    notification.exception(error);
                }
            });
        }
    };
});
