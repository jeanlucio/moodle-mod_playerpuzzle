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

    // Phaser requires regular functions for preload/create so it can bind `this` to the scene.
    const startPhaser = (gameConfig, strings) => {

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
            this.load.image('panelstone', `${urlPix}panel_stone.png`);
            this.load.image('scrollbanner', `${urlPix}scroll_banner.png`);
            this.load.audio('bg_music', `${urlPix}music.mp3`);
            this.load.audio('sfx_swap', `${urlPix}swap.mp3`);
            this.load.audio('sfx_match', `${urlPix}match.mp3`);
            this.load.audio('sfx_hit', `${urlPix}hit.mp3`);
        };

        // Must be regular function: Phaser binds `this` to the scene instance.
        const create = function() {
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

                // Consumables row: the two purchasable consumíveis with no meter of their own
                // (Poção, Espada). Escudo and Magia Rápida fill a ring instead, so their buy
                // badges live on that ring, not here. Coin/Star used to share this row but
                // they're passive readouts, not buttons — moved down beside the history block
                // (goldX/starX below), leaving this row to hold two items with room to breathe.
                // Kept clear of panel_stone.png's carved border, which eats ~57px of panel
                // width on each edge.
                potionX: 130, potionY: 388, swordX: 290, swordY: 388,
                // Boss side is the player row translated by the panel offset (860) — Potion and
                // Espada shown for visual parity only (no boss shop), plain icon, no buy badge.
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

                // Compact circular icon buttons (Música/Efeitos/Expandir), right-aligned —
                // replaces the old bracket-text "[ Word ]" buttons (28/08/2026), which read as
                // generic HTML/Bootstrap controls rather than the game's own bronze-accent
                // dark-fill language already used for rings/badges. ui.js computes each
                // button's own X from L.w, topBtnGap and its position in the row (rightmost =
                // Expandir), so only the shared Y/radius/gap live here.
                topBtnY: 26, topBtnRadius: 20, topBtnGap: 50
            } : {
                // Reorganized (26/08/2026) after the mobile layout was found broken, not just
                // unpolished: the level/phase indicator text was clipped by the Expandir
                // button (see progressIndicatorFontSize/X below), Coin/Star were raw emoji
                // text nearly unreadable against the sky background, and nothing visually
                // grouped the boss's own HUD cluster from the player's. Prototyped first as an
                // interactive artifact (sliders for cluster height/chip gaps, a live readout of
                // the purchase badge's real touch-pixel size at several embed widths) before
                // committing to these numbers. Deliberately simpler than the
                // desktop redesign: a plain translucent rounded panel per cluster (see
                // ui.js::drawSimplePanel()), not the panel_stone.png/scroll_banner.png artwork
                // — those are sized for a wide 16:9 stage band this 9:16 column doesn't have,
                // and every extra px of vertical chrome here is a px stolen from the board.
                w: 540, h: 960, aspect: '9/16', maxW: '540px',
                hasCharacterStage: false,
                bgX: 270, bgY: 480, bgW: 540, bgH: 960,

                // Top bar: the icon-button row (Música/Efeitos/Expandir, see the desktop L's
                // own topBtnY/Radius/Gap comment) stays on row 1. The level/phase indicator
                // gets its own row 2 below instead of squeezing into row 1 alongside the
                // buttons — even now that they're compact circles instead of the old wide
                // bracket-text boxes, the full pt_BR progress string still doesn't fit at any
                // readable size next to them. Everything below shifted down 40px to fit this
                // second row; the column still closes with ~90px of slack at the very bottom
                // (verify after any further change here).
                progressIndicatorY: 58,
                topBtnY: 24, topBtnRadius: 18, topBtnGap: 44,

                // Boss cluster: sprite, HP bar (+Status badges hanging off its own corner),
                // Coin/Star (no Potion — boss has no shop; also fixes a pre-existing gap where
                // the boss's own coin count was never shown on mobile at all, unlike Star),
                // then Grimório/Escudo/Orbe (no purchase badges, same reason). panelTop/Bottom
                // bound the translucent backing rect drawn behind this whole cluster.
                panelTopBoss: 92, panelBottomBoss: 254,
                bossX: 270, bossY: 115, bossScale: 90,
                hpBarW: 300, hpBarH: 22, hpBarRadius: 11,
                bossUiX: 120, bossHpY: 144, bossTxtY: 155,
                bossGoldX: 200, bossGoldY: 185, bossStarX: 340, bossStarY: 185,
                bossRingY: 224, bossGrimoireX: 170, bossShieldRingX: 270, bossOrbX: 370,

                // Board: fixed 440x440 (board.js hardcodes pieceSize 55 regardless of layout),
                // centered horizontally ((540-440)/2 = 50), sitting directly below the boss
                // cluster with an 8px gap — boardOffY is the piece-center reference board.js
                // expects (panel top-left 262 + half a cell 27.5), same convention as the
                // desktop boardOffX/Y comment above.
                boardOffX: 77.5, boardOffY: 289.5,

                // Player cluster: same structure as the boss's, plus Potion in the resource row
                // and purchase badges on Potion/Escudo/Grimório, all absent from the boss's
                // copy since the boss has no shop.
                panelTopPlayer: 710, panelBottomPlayer: 828,
                // Player sprite sits beside the bar/resource/ring stack, in the panel's own
                // left margin (16 to playerUiX's 120, a 104px gap) — not centered above like
                // the boss's, per the user's own suggestion (27/08/2026): the boss can afford
                // that because its panel is taller (162px vs this one's 118), and matching that
                // approach here would have meant growing this panel too, cascading into
                // re-tuning the board position and every row below it. 76px keeps a ~14px
                // margin on both sides of the gap; centered on the vertical midpoint of the
                // whole HP-bar-to-ring-row content block (~769), verified live to clear both
                // the panel's own top/bottom edges and its rounded corners.
                playerX: 68, playerY: 769, playerScale: 76,
                playerUiX: 120, playerHpY: 718, playerTxtY: 729,
                goldX: 160, goldY: 759, starX: 270, starY: 759,
                potionX: 380, potionY: 759,
                playerRingY: 798, playerGrimoireX: 170, playerShieldRingX: 270, playerOrbX: 370,

                // History: one button (not two always-visible 5-line blocks like desktop —
                // there's no room) opening a modal with Você/Chefe tabs, see
                // ui.js::showHistoryModalMobile().
                historyBtnY: 838,

                resourceIconSize: 32,
                ringRadius: 16, ringThickness: 4, ringIconSize: 22,

                // Purchase badge scale for ui.js::createPurchaseBadge() — found live
                // (27/08/2026) that the desktop's default 46x34 badge is bigger than mobile's
                // own 32px-diameter ring, swallowing its colored arc entirely. 0.72 is the
                // smallest scale that still clears the WCAG 24x24 real-px floor at mobile's
                // true 1:1 CSS scale (34 * 0.72 ≈ 24.5), leaving the ring's own icon/arc
                // visible next to it. See createPurchaseBadge()'s own docblock for the full
                // scale math (desktop vs mobile canvas CSS shrink).
                badgeScale: 0.72
            };

            const containerDOM = document.getElementById('playerpuzzle-canvas-container');
            containerDOM.querySelectorAll('p').forEach(el => el.remove());
            // Applied regardless of device (27/08/2026): mobile no longer gets a separate
            // full-viewport embedded layout, so a phone rotated to landscape gets the same
            // capped 16:9 desktop treatment a wide browser window would.
            containerDOM.classList.toggle('pp-canvas-desktop', isDesk);
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
                // False (28/08/2026): with capture:true, Phaser's own input manager calls
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
                if (window.innerWidth > window.innerHeight) {
                    container.classList.add('pp-canvas-desktop');
                }

                const strKeys = [
                    'advancingphase',
                    'bossansweredcorrect', 'bossansweredwrong', 'bosscorrectfeedback',
                    'bosslostmultiplier', 'bosstrigger', 'bosswrongfeedback', 'btnattack',
                    'btncontinue', 'btnexitgame', 'btnplayagain',
                    'coinscollected', 'defeat',
                    'difficulty_easy', 'difficulty_hard', 'difficulty_normal',
                    'historylogattack', 'historylogcoins', 'historylogcritical', 'historylogempty',
                    'historylogheal', 'historylogmana', 'historylogmultiplier',
                    'historylogmultiplierlost', 'historylogpoisoncharge', 'historylogpoisontick',
                    'historylogshieldblock', 'historylogshieldcharge', 'historylogtitle',
                    'historylogwronganswer',
                    'hpboss', 'hpyou', 'iconeffects', 'loading', 'maxmultiplier',
                    'musicoff', 'musicon', 'nextlevel', 'nextphase', 'noanswers',
                    'phaseadvanceerror', 'phasecompletetitle', 'phasedifficulty', 'playercorrect',
                    'playerlostmultiplier', 'playerwrong', 'progressindicator', 'progresssaved',
                    'questionerror', 'requirejserror', 'saveerror', 'savingprogress',
                    'shuffling', 'victory'
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
