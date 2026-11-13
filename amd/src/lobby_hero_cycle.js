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
 * Lobby hero picture: while the student has not chosen a hero, the picture that stands in the Lobby goes through the
 * six heroes, one after the other, so none of them looks like the one they have to take.
 *
 * @module     mod_playerpuzzle/lobby_hero_cycle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** How long each hero stays, in milliseconds. */
const SHOW_FOR = 3200;

/** How long the picture takes to fade out, and to fade back in with the next one, in milliseconds. */
const FADE_FOR = 280;

/**
 * Starts the cycle when the Lobby rendered the picture with a list of heroes in data-heroes. Without this module,
 * or with the reduced-motion setting on, the first hero of the list stays still.
 */
export const init = () => {
    const holder = document.querySelector('.pp-lobby-hero[data-heroes]');
    const image = holder ? holder.querySelector('img') : null;
    if (!image) {
        return;
    }

    let urls = [];
    try {
        urls = JSON.parse(holder.dataset.heroes);
    } catch (error) {
        return;
    }
    if (!Array.isArray(urls) || urls.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    // Loaded ahead, so a hero never shows up half drawn in the middle of its turn.
    urls.forEach(url => {
        new Image().src = url;
    });

    let index = Math.max(0, urls.indexOf(image.getAttribute('src')));
    window.setInterval(() => {
        // A hidden tab has nobody to look at the picture, and its timers are throttled anyway.
        if (document.hidden) {
            return;
        }
        holder.classList.add('pp-lobby-hero-fading');
        window.setTimeout(() => {
            index = (index + 1) % urls.length;
            image.src = urls[index];
            holder.classList.remove('pp-lobby-hero-fading');
        }, FADE_FOR);
    }, SHOW_FOR);
};
