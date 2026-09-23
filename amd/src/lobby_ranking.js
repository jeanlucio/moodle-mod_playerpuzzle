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
 * Lobby ranking panel: swaps the Lobby's main panel for the ranking and back, in place.
 *
 * @module     mod_playerpuzzle/lobby_ranking
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wires the Ranking and Back buttons. The ranking's rows are already rendered with the page,
 * so switching needs no request. Focus follows the switch — onto the ranking's heading when
 * it opens, and back onto the Ranking button when it closes — so a keyboard or screen-reader
 * user lands where the content changed instead of on a now-hidden element.
 */
export const init = () => {
    const main = document.getElementById('pp-lobby-main');
    const ranking = document.getElementById('pp-lobby-ranking');
    const opener = document.querySelector('[data-action="open-ranking"]');
    const closer = document.querySelector('[data-action="close-ranking"]');
    if (!main || !ranking || !opener || !closer) {
        return;
    }

    opener.addEventListener('click', () => {
        main.hidden = true;
        ranking.hidden = false;
        opener.setAttribute('aria-expanded', 'true');
        document.getElementById('pp-lobby-ranking-title').focus();
    });

    closer.addEventListener('click', () => {
        ranking.hidden = true;
        main.hidden = false;
        opener.setAttribute('aria-expanded', 'false');
        opener.focus();
    });
};
