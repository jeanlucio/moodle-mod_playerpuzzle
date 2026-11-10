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
/**
 * Builds the game on the stage drawn with elements: the images are loaded with a progress bar, the stage and the
 * HUD are made, and the combat and the board are started with the same calls the canvas version makes. The scene
 * object it makes answers to the names combat.js and board.js use (time, tweens, input, scene, the sounds) with the
 * stage runtime behind them.
 *
 * The sounds are silent here for now: they come with their own part.
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
    'mod_playerpuzzle/board_view',
    'mod_playerpuzzle/stage/runtime',
    'mod_playerpuzzle/stage/stage',
    'mod_playerpuzzle/stage/assets',
], (Layout, Hud, CombatHandler, BoardHandler, BoardView, RuntimeModule, StageModule, Assets) => {
    'use strict';

    /**
     * A sound effect that plays nothing, with the calls the game makes on one.
     *
     * @return {{play: Function, setVolume: Function}}
     */
    const silentEffect = () => ({
        play() {
            // Silent for now.
        },
        setVolume() {
            // Silent for now.
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

        const urls = imageUrls(gameConfig, isDesk);
        await Assets.preloadImages(Object.keys(urls).map(key => ({key, url: urls[key]})), {onProgress: showProgress});

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
            sfxSwap: silentEffect(),
            sfxMatch: silentEffect(),
            sfxHit: silentEffect(),
            bgMusic: null,
            playMusic() {
                // Silent for now.
            },
            pauseMusic() {
                // Silent for now.
            },
        };
        scene.ui = new Hud(scene, L, gameConfig, strings);
        scene.ui.setupStaticUI();
        scene.combat = new CombatHandler(scene, gameConfig, strings);
        scene.board = new BoardHandler(scene, L, strings, BoardView);
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
