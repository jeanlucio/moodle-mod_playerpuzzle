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
 * A board that draws nothing: the same calls as the board views that draw, answered by doing nothing, with the swap
 * still taking the time it takes so the turn goes on in the same order. It stands in for the board while the stage
 * and the HUD are drawn without the canvas, and goes when the board is drawn with elements.
 *
 * @module     mod_playerpuzzle/board_view_blank
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], () => {
    'use strict';

    // How long a swap takes, as in the board views that draw it.
    const SWAP_MS = 200;

    class BoardViewBlank {
        /**
         * @param {object} scene The scene, for its clock.
         */
        constructor(scene) {
            this.scene = scene;
        }

        add() {
            // Nothing is drawn.
        }

        metrics() {
            return {x: 0, y: 0, size: 0};
        }

        tint() {
            // Nothing is drawn.
        }

        swap(first, second, done) {
            this.scene.time.delayedCall(SWAP_MS, done);
        }

        pop() {
            // Nothing is drawn.
        }

        fall() {
            // Nothing is drawn.
        }

        spawn() {
            // Nothing is drawn.
        }

        retype() {
            // Nothing is drawn.
        }

        fadeIn() {
            // Nothing is drawn.
        }

        startHint() {
            // Nothing is drawn.
        }

        stopHint() {
            // Nothing is drawn.
        }

        notice() {
            return {destroy() {
                // Nothing was drawn.
            }};
        }
    }

    return BoardViewBlank;
});
