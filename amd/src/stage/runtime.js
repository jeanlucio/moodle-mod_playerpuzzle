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
 * The runtime of the game's stage: one clock and one tween manager moved by the same updates, a pause that freezes
 * both, and a loop driven by the browser's animation frames.
 *
 * Time is the sum of the updates received and nothing else. step(ms) is the manual way to move it, in frames of
 * 1/60 s, which is what tests and the visual baseline use; start() moves it by the real time between animation
 * frames. Both go through update().
 *
 * @module     mod_playerpuzzle/stage/runtime
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
((define) => {
    define(['mod_playerpuzzle/stage/clock', 'mod_playerpuzzle/stage/tween'], (ClockModule, TweenModule) => {
        'use strict';

        const FRAME_MS = 1000 / 60;

        // A frame that took longer than this (a hidden tab, a debugger) counts as this much, so nothing jumps.
        const LONGEST_FRAME_MS = 100;

        class Runtime {
            /**
             * @param {object} [env] What the loop needs from the browser, replaceable in a test.
             * @param {Function} [env.requestFrame] requestAnimationFrame.
             * @param {Function} [env.cancelFrame] cancelAnimationFrame.
             */
            constructor(env = {}) {
                this.time = new ClockModule.Clock();
                this.tweens = new TweenModule.TweenManager();
                this.paused = false;
                this.requestFrame = env.requestFrame;
                this.cancelFrame = env.cancelFrame;
                this.frameHandle = null;
                this.lastStamp = null;
            }

            /**
             * Moves the clock and the tweens forward, unless paused.
             *
             * @param {number} dt Milliseconds.
             */
            update(dt) {
                if (this.paused) {
                    return;
                }
                this.time.update(dt);
                this.tweens.update(dt);
            }

            /**
             * Moves time forward by hand, in frames of 1/60 s and a last shorter one.
             *
             * @param {number} ms Milliseconds.
             */
            step(ms) {
                let left = ms;
                while (left > 0) {
                    const dt = Math.min(FRAME_MS, left);
                    this.update(dt);
                    left -= dt;
                }
            }

            /**
             * Freezes the clock and the tweens; nothing moves and nothing fires until resume().
             */
            pause() {
                this.paused = true;
            }

            /**
             * Lets time run again, from where it stopped: the time spent paused is not counted.
             */
            resume() {
                this.paused = false;
            }

            /**
             * Starts moving time by the real time between animation frames.
             */
            start() {
                if (this.frameHandle !== null || !this.requestFrame) {
                    return;
                }
                this.lastStamp = null;
                const tick = stamp => {
                    if (this.lastStamp !== null) {
                        this.update(Math.min(stamp - this.lastStamp, LONGEST_FRAME_MS));
                    }
                    this.lastStamp = stamp;
                    this.frameHandle = this.requestFrame(tick);
                };
                this.frameHandle = this.requestFrame(tick);
            }

            /**
             * Stops the loop; time stays where it is.
             */
            stop() {
                if (this.frameHandle !== null && this.cancelFrame) {
                    this.cancelFrame(this.frameHandle);
                }
                this.frameHandle = null;
            }
        }

        return {Runtime, FRAME_MS};
    });
})(typeof define === 'function' && define.amd ? define : (deps, factory) => {
    module.exports = factory(...deps.map(dep => require(`./${dep.split('/').pop()}`)));
});
