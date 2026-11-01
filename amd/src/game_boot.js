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
    'mod_playerpuzzle/board',
    'mod_playerpuzzle/accessibility'
], function(notification, Str, UIHandler, CombatHandler, BoardHandler, Accessibility) {
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
                this.load.image('panelstone', `${M.cfg.wwwroot}/mod/playerpuzzle/pix/panel_stone.webp`);
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

            const L = isDesk ? {
                w: 1280, h: 720, aspect: '16/9', maxW: '100%',
                hasCharacterStage: true,

                // Character stage band: student/boss standing in front of stage_bg. stage_bg
                // reaches all the way to y=0 — the top buttons already carry their own opaque
                // background, so there's no seam to hide there.
                stageBgX: 640, stageBgY: 140, stageBgW: 1280, stageBgH: 280,
                bossScale: 190, playerScale: 190,
                bossX: 1090, bossY: 150,
                playerX: 190, playerY: 150,

                // Board + side panels band. panelY is the side panels' true top edge — they
                // stay flush against it, no overlap. panelW is derived from the board's own
                // size (not independent), so both panels always sit flush against its edges,
                // no gap. panelOverlap is consumed by the board instead: it rises above panelY
                // by that much, on purpose, reading as a raised platform poking up between the
                // two flanking panels — matches the reference screenshot the plugin is modeled
                // after — and board.js reuses the same number to extend its own backdrop fill
                // past the grid's real bottom edge, so the gap that rise opens up before the
                // true canvas bottom (720) stays covered.
                panelY: 280, panelOverlap: 14, panelW: 420,
                // A different pair, boardOffX/Y, is fed only to board.js: it treats this pair
                // as the center of the first cell (pieces are origin-centered images) rather
                // than the grid's top-left corner — it's the grid's own corner (420, 280)
                // minus panelOverlap on Y (the platform rise) plus half a cell (27.5) to
                // compensate for the center-origin. Never reuse this pair for anything drawn
                // as a plain top-left-origin rectangle (e.g. the side panels).
                boardOffX: 447.5, boardOffY: 293.5,

                // HP bars now live inside the panel, above the resource row. Centered within
                // the panel's own width ((420 - hpBarW) / 2 = 60) — clears panel_stone.png's
                // carved border on both sides (was 40/940, sitting under the border on one
                // side and asymmetric within the panel on the other).
                hpBarW: 300, hpBarH: 28, hpBarRadius: 14,
                bossUiX: 920, bossHpY: 300, bossTxtY: 314,
                playerUiX: 60, playerHpY: 300, playerTxtY: 314,

                // Consumables row: the two purchasable consumables with no meter of their own
                // (Potion, Sword). Shield and Quick Magic fill a ring instead, so their buy
                // badges live on that ring, not here. Coin/Star used to share this row but
                // they're passive readouts, not buttons — moved down beside the history block
                // (goldX/starX below), leaving this row to hold two items with room to breathe.
                // Kept clear of panel_stone.png's carved border, which eats ~57px of panel
                // width on each edge.
                potionX: 130, potionY: 388, swordX: 290, swordY: 388,
                // Boss side is the player row translated by the panel offset (860) — Potion and
                // Sword shown for visual parity only (no boss shop), plain icon, no buy badge.
                bossPotionX: 990, bossPotionY: 388, bossSwordX: 1150, bossSwordY: 388,

                // Coin/Star: passive quantity readouts, moved out of the action area to sit
                // beside the history block instead, stacked vertically just right of the scroll
                // banner — the space freed up when the Status badges moved to the HP bar's own
                // corner. Smaller icon (indicatorIconSize) than the consumable row's.
                goldX: 250, goldY: 543, starX: 250, starY: 574,
                bossGoldX: 1110, bossGoldY: 543, bossStarX: 1110, bossStarY: 574,
                indicatorIconSize: 26,
                // Gap between ring centers tightened 120 -> 100 (was 90/210/330) — same border
                // as the HP bar/resource row above, now clearing it on both sides instead of
                // sitting almost flush against it.
                bossRingY: 475, bossGrimoireX: 970, bossShieldRingX: 1070, bossOrbX: 1170,
                playerRingY: 475, playerGrimoireX: 110, playerShieldRingX: 210, playerOrbX: 310,

                ringRadius: 32, ringThickness: 7, ringIconSize: 40, resourceIconSize: 60,

                // Action history: 5 lines per side, below the rings. Desktop-only, same as
                // the player character and resource chips — no mobile equivalent yet. Title Y
                // (and the line Y that follows it) both shifted down — at the old 500/520 the
                // scroll banner behind the title (see ui.js createHistoryLog()) overlapped the
                // bottom of the ring row above it.
                historyTitleY: 548, historyLineY: 585, historyLineHeight: 24,

                // Compact circular icon buttons (Music/Sound Effects/Expand), right-aligned —
                // replaces the old bracket-text "[ Word ]" buttons, which read as
                // generic HTML/Bootstrap controls rather than the game's own bronze-accent
                // dark-fill language already used for rings/badges. ui.js computes each
                // button's own X from L.w, topBtnGap and its position in the row (rightmost =
                // Expand), so only the shared Y/radius/gap live here.
                topBtnY: 26, topBtnRadius: 20, topBtnGap: 50
            } : {
                // Portrait layout: a stage band across the top, then the opponent's HUD, the
                // board, the student's HUD and the history button. The band shows the phase's
                // own wide stage (the same image the desktop band uses), scaled to the band's
                // height and centred, so the middle of the scene shows and no portrait art is
                // needed; the student and the opponent stand at its two ends, at a size that
                // reads on a phone. Below the band, a plain dark backdrop: every px here is
                // shared with the fixed 440x440 board, so the HUD clusters are single compact
                // rows on a translucent panel (ui.js::drawSimplePanel()), not the desktop's
                // panel_stone.png/scroll_banner.png artwork.
                w: 540, h: 960, aspect: '9/16', maxW: '540px',
                hasCharacterStage: false,
                stageBandH: 236,
                backdropColor: 0x2b241e,

                // Over the band: the icon-button row (Music/Sound Effects/Expand, see the
                // desktop L's own topBtnY/Radius/Gap comment) on row 1, and the level/phase
                // indicator on its own row 2 — the full pt_BR progress string does not fit next
                // to the buttons at any readable size.
                progressIndicatorY: 58,
                topBtnY: 24, topBtnRadius: 18, topBtnGap: 44,

                // The two characters stand on the band's floor, below the indicator row. Their
                // sprites put the feet at 95% of the frame, so a 150px sprite centred 14px above
                // the band's own centre lands its feet just above the band's bottom edge.
                playerX: 88, playerY: 158, playerScale: 150,
                bossX: 452, bossY: 158, bossScale: 150,

                // Opponent HUD: HP bar (Status badges hang off its own corner), then one row
                // with Coin/Star and the Grimoire/Shield/Orb rings — no Potion and no purchase
                // badges, since the opponent has no shop.
                panelTopBoss: 242, panelBottomBoss: 320,
                hpBarW: 300, hpBarH: 22, hpBarRadius: 11,
                bossUiX: 120, bossHpY: 250, bossTxtY: 261,
                bossGoldX: 60, bossGoldY: 296, bossStarX: 160, bossStarY: 296,
                bossRingY: 296, bossGrimoireX: 330, bossShieldRingX: 390, bossOrbX: 450,

                // Board: fixed 440x440 (board.js hardcodes pieceSize 55 regardless of layout),
                // centred horizontally ((540-440)/2 = 50), 8px below the opponent's panel —
                // boardOffX/Y is the piece-centre reference board.js expects (top-left 50/328
                // plus half a cell 27.5), same convention as the desktop boardOffX/Y comment.
                boardOffX: 77.5, boardOffY: 355.5,

                // Student HUD: HP bar, the "Perguntas: X/N" counter (only with a minimum of
                // questions), then one row with Coin/Star, the Potion and the rings, with
                // purchase badges on the Potion, Grimoire and Shield.
                panelTopPlayer: 776, panelBottomPlayer: 870,
                playerUiX: 120, playerHpY: 784, playerTxtY: 795,
                questionsCounterY: 811,
                goldX: 60, goldY: 846, starX: 150, starY: 846,
                potionX: 250, potionY: 846,
                playerRingY: 846, playerGrimoireX: 330, playerShieldRingX: 390, playerOrbX: 450,

                // History: one button (not two always-visible 5-line blocks like desktop —
                // there's no room) opening a modal with Player/Boss tabs, see
                // ui.js::showHistoryModalMobile().
                historyBtnY: 904,

                resourceIconSize: 32,
                ringRadius: 16, ringThickness: 4, ringIconSize: 22,

                // Purchase badge scale for ui.js::createPurchaseBadge() — the desktop's default
                // 46x34 badge is bigger than mobile's own 32px-diameter ring, swallowing its
                // colored arc entirely. 0.72 is the smallest scale that still clears the WCAG
                // 24x24 real-px floor at mobile's true 1:1 CSS scale (34 * 0.72 ≈ 24.5),
                // leaving the ring's own icon/arc visible next to it. See
                // createPurchaseBadge()'s own docblock for the full scale math (desktop vs
                // mobile canvas CSS shrink).
                badgeScale: 0.72
            };

            const containerDOM = document.getElementById('playerpuzzle-canvas-container');
            containerDOM.querySelectorAll('p').forEach(el => el.remove());
            // Applied regardless of device: mobile no longer gets a separate full-viewport
            // embedded layout, so a phone rotated to landscape gets the same capped 16:9
            // desktop treatment a wide browser window would.
            containerDOM.classList.toggle('pp-canvas-desktop', isDesk);
            containerDOM.appendChild(document.getElementById('playerpuzzle-modal'));

            this.ui.L = L;
            // Sets this.musicOn/this.sfxOn from the loaded user preference (defaulting to
            // enabled), read below before the sound objects are created.
            this.ui.setupStaticUI();

            const sfxVolume = this.sfxOn ? 1 : 0;
            this.sfxSwap = this.sound.add('sfx_swap', {volume: 0.6 * sfxVolume});
            this.sfxMatch = this.sound.add('sfx_match', {volume: 0.5 * sfxVolume});
            this.sfxHit = this.sound.add('sfx_hit', {volume: 0.8 * sfxVolume});
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
                    'bossansweredcorrect', 'bossansweredwrong', 'bosscorrectfeedback',
                    'bosslostmultiplier', 'bossrevived', 'bosstrigger', 'bosswrongfeedback',
                    'btnattack', 'btncontinue', 'btnexitgame', 'btnplayagain',
                    'coinscollected',
                    'damagedealt', 'damagetaken',
                    'debriefcorrectanswer', 'debriefcorrectsr', 'debriefempty', 'debriefreview',
                    'debriefsummary', 'debrieftitle', 'debriefwrongsr', 'debriefyouranswer',
                    'defeat',
                    'demostep_fullscreen_text', 'demostep_fullscreen_title',
                    'demostep_hp_text', 'demostep_hp_title',
                    'demostep_music_text', 'demostep_music_title',
                    'demostep_sfx_text', 'demostep_sfx_title',
                    'demostep_welcome_text', 'demostep_welcome_title',
                    'demowalkthrough_next', 'demowalkthrough_progress', 'demowalkthrough_start',
                    'demowalkthrough_title',
                    'difficulty_easy', 'difficulty_hard', 'difficulty_normal',
                    'hintbutton',
                    'historylogattack', 'historylogcoins', 'historylogcritical', 'historylogempty',
                    'historylogheal', 'historylogmana', 'historylogmultiplier',
                    'historylogmultiplierlost', 'historylogpoisoncharge', 'historylogpoisontick',
                    'historylogrevive', 'historylogshieldblock', 'historylogshieldcharge',
                    'historylogtitle', 'historylogwronganswer',
                    'hpboss', 'hpyou', 'loading', 'manafull', 'maxmultiplier',
                    'nextlevel', 'nextphase', 'noanswers',
                    'phaseadvanced', 'phaseadvanceerror', 'phasecompletetitle', 'phasedifficulty',
                    'piece_coin', 'piece_coin_plural', 'piece_grimoire', 'piece_grimoire_plural',
                    'piece_orb', 'piece_orb_plural', 'piece_potion', 'piece_potion_plural',
                    'piece_shield', 'piece_shield_plural', 'piece_star', 'piece_star_plural',
                    'piece_sword', 'piece_sword_plural',
                    'playercorrect',
                    'playerlostmultiplier', 'playerwrong', 'progressindicator', 'progresssaved',
                    'questionerror', 'questionscounter', 'questionsprogress',
                    'requirejserror', 'saveerror', 'savingprogress',
                    'shoperror', 'shuffling',
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
