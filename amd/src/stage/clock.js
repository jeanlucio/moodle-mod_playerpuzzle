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
 * The game's clock: delayed calls and repeating timers, advanced only by update(), never by the wall clock, so the
 * game can be paused and a test can step it by exact amounts.
 *
 * An event accumulates the time it is handed, from the first update after it was created, and fires on the first
 * update where the total reaches its delay, in the order the events were created. An event created while the clock
 * is firing starts counting on the next update. A repeating event keeps the time left over after it fires, so it
 * does not drift.
 *
 * @module     mod_playerpuzzle/stage/clock
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
((define) => {
    define([], () => {
        'use strict';

        /**
         * A timer the clock holds. Created by Clock.addEvent() and Clock.delayedCall().
         */
        class TimerEvent {
            /**
             * @param {object} config See Clock.addEvent().
             */
            constructor(config) {
                this.delay = config.delay || 0;
                this.callback = config.callback;
                this.callbackScope = config.callbackScope;
                this.args = config.args || [];
                this.loop = Boolean(config.loop);
                this.elapsed = 0;
                this.removed = false;
            }

            /**
             * Cancels the event; a callback not yet fired never fires.
             *
             * @param {boolean} [dispatch] Whether to fire the callback one last time. Defaults to false.
             */
            remove(dispatch = false) {
                if (this.removed) {
                    return;
                }
                this.removed = true;
                if (dispatch && this.callback) {
                    this.callback.apply(this.callbackScope, this.args);
                }
            }
        }

        class Clock {
            constructor() {
                this.now = 0;
                this.events = [];
            }

            /**
             * Adds a timer.
             *
             * @param {object} config
             * @param {number} config.delay Milliseconds until it fires.
             * @param {Function} config.callback What to call.
             * @param {object} [config.callbackScope] The `this` of the call.
             * @param {Array} [config.args] Arguments of the call.
             * @param {boolean} [config.loop] Whether it fires again every delay.
             * @return {TimerEvent} The event.
             */
            addEvent(config) {
                const event = new TimerEvent(config);
                this.events.push(event);
                return event;
            }

            /**
             * Calls a function once, after a delay.
             *
             * @param {number} delay Milliseconds.
             * @param {Function} callback What to call.
             * @param {Array} [args] Arguments of the call.
             * @param {object} [callbackScope] The `this` of the call.
             * @return {TimerEvent} The event, to remove it before it fires.
             */
            delayedCall(delay, callback, args, callbackScope) {
                return this.addEvent({delay, callback, args, callbackScope});
            }

            /**
             * Removes every timer.
             */
            removeAllEvents() {
                this.events.forEach(event => {
                    event.removed = true;
                });
                this.events = [];
            }

            /**
             * Moves the clock forward and fires what is due.
             *
             * @param {number} dt Milliseconds since the last update.
             */
            update(dt) {
                this.now += dt;
                const due = this.events.slice();
                due.forEach(event => {
                    if (event.removed) {
                        return;
                    }
                    event.elapsed += dt;
                    if (event.elapsed < event.delay) {
                        return;
                    }
                    const remainder = event.elapsed - event.delay;
                    if (event.loop) {
                        event.elapsed = remainder;
                    } else {
                        event.removed = true;
                    }
                    if (event.callback) {
                        event.callback.apply(event.callbackScope, event.args);
                    }
                });
                this.events = this.events.filter(event => !event.removed);
            }
        }

        return {Clock, TimerEvent};
    });
})(typeof define === 'function' && define.amd ? define : (deps, factory) => {
    module.exports = factory(...deps.map(dep => require(`./${dep.split('/').pop()}`)));
});
