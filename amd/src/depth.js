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
 * Drawing depths shared by the game scene's modules.
 *
 * WebGL draws shapes (Graphics, Arc, Rectangle) and textured quads (Image, Text) through
 * different batches, and every switch between the two in the drawing order ends a batch. Some
 * mobile GPUs pay so much per switch that a scene alternating shape and icon dozens of times
 * per frame falls from 60 to about 10 frames per second, while drawing the same objects
 * grouped holds 60. So the persistent scene is drawn in three bands, each with its shapes
 * first and its images and text after them: a shape that must sit on top of an icon goes to
 * the next band's shapes, never between icons of its own band.
 *
 * Transient overlays (balloons, modals, the shuffle notice) keep their own higher depths:
 * they add a switch or two only while they are on screen.
 *
 * @module     mod_playerpuzzle/depth
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], () => {
    'use strict';

    return {
        // Backdrop colour and the mobile panels, under the stage art.
        BACK_SHAPES: -1,
        // Stage art, characters, panel art and the icons that sit on the panels.
        BACK_IMAGES: 0,
        // Board grid, HP bars, ring plates and arcs, status badge plates.
        MIDDLE_SHAPES: 1,
        // Board pieces, HP text, ring and status badge icons.
        MIDDLE_IMAGES: 2,
        // Purchase badges, status badge counters, top-row buttons.
        FRONT_SHAPES: 5,
        // Their labels and icons.
        FRONT_IMAGES: 6,
    };
});
