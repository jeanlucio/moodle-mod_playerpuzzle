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
 * Lobby panel switching: swaps the Lobby's main panel for the Shop, Settings or Ranking
 * panel and back, in place — one shared handler for all three, keyed only by data
 * attributes, rather than a near-duplicate module per panel.
 *
 * @module     mod_playerpuzzle/lobby_panels
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wires every "open panel" button (data-action="open-panel", data-panel="<id>") and every
 * "close panel" button (data-action="close-panel", inside the panel itself) in the Lobby.
 * Each panel's content is already rendered with the page, so switching needs no request.
 * Focus follows the switch — onto the panel's own heading when it opens, and back onto the
 * button that opened it when it closes — so a keyboard or screen-reader user lands where
 * the content changed instead of on a now-hidden element.
 */
export const init = () => {
    const main = document.getElementById('pp-lobby-main');
    if (!main) {
        return;
    }

    let opener = null;

    document.addEventListener('click', (event) => {
        const openButton = event.target.closest('[data-action="open-panel"]');
        if (openButton) {
            const panel = document.getElementById(openButton.dataset.panel);
            if (!panel) {
                return;
            }
            opener = openButton;
            main.hidden = true;
            panel.hidden = false;
            openButton.setAttribute('aria-expanded', 'true');
            const heading = panel.querySelector('[tabindex="-1"]');
            if (heading) {
                heading.focus();
            }
            return;
        }

        const closeButton = event.target.closest('[data-action="close-panel"]');
        if (closeButton) {
            const panel = closeButton.closest('.pp-lobby-view');
            if (panel) {
                panel.hidden = true;
            }
            main.hidden = false;
            if (opener) {
                opener.setAttribute('aria-expanded', 'false');
                opener.focus();
                opener = null;
            }
        }
    });
};
