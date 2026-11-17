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
 * Lobby dialog for a refused start: when Play sends the student back because the activity's rules do not let a new
 * attempt begin (no item to pay the retry cost, a limit reached, a cooldown), the reason opens in a dialog.
 *
 * @module     mod_playerpuzzle/lobby_refusal
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';

/**
 * Opens the dialog with the reason the Lobby printed. The printed note stays for a page without scripts and for
 * assistive technology until the dialog takes over, and is hidden once it has, so the reason is not said twice.
 */
export const init = () => {
    const note = document.getElementById('pp-lobby-refusal');
    if (!note) {
        return;
    }
    // The text is plain: the dialog's body is HTML, so it is escaped before it goes in.
    const body = document.createElement('p');
    body.textContent = note.textContent;
    Notification.alert(note.dataset.title, body.outerHTML);
    note.hidden = true;
};
