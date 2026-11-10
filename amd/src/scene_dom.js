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
 * Builds the game on the stage drawn with elements: the images are loaded with a progress bar, the stage and the
 * HUD are made, and the combat and the board are started. The scene
 * object it makes answers to the names combat.js and board.js use (time, tweens, input, scene, the sounds) with the
 * stage runtime behind them.
 *
 * Loading waits for the images (the sounds are loaded alongside and the game goes on without them if they fail) and
 * gives up with the boot error when nothing at all has arrived for twenty seconds.
 *
 * @module     mod_playerpuzzle/scene_dom
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'mod_playerpuzzle/layout',
    'mod_playerpuzzle/hud',
    'mod_playerpuzzle/combat',
    'mod_playerpuzzle/board',
    'mod_playerpuzzle/stage/runtime',
    'mod_playerpuzzle/stage/stage',
    'mod_playerpuzzle/stage/assets',
    'mod_playerpuzzle/stage/sound',
], (Layout, Hud, CombatHandler, BoardHandler, RuntimeModule, StageModule, Assets, SoundModule) => {
    'use strict';

    // How long the game waits for something, anything, to finish loading before it gives up.
    const BOOT_STALL_MS = 20000;

    /**
     * What the game says and does with a sound effect, by the name the stage's sound knows it by. Setting a volume
     * is, to this game, only ever on or off.
     *
     * @param {object} sound The stage's sound.
     * @param {string} name 'swap', 'match' or 'hit'.
     * @return {{play: Function, setVolume: Function}}
     */
    const effect = (sound, name) => ({
        play() {
            sound.playEffect(name);
        },
        setVolume(volume) {
            sound.setEffects(volume > 0);
        },
    });

    /**
     * The images the game draws, by key.
     *
     * @param {object} config The game's configuration.
     * @param {boolean} isDesk Whether the screen is the horizontal one.
     * @return {Object<string, string>}
     */
    const imageUrls = (config, isDesk) => {
        const urls = {stagebg: config.stagebgurl, player: config.playerurl, boss: config.bossurl};
        if (isDesk) {
            urls.panelstone = `${M.cfg.wwwroot}/mod/playerpuzzle/pix/panel_frame.webp`;
            urls.scrollbanner = `${M.cfg.wwwroot}/mod/playerpuzzle/pix/scroll_banner.webp`;
        }
        for (let i = 0; i < 7; i++) {
            urls[`item${i}`] = config.spriteurls[i];
        }
        return urls;
    };

    /**
     * Fills the loading bar.
     *
     * @param {number} loaded Files loaded.
     * @param {number} total Files to load.
     */
    const showProgress = (loaded, total) => {
        const bar = document.getElementById('pp-progress-bar');
        if (!bar) {
            return;
        }
        const percent = Math.round((loaded / total) * 100);
        bar.style.setProperty('--pp-bar-width', `${percent}%`);
        bar.setAttribute('aria-valuenow', percent);
        bar.textContent = `${percent}%`;
    };

    /**
     * Loads the images and the sound effects, filling the bar as each file arrives. A boot that stalls (not one file
     * arriving for twenty seconds, however slow the connection, since each file starts the count again) is an error,
     * and so is an image that fails; a sound that fails is only a game without it.
     *
     * @param {object} urls The images by key.
     * @param {object} sound The stage's sound.
     * @return {Promise<void>}
     */
    const load = async(urls, sound) => {
        const effects = {
            swap: `${M.cfg.wwwroot}/mod/playerpuzzle/pix/swap.mp3`,
            match: `${M.cfg.wwwroot}/mod/playerpuzzle/pix/match.mp3`,
            hit: `${M.cfg.wwwroot}/mod/playerpuzzle/pix/hit.mp3`,
        };
        const total = Object.keys(urls).length + Object.keys(effects).length;
        let loaded = 0;
        let timer = null;
        let giveUp = null;
        const stalled = new Promise((resolve, reject) => {
            giveUp = reject;
        });
        const arm = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(
                () => giveUp(new Error('PlayerPuzzle boot stalled: no loading progress for 20s.')),
                BOOT_STALL_MS
            );
        };
        const arrived = () => {
            loaded++;
            showProgress(loaded, total);
            arm();
        };
        arm();
        try {
            await Promise.race([
                Promise.all([
                    Assets.preloadImages(Object.keys(urls).map(key => ({key, url: urls[key]})), {onProgress: arrived}),
                    sound.loadEffects(effects, arrived).catch(error => window.console.warn('PlayerPuzzle sounds failed:', error)),
                ]),
                stalled,
            ]);
        } finally {
            window.clearTimeout(timer);
        }
    };

    /**
     * Starts the game.
     *
     * @param {object} gameConfig The game's configuration.
     * @param {object} strings The strings.
     * @return {Promise<object>} The scene.
     */
    const start = async(gameConfig, strings) => {
        const isDesk = window.innerWidth > window.innerHeight;
        const L = Layout.build(isDesk);
        const container = document.getElementById('playerpuzzle-canvas-container');

        const sound = SoundModule.create(SoundModule.browserEnv(), {
            effectsOn: gameConfig.sfxenabled !== false,
            musicOn: gameConfig.musicenabled !== false,
        });
        sound.setMusicUrl(`${M.cfg.wwwroot}/mod/playerpuzzle/pix/music.mp3`);
        const urls = imageUrls(gameConfig, isDesk);
        await load(urls, sound);

        container.querySelectorAll('p').forEach(el => el.remove());
        container.classList.toggle('pp-canvas-desktop', isDesk);
        container.appendChild(document.getElementById('playerpuzzle-modal'));
        ['playerpuzzle-opponent-help', 'playerpuzzle-giveup'].forEach(id => {
            const dialog = document.getElementById(id);
            if (dialog) {
                container.appendChild(dialog);
            }
        });
        document.getElementById('pp-bootstrap-loader')?.remove();

        const runtime = new RuntimeModule.Runtime({
            requestFrame: callback => window.requestAnimationFrame(callback),
            cancelFrame: handle => window.cancelAnimationFrame(handle),
        });
        const stage = StageModule.create({container, width: L.w, height: L.h, runtime});

        const scene = {
            runtime,
            stage,
            L,
            time: runtime.time,
            tweens: runtime.tweens,
            input: {enabled: true},
            scene: {
                pause: () => runtime.pause(),
                resume: () => runtime.resume(),
            },
            imageUrl: key => urls[key],
            sound,
            sfxSwap: effect(sound, 'swap'),
            sfxMatch: effect(sound, 'match'),
            sfxHit: effect(sound, 'hit'),
            bgMusic: null,
            playMusic() {
                if (scene.musicOn) {
                    sound.setMusic(true);
                }
            },
            pauseMusic() {
                sound.setMusic(false);
            },
        };
        scene.ui = new Hud(scene, L, gameConfig, strings);
        scene.ui.setupStaticUI();
        // The music is fetched now only when it is on, in the background, and plays as soon as the browser lets it.
        scene.playMusic();
        scene.combat = new CombatHandler(scene, gameConfig, strings);
        scene.board = new BoardHandler(scene, L, strings);
        scene.ui.createOpponentItems(scene.combat.ruleConfig().bossKit);

        scene.combat.updateUI();
        scene.board.resumeFlow();

        // Demo match: the input stays off until the student has stepped through the walkthrough.
        if (gameConfig.isdemo) {
            scene.input.enabled = false;
            scene.ui.showDemoWalkthrough(() => {
                scene.input.enabled = true;
            });
        }
        runtime.start();
        return scene;
    };

    return {start};
});
