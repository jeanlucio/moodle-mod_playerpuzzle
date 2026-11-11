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
 * The easing curves the game's animations use, by the names the animation code asks for. Each one maps a progress
 * from 0 to 1 to an eased progress from 0 to 1.
 *
 * UMD-wrapped like the engine modules, so the same file loads as an AMD module in the browser and as a plain
 * CommonJS module in Node, where the tests run it without a browser.
 *
 * @module     mod_playerpuzzle/stage/easing
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
((define) => {
    define([], () => {
        'use strict';

        const bounceOut = v => {
            if (v < 1 / 2.75) {
                return 7.5625 * v * v;
            }
            if (v < 2 / 2.75) {
                const w = v - (1.5 / 2.75);
                return (7.5625 * w * w) + 0.75;
            }
            if (v < 2.5 / 2.75) {
                const w = v - (2.25 / 2.75);
                return (7.5625 * w * w) + 0.9375;
            }
            const w = v - (2.625 / 2.75);
            return (7.5625 * w * w) + 0.984375;
        };

        const curves = {
            'Linear': v => v,
            'Quad.easeIn': v => v * v,
            'Quad.easeOut': v => v * (2 - v),
            'Quad.easeInOut': v => (v < 0.5 ? 2 * v * v : -1 + ((4 - (2 * v)) * v)),
            'Cubic.easeIn': v => v * v * v,
            'Cubic.easeOut': v => ((v - 1) * (v - 1) * (v - 1)) + 1,
            'Sine.easeInOut': v => 0.5 * (1 - Math.cos(Math.PI * v)),
            'Bounce.easeOut': bounceOut,
        };

        /**
         * The curve with a given name; a tween with no ease is linear.
         *
         * @param {string|undefined} name Name such as 'Quad.easeOut'.
         * @return {Function} The curve.
         * @throws {Error} When the name is not one of the curves.
         */
        function get(name) {
            if (name === undefined || name === null || name === 'Power0') {
                return curves.Linear;
            }
            if (!Object.prototype.hasOwnProperty.call(curves, name)) {
                throw new Error(`Unknown easing: ${name}`);
            }
            return curves[name];
        }

        return {get, names: Object.keys(curves)};
    });
})(typeof define === 'function' && define.amd ? define : (deps, factory) => {
    module.exports = factory(...deps.map(dep => require(`./${dep.split('/').pop()}`)));
});
