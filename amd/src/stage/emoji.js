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
 * Finds the emoji and other pictographs in a text. A screen reader speaks them by name ("crossed swords"), which is
 * noise on a button or in an announcement, so the game removes them from what is announced and hides them from what is
 * shown (see mod_playerpuzzle/accessibility).
 *
 * UMD-wrapped like the engine modules, so the same file loads as an AMD module in the browser and as a plain
 * CommonJS module in Node, where the tests run it without a browser.
 *
 * @module     mod_playerpuzzle/stage/emoji
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global module */
((define) => {
    define([], () => {
        'use strict';

        // A pictograph with its variation selector or skin tone, and the ones joined to it (a family, a flag with a sword).
        const ONE = '\\p{Extended_Pictographic}(?:\\uFE0F|\\p{Emoji_Modifier})?';
        // The check and cross marks that open the right and wrong messages (U+2713 to U+2718) are not pictographs in
        // Unicode, but a screen reader speaks them all the same ("check mark"), and the words after them already say it.
        const MARK = '[\\u2713-\\u2718]\\uFE0F?';
        const SOURCE = `(?:${ONE}(?:\\u200D${ONE})*|${MARK})+`;

        /**
         * Cuts a text into the runs that are pictographs and the runs that are not, in order.
         *
         * @param {string} text The text.
         * @return {Array<{text: string, emoji: boolean}>}
         */
        const split = text => {
            const parts = [];
            let last = 0;
            for (const match of text.matchAll(new RegExp(SOURCE, 'gu'))) {
                if (match.index > last) {
                    parts.push({text: text.slice(last, match.index), emoji: false});
                }
                parts.push({text: match[0], emoji: true});
                last = match.index + match[0].length;
            }
            if (last < text.length) {
                parts.push({text: text.slice(last), emoji: false});
            }
            return parts;
        };

        /**
         * The text without its pictographs, the spaces they leave behind folded into one.
         *
         * @param {string} text The text.
         * @return {string}
         */
        const strip = text => split(String(text))
            .filter(part => !part.emoji)
            .map(part => part.text)
            .join('')
            .replace(/ {2,}/g, ' ')
            .trim();

        /**
         * Whether the text has any pictograph.
         *
         * @param {string} text The text.
         * @return {boolean}
         */
        const has = text => new RegExp(SOURCE, 'u').test(text);

        return {split, strip, has};
    });
})(typeof define === 'function' && define.amd ? define : (deps, factory) => {
    module.exports = factory(...deps.map(dep => require(`./${dep.split('/').pop()}`)));
});
