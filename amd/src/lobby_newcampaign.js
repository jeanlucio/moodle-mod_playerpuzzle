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
 * Lobby "Start a new Campaign" button: asks before submitting, since a new Campaign empties the
 * student's PuzzleCoin balance and stock and that cannot be undone.
 *
 * @module     mod_playerpuzzle/lobby_newcampaign
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';

/**
 * Wires the confirmation to the Play form, when the Lobby rendered it as a new Campaign (the form
 * then carries the warning in data-confirm). Without this module the form still submits, after the
 * warning printed above the button.
 */
export const init = () => {
    const form = document.getElementById('pp-lobby-playform');
    if (!form || !form.dataset.confirm) {
        return;
    }
    const button = form.querySelector('button[type="submit"]');
    let confirmed = false;

    form.addEventListener('submit', async(event) => {
        if (confirmed) {
            return;
        }
        event.preventDefault();
        try {
            await Notification.saveCancelPromise(button.textContent, form.dataset.confirm, button.textContent);
        } catch (error) {
            // Cancelled: the Campaign does not start.
            return;
        }
        confirmed = true;
        form.requestSubmit();
    });
};
