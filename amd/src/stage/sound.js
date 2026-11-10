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
 * The game's sound: three short effects decoded in memory and the background music streamed, each with its own
 * volume, both switchable, and the unlocking browsers ask for before they let a page play anything.
 *
 * Browsers keep a page silent until the person has touched it, and not every touch counts: on Android a finger that has
 * only gone down does not, one that has gone up does. The effects wait in a suspended audio context and the music asks
 * to play and is refused, so on every touch, click or key the sound tries again, and stops listening only once the
 * context is really running. The music file is only fetched when the music is first asked for, so a student who keeps
 * it off never downloads it.
 *
 * UMD-wrapped, with every browser object handed in, so the logic runs in Node tests with fakes.
 *
 * @module     mod_playerpuzzle/stage/sound
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
((define) => {
    define([], () => {
        'use strict';

        // How loud each effect and the music play when on.
        const EFFECT_VOLUME = {swap: 0.6, match: 0.5, hit: 0.65};
        const MUSIC_VOLUME = 0.3;

        /**
         * Creates the sound of one game.
         *
         * @param {object} env What the sound needs from the browser.
         * @param {Function} env.createContext Returns a Web Audio context.
         * @param {Function} env.fetchBuffer (url) => Promise of the file's bytes.
         * @param {Function} env.createMusic (url) => an object with play() returning a promise, pause(), volume and loop.
         * @param {Function} env.onGesture (callback) => calls it on every touch, click or key until it answers true.
         * @param {object} [options]
         * @param {boolean} [options.effectsOn] Whether the effects start on.
         * @param {boolean} [options.musicOn] Whether the music starts on.
         * @return {object} The sound.
         */
        function create(env, options = {}) {
            let context = null;
            const buffers = {};
            const urls = {};
            let effectsOn = options.effectsOn !== false;
            let musicOn = options.musicOn !== false;
            let music = null;
            let musicUrl = null;
            let musicWanted = false;

            const getContext = () => {
                if (context === null) {
                    context = env.createContext();
                }
                return context;
            };

            const tryMusic = async() => {
                if (!musicOn || !musicWanted || musicUrl === null) {
                    return;
                }
                if (music === null) {
                    music = env.createMusic(musicUrl);
                    music.loop = true;
                    music.volume = MUSIC_VOLUME;
                }
                try {
                    await music.play();
                } catch (error) {
                    // Refused until the first gesture; unlock() tries again.
                    return;
                }
            };

            const isRunning = () => context !== null && context.state === 'running';

            // Both are started before either is awaited: some browsers only let the music play from the very turn of
            // the gesture's own call.
            const resumeContext = async() => {
                if (context === null || context.state !== 'suspended') {
                    return;
                }
                try {
                    await context.resume();
                } catch (error) {
                    // Still suspended: the next gesture tries again.
                }
            };
            const unlock = async() => {
                await Promise.all([resumeContext(), tryMusic()]);
                return context === null || isRunning();
            };
            env.onGesture(unlock);

            return {
                /**
                 * Fetches and decodes the effects.
                 *
                 * @param {Object<string, string>} files The URL of each effect by name.
                 * @param {Function} [onLoaded] Called with the name of each effect that finishes.
                 * @return {Promise<void>}
                 */
                async loadEffects(files, onLoaded) {
                    const ctx = getContext();
                    await Promise.all(Object.keys(files).map(async name => {
                        urls[name] = files[name];
                        const bytes = await env.fetchBuffer(files[name]);
                        buffers[name] = await ctx.decodeAudioData(bytes);
                        if (onLoaded) {
                            onLoaded(name);
                        }
                    }));
                },

                /**
                 * Plays an effect, unless effects are off or the browser has not been unlocked yet.
                 *
                 * @param {string} name 'swap', 'match' or 'hit'.
                 */
                playEffect(name) {
                    if (!effectsOn || !isRunning() || !buffers[name]) {
                        return;
                    }
                    const source = context.createBufferSource();
                    const gain = context.createGain();
                    gain.gain.value = EFFECT_VOLUME[name];
                    source.buffer = buffers[name];
                    source.connect(gain);
                    gain.connect(context.destination);
                    source.start(0);
                },

                /**
                 * Turns the effects on or off.
                 *
                 * @param {boolean} on Whether they play.
                 */
                setEffects(on) {
                    effectsOn = Boolean(on);
                },

                /**
                 * Tells the sound where the music is, without fetching it.
                 *
                 * @param {string} url The music file.
                 */
                setMusicUrl(url) {
                    musicUrl = url;
                },

                /**
                 * Turns the music on or off; on starts it (fetching the file the first time), off pauses it.
                 *
                 * @param {boolean} on Whether it plays.
                 * @return {Promise<void>}
                 */
                async setMusic(on) {
                    musicOn = Boolean(on);
                    musicWanted = musicOn;
                    if (musicOn) {
                        await tryMusic();
                    } else if (music !== null) {
                        music.pause();
                    }
                },

                /**
                 * Starts the music if it is on, as at the start of the game.
                 *
                 * @return {Promise<void>}
                 */
                async startMusic() {
                    musicWanted = true;
                    await tryMusic();
                },

                /**
                 * Whether a gesture has unlocked the sound.
                 *
                 * @return {boolean}
                 */
                isUnlocked() {
                    return isRunning();
                },

                EFFECT_VOLUME,
                MUSIC_VOLUME,
            };
        }

        /**
         * The browser objects a game's sound runs on.
         *
         * @return {object} What create() asks for, made of the page's own audio, fetch and events.
         */
        function browserEnv() {
            // It is pointerup, not pointerdown: a finger that has only gone down is not an activation to the browser.
            const gestures = ['pointerup', 'touchend', 'click', 'keydown'];
            return {
                createContext: () => new (window.AudioContext || window.webkitAudioContext)(),
                fetchBuffer: async url => (await fetch(url)).arrayBuffer(),
                createMusic: url => new Audio(url),
                onGesture: callback => {
                    const listener = async() => {
                        if (await callback()) {
                            gestures.forEach(name => document.removeEventListener(name, listener, true));
                        }
                    };
                    gestures.forEach(name => document.addEventListener(name, listener, true));
                },
            };
        }

        return {create, browserEnv, EFFECT_VOLUME, MUSIC_VOLUME};
    });
})(typeof define === 'function' && define.amd ? define : (deps, factory) => {
    module.exports = factory(...deps.map(dep => require(`./${dep.split('/').pop()}`)));
});
