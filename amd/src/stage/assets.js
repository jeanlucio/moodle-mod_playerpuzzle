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
 * Loads the images of the game before it starts, one at a time reporting progress, so the loading bar fills as
 * each file arrives and the boot can tell a slow connection (files keep coming) from a stalled one (nothing does).
 *
 * UMD-wrapped, with the loader replaceable, so the order of the progress reports and the failure of one file can be
 * tested in Node.
 *
 * @module     mod_playerpuzzle/stage/assets
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
((define) => {
    define([], () => {
        'use strict';

        /**
         * Loads and decodes one image in the browser.
         *
         * @param {string} url Where it is.
         * @return {Promise<HTMLImageElement>} The decoded image.
         */
        const loadImage = url => new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error(`Could not load ${url}`));
            image.src = url;
        });

        /**
         * Loads every image of a list.
         *
         * @param {Array<{key: string, url: string}>} list The files.
         * @param {object} [options]
         * @param {Function} [options.onProgress] Called with (loaded, total, key) after each file arrives.
         * @param {Function} [options.load] Replaces the browser loader, for tests: (url) => Promise.
         * @return {Promise<Object<string, HTMLImageElement>>} The images by key; rejects on the first file that fails.
         */
        async function preloadImages(list, options = {}) {
            const load = options.load || loadImage;
            const images = {};
            let loaded = 0;
            await Promise.all(list.map(async item => {
                images[item.key] = await load(item.url);
                loaded++;
                if (options.onProgress) {
                    options.onProgress(loaded, list.length, item.key);
                }
            }));
            return images;
        }

        return {preloadImages};
    });
})(typeof define === 'function' && define.amd ? define : (deps, factory) => {
    module.exports = factory(...deps.map(dep => require(`./${dep.split('/').pop()}`)));
});
