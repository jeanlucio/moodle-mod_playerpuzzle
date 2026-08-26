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
    'core/notification',
    'core/str',
    'mod_playerpuzzle/ui',
    'mod_playerpuzzle/combat',
    'mod_playerpuzzle/board'
], function(notification, Str, UIHandler, CombatHandler, BoardHandler) {
    'use strict';

    let phaserLoadPromise = null;

    // Loads Phaser as a dynamically-injected <script>, resolved via its onload event, instead
    // of a static <script> tag queued through $PAGE->requires->js(). A static tag there would
    // sit in the page's footer output and race core_message/message_drawer.js, which expects
    // its own drawer markup (rendered further down the same footer) to already be in the DOM
    // by the time its own require() callback runs — same pattern as filter_mathjaxloader's
    // loadMathJax() (filter/mathjaxloader/amd/src/loader.js).
    const loadPhaser = (url) => {
        if (!phaserLoadPromise) {
            phaserLoadPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.type = 'text/javascript';
                script.onload = resolve;
                script.onerror = reject;
                script.src = url;
                document.getElementsByTagName('head')[0].appendChild(script);
            });
        }
        return phaserLoadPromise;
    };

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
            this.load.image('stagebg', gameConfig.stagebgurl);
            this.load.image('player', gameConfig.playerurl);
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
                hasCharacterStage: true,

                // Character stage band: student/boss standing in front of stage_bg, HP
                // anchored under each. stage_bg reaches all the way to y=0 — the top buttons
                // already carry their own opaque background, so there's no seam to hide.
                // Board+panels band (panelY) starts exactly where this one ends, at y=280.
                stageBgX: 640, stageBgY: 140, stageBgW: 1280, stageBgH: 280,
                bossScale: 190, playerScale: 190,
                bossX: 1090, bossY: 150,
                playerX: 190, playerY: 150,
                bossUiX: 940, bossHpY: 250, bossTxtY: 261,
                playerUiX: 40, playerHpY: 250, playerTxtY: 261,

                // Board + side panels band. panelW is derived from the board's own size (not
                // independent), so both panels always sit flush against its edges, no gap.
                // panelY is the band's true top edge; boardOffX/Y is a *different* number fed
                // to board.js, which treats it as the center of the first cell (pieces are
                // origin-centered images) rather than the grid's top-left corner — it's
                // panelY/boardOffX's corner (280, 420) plus half a cell (27.5) to compensate.
                // Conflating the two (reusing boardOffY for the panel rect) is exactly the bug
                // fixed here: it pushed the panel 27.5px below the stage band, leaving a gap.
                panelY: 280, panelW: 420,
                boardOffX: 447.5, boardOffY: 307.5,
                bossRingY: 400, bossGrimoireX: 950, bossShieldRingX: 1070, bossOrbX: 1190,
                playerRingY: 400, playerGrimoireX: 90, playerShieldRingX: 210, playerOrbX: 330,
                goldX: 140, goldY: 330, starX: 280, starY: 330,
                bossGoldX: 1000, bossGoldY: 330, bossStarX: 1140, bossStarY: 330,

                ringRadius: 32, ringThickness: 7, ringIconSize: 40, resourceIconSize: 60,
                btnExpX: 1260, btnExpY: 20
            } : {
                w: 540, h: 960, aspect: '9/16', maxW: '540px',
                hasCharacterStage: false,
                bgX: 270, bgY: 480, bgW: 540, bgH: 960,
                bossX: 270, bossY: 75, bossScale: 100,
                bossUiX: 120, bossHpY: 135, bossTxtY: 146,
                bossRingY: 180, bossGrimoireX: 170, bossShieldRingX: 270, bossOrbX: 370,
                bossStarX: 120, bossStarY: 214,
                playerUiX: 120, playerHpY: 237, playerTxtY: 248,
                playerRingY: 282, playerGrimoireX: 170, playerShieldRingX: 270, playerOrbX: 370,
                goldX: 270, goldY: 298, starX: 420, starY: 298,
                boardOffX: 77.5, boardOffY: 330, btnExpX: 520, btnExpY: 20,
                ringRadius: 16, ringThickness: 4, ringIconSize: 22
            };

            const containerDOM = document.getElementById('playerpuzzle-canvas-container');
            containerDOM.querySelectorAll('p').forEach(el => el.remove());
            if (!gameConfig.mobile) {
                containerDOM.classList.toggle('pp-canvas-desktop', isDesk);
            }
            containerDOM.appendChild(document.getElementById('playerpuzzle-modal'));

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
        async init() {
            try {
                const container = document.getElementById('playerpuzzle-canvas-container');
                const configStr = container.getAttribute('data-config');
                if (!configStr) {
                    throw new Error('Game configuration is missing from HTML.');
                }

                const config = JSON.parse(configStr);

                // Apply desktop layout immediately so the loading screen renders at the correct size.
                if (!config.mobile && window.innerWidth > window.innerHeight) {
                    container.classList.add('pp-canvas-desktop');
                }

                const strKeys = [
                    'bossansweredcorrect', 'bossansweredwrong', 'bosscorrectfeedback',
                    'bosslostmultiplier', 'bosstrigger', 'bosswrongfeedback', 'btnattack',
                    'btncontinue', 'btnexit', 'btnexitgame', 'btnplayagain', 'btnquit',
                    'coinscollected', 'defeat', 'exitwarning', 'expand',
                    'hpboss', 'hpyou', 'loading', 'maxmultiplier',
                    'musicoff', 'musicon', 'noanswers', 'playercorrect',
                    'playerlostmultiplier', 'playerwrong', 'progressindicator', 'progresssaved',
                    'questionerror', 'requirejserror', 'saveerror', 'savingprogress', 'sfxoff',
                    'sfxon', 'shrink', 'shuffling', 'victory'
                ];

                const values = await Str.get_strings(
                    strKeys.map(key => ({key, component: 'mod_playerpuzzle'}))
                );
                const strings = {};
                strKeys.forEach((key, i) => {
                    strings[key] = values[i];
                });

                const onLoadError = (err) => {
                    window.console.error('Phaser load error:', err);
                    const errContainer = document.getElementById('playerpuzzle-canvas-container');
                    if (errContainer) {
                        errContainer.innerHTML = `<p class="text-danger">${strings.requirejserror}</p>`;
                    }
                };

                try {
                    await loadPhaser(`${M.cfg.wwwroot}/mod/playerpuzzle/javascript/phaser.min.js`);
                } catch (err) {
                    onLoadError(err);
                    return;
                }

                // Phaser's UMD bundle self-registers as the AMD module "Phaser" the moment it
                // executes (checked above via its own onload), so this resolves immediately.
                require(['Phaser'], PhaserObj => {
                    if (PhaserObj) {
                        window.Phaser = PhaserObj;
                    }
                    startPhaser(config, strings);
                }, onLoadError);
            } catch (error) {
                notification.exception(error);
            }
        }
    };
});
