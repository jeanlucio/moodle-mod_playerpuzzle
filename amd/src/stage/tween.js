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
 * Tweens: a number on an object moving to another number over time, with a curve, a delay, and optionally going
 * back (yoyo) and repeating. A tween changes the properties of its target object and nothing else; whatever draws
 * the object reads them.
 *
 * What a tween does, step by step:
 * - A tween created outside an update starts counting on the update after the next one; one created inside an
 *   update (an onComplete that starts another tween) starts counting on the update after that.
 * - Its start values are read when it starts counting.
 * - Time is the sum of the updates it received, the delay included. The first update past the delay moves the
 *   values by the time past it.
 * - Where a leg of a yoyo or a repeat ends, the values go to that leg's exact end on that update, and the time
 *   past it is used from the next update on.
 * - When the last leg ends the values are set to their final numbers, onComplete is called and the tween goes.
 * - stop() leaves the values where they are and never calls onComplete.
 *
 * @module     mod_playerpuzzle/stage/tween
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
((define) => {
    define(['mod_playerpuzzle/stage/easing'], (Easing) => {
        'use strict';

        // Frame times are not exact in floating point (twelve frames of 1/60 s add up to a hair under 200 ms), so a
        // leg whose end is within this many milliseconds counts as ended.
        const SLACK_MS = 1e-6;

        const RESERVED = ['targets', 'duration', 'delay', 'ease', 'yoyo', 'repeat', 'onUpdate', 'onComplete'];

        class Tween {
            /**
             * @param {object} config See TweenManager.add().
             */
            constructor(config) {
                this.targets = Array.isArray(config.targets) ? config.targets : [config.targets];
                this.duration = config.duration;
                this.delay = config.delay || 0;
                this.ease = Easing.get(config.ease);
                this.yoyo = Boolean(config.yoyo);
                this.repeat = config.repeat === undefined ? 0 : config.repeat;
                this.onUpdate = config.onUpdate;
                this.onComplete = config.onComplete;
                this.ends = {};
                Object.keys(config).filter(key => !RESERVED.includes(key)).forEach(key => {
                    this.ends[key] = config[key];
                });
                this.starts = [];
                this.elapsed = 0;
                this.leg = 0;
                this.state = 'pending';
            }

            /**
             * Whether it never ends by itself.
             *
             * @return {boolean}
             */
            isInfinite() {
                return this.repeat < 0;
            }

            /**
             * How many legs it runs: one, or two with a yoyo, for each pass.
             *
             * @return {number}
             */
            legCount() {
                return (this.repeat + 1) * (this.yoyo ? 2 : 1);
            }

            /**
             * Stops it where it is, without calling onComplete.
             */
            stop() {
                this.state = 'stopped';
            }

            /**
             * Whether it is still waiting to start or running.
             *
             * @return {boolean}
             */
            isActive() {
                return this.state === 'pending' || this.state === 'running';
            }

            /**
             * Reads the start values; the tween counts time from here on.
             */
            start() {
                this.starts = this.targets.map(target => {
                    const values = {};
                    Object.keys(this.ends).forEach(key => {
                        values[key] = target[key];
                    });
                    return values;
                });
                this.state = 'running';
            }

            /**
             * Puts every property at start + (end - start) * progress.
             *
             * @param {number} progress Eased progress, 0 is the start values and 1 the end values.
             */
            apply(progress) {
                this.targets.forEach((target, i) => {
                    Object.keys(this.ends).forEach(key => {
                        const from = this.starts[i][key];
                        target[key] = from + ((this.ends[key] - from) * progress);
                    });
                });
            }

            /**
             * Gives it time.
             *
             * @param {number} dt Milliseconds.
             * @return {boolean} Whether it finished on this update.
             */
            advance(dt) {
                this.elapsed += dt;
                const local = this.elapsed - this.delay + SLACK_MS;
                if (local < 0) {
                    return false;
                }
                const duration = this.duration;
                const legs = this.legCount();
                const leg = duration > 0 ? Math.floor(local / duration) : legs;
                if (!this.isInfinite() && leg >= legs) {
                    this.apply(this.yoyo && legs % 2 === 0 ? 0 : this.ease(1));
                    this.finishUpdate();
                    this.state = 'done';
                    if (this.onComplete) {
                        this.onComplete(this, this.targets);
                    }
                    return true;
                }
                if (leg > this.leg) {
                    // A leg ended on this update: its exact end value, and the time past it from the next update on.
                    const endedForward = !this.yoyo || this.leg % 2 === 0;
                    this.apply(endedForward ? this.ease(1) : 0);
                    this.leg = leg;
                } else {
                    const part = (local - (leg * duration)) / duration;
                    const forward = !this.yoyo || leg % 2 === 0;
                    this.apply(this.ease(forward ? part : 1 - part));
                }
                this.finishUpdate();
                return false;
            }

            /**
             * Tells the onUpdate callback that the values changed.
             */
            finishUpdate() {
                if (this.onUpdate) {
                    this.onUpdate(this, this.targets);
                }
            }
        }

        class TweenManager {
            constructor() {
                this.running = [];
                this.pending = [];
            }

            /**
             * Starts a tween.
             *
             * @param {object} config
             * @param {object|object[]} config.targets The object or objects whose properties move.
             * @param {number} config.duration Milliseconds of one leg.
             * @param {number} [config.delay] Milliseconds to wait before it moves, once.
             * @param {string} [config.ease] Name of the curve, linear when left out.
             * @param {boolean} [config.yoyo] Whether each pass is followed by the same pass backwards.
             * @param {number} [config.repeat] Extra passes after the first; -1 never ends.
             * @param {Function} [config.onUpdate] Called with (tween, targets) after the values change.
             * @param {Function} [config.onComplete] Called with (tween, targets) when it ends.
             * Every other key is a property of the target and its value is the number it moves to.
             * @return {Tween} The tween.
             */
            add(config) {
                const tween = new Tween(config);
                this.pending.push(tween);
                return tween;
            }

            /**
             * Every tween that has not ended, the ones about to start included.
             *
             * @return {Tween[]}
             */
            getTweens() {
                return this.running.concat(this.pending).filter(tween => tween.isActive());
            }

            /**
             * Stops every tween that moves any of the given objects.
             *
             * @param {object|object[]} targets An object or a list of them.
             */
            killTweensOf(targets) {
                const list = Array.isArray(targets) ? targets : [targets];
                this.getTweens().forEach(tween => {
                    if (tween.targets.some(target => list.includes(target))) {
                        tween.stop();
                    }
                });
            }

            /**
             * Stops everything.
             */
            killAll() {
                this.getTweens().forEach(tween => tween.stop());
            }

            /**
             * Moves every running tween forward, then lets the ones created meanwhile start counting.
             *
             * @param {number} dt Milliseconds since the last update.
             */
            update(dt) {
                this.running.slice().forEach(tween => {
                    if (tween.state === 'running') {
                        tween.advance(dt);
                    }
                });
                const starting = this.pending;
                this.pending = [];
                starting.forEach(tween => {
                    if (tween.state === 'pending') {
                        tween.start();
                    }
                });
                this.running = this.running.concat(starting).filter(tween => tween.state === 'running');
            }
        }

        return {Tween, TweenManager};
    });
})(typeof define === 'function' && define.amd ? define : (deps, factory) => {
    module.exports = factory(...deps.map(dep => require(`./${dep.split('/').pop()}`)));
});
