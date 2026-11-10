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
    'mod_playerpuzzle/layout',
    'mod_playerpuzzle/ui',
    'mod_playerpuzzle/combat',
    'mod_playerpuzzle/board',
    'mod_playerpuzzle/accessibility',
    'mod_playerpuzzle/scene_dom'
], (notification, Str, Layout, UIHandler, CombatHandler, BoardHandler, Accessibility, SceneDom) => {
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

    // Scale.FIT stretches the game canvas up via CSS to fill however wide the page actually
    // renders the container — up to the full course content column on desktop — while the
    // canvas' own backing store stays fixed at the logical size below. Without supersampling,
    // that CSS stretch blurs both sprites and Phaser Text alike on any screen wider than the
    // logical size. Rendering at SUPERSAMPLE times the logical size and then zooming the main
    // camera by the same factor (see create()) fixes this without touching a single coordinate
    // in the L layout objects below — those still describe the original 1280x720 / 540x960
    // design space, the zoomed camera just projects it into the bigger backing store, which
    // CSS then mostly scales back down instead of up. 2x matches the standard Retina/HiDPI
    // baseline: comfortably crisp on both a wide desktop monitor at 1x device pixel ratio and
    // a narrower one at 2x, without the GPU/memory cost of going higher.
    const SUPERSAMPLE = 2;

    // Shows the same "the game failed to start" message the Phaser-script-load failure path
    // (onLoadError, below) already uses, reused here for two failures that can only happen
    // once Phaser itself is already running: an exception thrown while preloading assets or
    // assembling the scene, and a boot that never completes at all. Phaser invokes
    // preload()/create() through its own internal scene manager, entirely outside this
    // module's own call stack — a thrown error in either would otherwise become a silently
    // uncaught exception, visible only in the browser devtools console, leaving a
    // screen-reader user with a stuck loading screen and no indication anything went wrong.
    const showBootError = (strings) => {
        const errContainer = document.getElementById('playerpuzzle-canvas-container');
        if (errContainer) {
            errContainer.innerHTML = `<p class="text-danger">${strings.requirejserror}</p>`;
        }
    };

    // Phaser requires regular functions for preload/create so it can bind `this` to the scene.
    const startPhaser = (gameConfig, strings) => {
        let bootTimeoutId = null;

        // (Re)arms the boot-stall timeout, replacing any previous one. Called once before
        // the game is even created, and again on every asset the loader finishes — so a
        // slow-but-progressing connection (each file resetting the clock) never trips it,
        // only a genuine stall (nothing at all completes for a full 20s) does. See the
        // showBootError() comment above for why a silent failure here matters.
        const armBootTimeout = () => {
            window.clearTimeout(bootTimeoutId);
            bootTimeoutId = window.setTimeout(() => {
                window.console.error('PlayerPuzzle boot stalled: no loading progress for 20s.');
                showBootError(strings);
            }, 20000);
        };

        // Must be regular function: Phaser binds `this` to the scene instance.
        const preloadScene = function() {
            this.ui = new UIHandler(this, null, gameConfig, strings);
            this.ui.setupLoader();
            this.load.on('progress', armBootTimeout);

            // Both layouts draw the phase's stage band; 'panelstone'/'scrollbanner' are only
            // placed by ui.js's desktop (hasCharacterStage) branch, so a portrait phone never
            // downloads them. isDesk here mirrors the same window-dimension check createScene()
            // does later; a mid-load orientation change could in theory skip the now-needed
            // ones, same narrow edge case the "apply desktop layout immediately" comment in
            // init() already accepts for the container's own CSS class.
            const isDesk = window.innerWidth > window.innerHeight;
            this.load.image('stagebg', gameConfig.stagebgurl);
            if (isDesk) {
                this.load.image('panelstone', `${M.cfg.wwwroot}/mod/playerpuzzle/pix/panel_frame.webp`);
                this.load.image('scrollbanner', `${M.cfg.wwwroot}/mod/playerpuzzle/pix/scroll_banner.webp`);
            }

            this.load.image('player', gameConfig.playerurl);
            this.load.image('boss', gameConfig.bossurl);
            for (let i = 0; i < 7; i++) {
                this.load.image(`item${i}`, gameConfig.spriteurls[i]);
            }

            // The background music is deliberately not loaded here: preload has to finish before
            // the scene starts, so a track of several hundred KB would delay every boot, even for
            // students who turned music off. createScene() fetches it in the background instead.
            const urlPix = `${M.cfg.wwwroot}/mod/playerpuzzle/pix/`;
            this.load.audio('sfx_swap', `${urlPix}swap.mp3`);
            this.load.audio('sfx_match', `${urlPix}match.mp3`);
            this.load.audio('sfx_hit', `${urlPix}hit.mp3`);
        };

        const preload = function() {
            try {
                preloadScene.call(this);
            } catch (err) {
                window.clearTimeout(bootTimeoutId);
                window.console.error('PlayerPuzzle preload failed:', err);
                showBootError(strings);
            }
        };

        // Must be regular function: Phaser binds `this` to the scene instance.
        const createScene = function() {
            const isDesk = window.innerWidth > window.innerHeight;

            // Camera zoom projects the L layout's 1280x720 / 540x960 design coordinates into
            // the SUPERSAMPLE-times-bigger canvas backing store set up below — see the
            // SUPERSAMPLE comment above for why. Must match 1:1 or every draw call in this
            // scene would land at the wrong scale relative to the backing store.
            //
            // setZoom() alone re-centers the view instead of pinning it to the origin: on a
            // fresh camera (default view spanning 0,0 - config.width/height at zoom 1, so
            // centered on config.width/2, config.height/2), zooming in shifts the visible world
            // away from true (0, 0) — worldView ends up centered on that same point instead,
            // e.g. (config.width/4, config.height/4) off from the corner our L layout actually
            // draws into. Bounding the camera to exactly the visible area at this zoom (the
            // unzoomed design size) leaves it nowhere to scroll, forcing worldView back to
            // (0, 0) deterministically — unlike setScroll(0, 0) / setting scrollX/scrollY
            // directly, which don't reliably override that re-centering in this Phaser version.
            const applyZoom = () => {
                this.cameras.main.setZoom(SUPERSAMPLE);
                this.cameras.main.setBounds(0, 0, isDesk ? 1280 : 540, isDesk ? 720 : 960);
            };
            applyZoom();
            // The Scale Manager also resizes (and re-centers) every camera on its own resize
            // pass — triggered by the container class toggle further down in this function, by
            // window resize, and by the fullscreen toggle — silently undoing the zoom set
            // above. Reapply on every one of those instead of only once at boot.
            this.scale.on(Phaser.Scale.Events.RESIZE, applyZoom);

            const L = Layout.build(isDesk);

            const containerDOM = document.getElementById('playerpuzzle-canvas-container');
            containerDOM.querySelectorAll('p').forEach(el => el.remove());
            // Applied regardless of device: mobile no longer gets a separate full-viewport
            // embedded layout, so a phone rotated to landscape gets the same capped 16:9
            // desktop treatment a wide browser window would.
            containerDOM.classList.toggle('pp-canvas-desktop', isDesk);
            containerDOM.appendChild(document.getElementById('playerpuzzle-modal'));
            ['playerpuzzle-opponent-help', 'playerpuzzle-giveup'].forEach(id => {
                const dialog = document.getElementById(id);
                if (dialog) {
                    containerDOM.appendChild(dialog);
                }
            });

            this.ui.L = L;
            // Sets this.musicOn/this.sfxOn from the loaded user preference (defaulting to
            // enabled), read below before the sound objects are created.
            this.ui.setupStaticUI();

            const sfxVolume = this.sfxOn ? 1 : 0;
            this.sfxSwap = this.sound.add('sfx_swap', {volume: 0.6 * sfxVolume});
            this.sfxMatch = this.sound.add('sfx_match', {volume: 0.5 * sfxVolume});
            this.sfxHit = this.sound.add('sfx_hit', {volume: 0.65 * sfxVolume});
            this.bgMusic = null;

            const me = this;
            let musicRequested = false;
            // Plays the background music when it is on, downloading it first in the background
            // if needed (only ever once). Called at start, when the audio unlocks and when the
            // student turns music on, so every path ends up playing as soon as it can.
            this.playMusic = () => {
                if (!me.musicOn) {
                    return;
                }
                if (!me.cache.audio.exists('bg_music')) {
                    if (!musicRequested) {
                        musicRequested = true;
                        me.load.audio('bg_music', `${M.cfg.wwwroot}/mod/playerpuzzle/pix/music.mp3`);
                        me.load.once('filecomplete-audio-bg_music', () => me.playMusic());
                        me.load.start();
                    }
                    return;
                }
                if (me.sound.locked) {
                    return;
                }
                if (!me.bgMusic) {
                    me.bgMusic = me.sound.add('bg_music', {volume: 0.3, loop: true});
                }
                if (me.bgMusic.isPaused) {
                    me.bgMusic.resume();
                } else if (!me.bgMusic.isPlaying) {
                    me.bgMusic.play();
                }
            };
            this.pauseMusic = () => {
                if (me.bgMusic && me.bgMusic.isPlaying) {
                    me.bgMusic.pause();
                }
            };
            // Starts the download right away; while the browser still blocks audio, the unlock
            // handlers below play it once it is allowed.
            this.playMusic();
            if (this.sound.locked) {
                this.sound.once(Phaser.Sound.Events.UNLOCKED, () => me.playMusic());
                // Android Chrome may not fire UNLOCKED via Phaser's internal mechanism.
                // Watch the AudioContext state directly as a fallback.
                if (this.sound.context) {
                    const ctx = this.sound.context;
                    const onCtxState = () => {
                        if (ctx.state === 'running') {
                            ctx.removeEventListener('statechange', onCtxState);
                            me.sound.locked = false;
                            me.playMusic();
                        }
                    };
                    ctx.addEventListener('statechange', onCtxState);
                }
            }

            this.combat = new CombatHandler(this, gameConfig, strings);
            this.board = new BoardHandler(this, L, strings);
            this.ui.createOpponentItems(this.combat.ruleConfig().bossKit);

            this.combat.updateUI();
            this.board.resumeFlow();

            // Demo match: board/combat input stays locked until the student
            // steps through the static walkthrough — re-enabled by its own onDismiss callback.
            if (gameConfig.isdemo) {
                this.input.enabled = false;
                this.ui.showDemoWalkthrough(() => {
                    this.input.enabled = true;
                });
            }
        };

        const create = function() {
            // The boot is over once the scene is being built: anything loaded later (the music)
            // must not re-arm the boot-stall timeout and replace a running game with an error.
            this.load.off('progress', armBootTimeout);
            try {
                createScene.call(this);
                window.clearTimeout(bootTimeoutId);
            } catch (err) {
                window.clearTimeout(bootTimeoutId);
                window.console.error('PlayerPuzzle scene creation failed:', err);
                showBootError(strings);
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
                width: (isDesk ? 1280 : 540) * SUPERSAMPLE,
                height: (isDesk ? 720 : 960) * SUPERSAMPLE,
                fullscreenTarget: document.getElementById('playerpuzzle-canvas-container')
            },
            input: {
                mouse: {
                    preventDefaultWheel: false
                },
                // False: with capture:true, Phaser's own input manager calls
                // preventDefault() on every touch anywhere over the canvas unconditionally —
                // combined with the canvas' own touch-action CSS, that blocked page scroll
                // through the ENTIRE canvas, not just the board, trapping mobile players who
                // touched the game while trying to scroll past it. board.js now does its own
                // narrowly-scoped preventDefault, only for a touch that actually starts on a
                // board piece (see board.js::maybeBlockScroll()) — this flag has to stay off
                // for that call to have any effect: Phaser attaches its underlying listener as
                // passive when capture is off, but that only governs Phaser's OWN listener, not
                // the separate one board.js adds directly on the canvas element.
                touch: {
                    capture: false
                }
            },
            scene: {preload: preload, create: create}
        };

        // Arms the initial stall timeout, covering the case where not even the first asset
        // request ever responds (so the 'progress' listener in preloadScene() never fires to
        // re-arm it itself). Cleared by create() above on success or on a caught error.
        armBootTimeout();

        const game = new Phaser.Game(config);

        // Phaser draws game state directly onto the canvas pixels, with no corresponding DOM
        // text a screen reader could ever read — hiding it from the accessibility tree avoids
        // announcing a bare, meaningless "canvas" element on every visit. The real accessible
        // surface is the parallel HTML the container's own role="application" + aria-label
        // already point to: the hidden grid table and the aria-live region.
        game.canvas.setAttribute('aria-hidden', 'true');
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
                Accessibility.setSpeechEnabled(!!config.enablespeech);

                // Apply desktop layout immediately so the loading screen renders at the correct size.
                if (window.innerWidth > window.innerHeight) {
                    container.classList.add('pp-canvas-desktop');
                }

                const strKeys = [
                    'advancingphase',
                    'bossansweredcorrect', 'bossansweredwrong', 'bosscorrectblocked', 'bosscorrectfeedback',
                    'bosslostmultiplier', 'bossrevived', 'bosstrigger', 'bosswrongfeedback',
                    'btnattack', 'btncontinue', 'btnexitgame', 'btnplayagain', 'btnstartphase',
                    'campaigncomplete', 'coinscollected',
                    'damagedealt', 'damagetaken',
                    'debriefcorrectanswer', 'debriefcorrectsr', 'debriefempty', 'debriefreview',
                    'debriefsummary', 'debrieftitle', 'debriefwrongsr', 'debriefyouranswer',
                    'defeat',
                    'demostep_fullscreen_text', 'demostep_fullscreen_title',
                    'demostep_giveup_text', 'demostep_giveup_title',
                    'demostep_help_text', 'demostep_help_title',
                    'demostep_hp_text', 'demostep_hp_title',
                    'demostep_music_text', 'demostep_music_title',
                    'demostep_sfx_text', 'demostep_sfx_title',
                    'demostep_welcome_text', 'demostep_welcome_title',
                    'demounseen',
                    'demowalkthrough_next', 'demowalkthrough_progress', 'demowalkthrough_start',
                    'demowalkthrough_title',
                    'difficulty_easy', 'difficulty_hard', 'difficulty_normal',
                    'hintbuyinlobby', 'hintbutton',
                    'historylogambush', 'historylogbreath', 'historylogbreathblocked', 'historylogbreathtaken',
                    'historylogcoins', 'historylogcritical', 'historylogcriticalblocked', 'historylogcriticaltaken',
                    'historylogdrain', 'historylogempty', 'historylogintangible', 'historylogitempotion',
                    'historylogkit', 'historylogkit_potion', 'historylogkit_sword', 'historylogloot', 'historylogopening_boss',
                    'historylogopening_player', 'historyloglooted',
                    'historylograid', 'historylograidblocked', 'historylograidtaken',
                    'historylogheal', 'historylogmana', 'historylogmultiplier',
                    'historylogmultiplierlost', 'historylogpoisoncharge', 'historylogpoisontick',
                    'historylogpower_ambush', 'historylogpower_breath', 'historylogpower_drain',
                    'historylogpower_fastgrimoire',
                    'historylogpower_iceshield', 'historylogpower_intangible', 'historylogpower_loot',
                    'historylogpower_longpoison', 'historylogpower_manaboost', 'historylogpower_regen',
                    'historylogpower_rootheal',
                    'historylogregen', 'historylogrevive', 'historylogshieldblock', 'historylogshieldcharge',
                    'historylogstrike', 'historylogstrikeblocked', 'historylogstriketaken',
                    'historylogsword', 'historylogswordblocked', 'historylogswordtaken',
                    'historylogtitle', 'historylogwronganswer',
                    'hpboss', 'hpyou', 'loading', 'manafull', 'maxmultiplier',
                    'nextlevel', 'nextphase', 'noanswers', 'opponentitempotion',
                    'opponentkit', 'opponentkit_potion', 'opponentkit_sword',
                    'openingright', 'openingtrigger', 'openingwrong',
                    'phaseadvanced', 'phaseadvanceerror', 'phasecompletetitle', 'phasedifficulty', 'phaselost', 'phaseshoptitle',
                    'shop_hero_evolved',
                    'piece_coin', 'piece_coin_plural', 'piece_grimoire', 'piece_grimoire_plural',
                    'piece_orb', 'piece_orb_plural', 'piece_potion', 'piece_potion_plural',
                    'piece_shield', 'piece_shield_plural', 'piece_star', 'piece_star_plural',
                    'piece_sword', 'piece_sword_plural',
                    'playercorrect', 'playercorrectblocked',
                    'power_ambush', 'power_breath', 'power_drain', 'power_fastgrimoire', 'power_iceshield',
                    'power_longpoison',
                    'power_intangible', 'power_loot', 'power_manaboost',
                    'power_regen', 'power_rootheal',
                    'playerlostmultiplier', 'playerwrong', 'progressindicator', 'progresssaved',
                    'questionerror', 'questionscounter', 'questionsprogress',
                    'requirejserror', 'retrydifficulty', 'saveerror', 'savingprogress',
                    'shoperror', 'shuffling',
                    'stage_fullscreen', 'stage_giveup', 'stage_help', 'stage_use_item',
                    'lobby_music_label', 'lobby_sfx_label',
                    'turnstart_intro', 'turnstart_move',
                    'tutorialballoon_coin', 'tutorialballoon_grimoire', 'tutorialballoon_orb',
                    'tutorialballoon_potion', 'tutorialballoon_shield', 'tutorialballoon_star',
                    'tutorialballoon_sword', 'tutorialquestioninstruction',
                    'victory', 'victoryunverifiedlost', 'victoryunverifiedrestart'
                ];

                const values = await Str.get_strings(
                    strKeys.map(key => ({key, component: 'mod_playerpuzzle'}))
                );
                const strings = {};
                strKeys.forEach((key, i) => {
                    strings[key] = values[i];
                });

                if (config.renderer === 'dom') {
                    try {
                        window.__dom = {scene: await SceneDom.start(config, strings)};
                    } catch (err) {
                        window.console.error('PlayerPuzzle failed to start:', err);
                        container.innerHTML = `<p class="text-danger">${strings.requirejserror}</p>`;
                    }
                    return;
                }

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
