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
 * Lobby Settings panel: the narration/Music/Sound Effects toggles, and keeping the main
 * view's difficulty readout in sync with the picker moved into this panel.
 *
 * @module     mod_playerpuzzle/lobby_settings
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** @type {Object<string, string>} Checkbox element id to sound_preferences channel. */
const CHANNELS_BY_ID = {
    'pp-lobby-speech': 'speech',
    'pp-lobby-music': 'music',
    'pp-lobby-sfx': 'sfx',
};

/**
 * Persists an audio preference, fire-and-forget — mirrors hud.js's own saveSoundPreference()
 * for the in-game Music/Sound Effects badges: the checkbox has already changed state by the
 * time this runs, so nothing in the UI waits on the round trip, and a failure (network blip)
 * is silently ignored — worst case, the preference simply does not survive a reload.
 *
 * @param {number} cmid Course module id.
 * @param {string} type Channel: 'speech', 'music' or 'sfx'.
 * @param {boolean} enabled New state.
 */
const saveSoundPreference = (cmid, type, enabled) => {
    Ajax.call([{
        methodname: 'mod_playerpuzzle_set_sound_preference',
        args: {cmid, type, enabled},
    }]);
};

/**
 * Steps the difficulty button to the next difficulty: the hidden field that goes with the Play form, the text on
 * the button and the line under it that says what the difficulty changes. Every text came with the page, so
 * nothing is requested.
 *
 * @param {HTMLElement} button The difficulty button, whose data-choices lists the difficulties as JSON.
 */
const cycleDifficulty = (button) => {
    const input = document.getElementById('pp-lobby-difficulty-value');
    const readout = document.getElementById('pp-lobby-difficulty-readout');
    const description = document.getElementById('pp-lobby-difficulty-description');
    if (!input || !readout) {
        return;
    }
    const choices = JSON.parse(button.dataset.choices);
    const index = choices.findIndex((choice) => choice.value === input.value);
    const next = choices[(index + 1) % choices.length];
    input.value = next.value;
    readout.textContent = next.text;
    if (description) {
        description.textContent = next.description;
    }
};

/**
 * Wires the narration/Music/Sound Effects checkboxes in the Settings panel and the difficulty button on the
 * main view.
 *
 * @param {number} cmid Course module id.
 */
export const init = (cmid) => {
    document.addEventListener('change', (event) => {
        const channel = CHANNELS_BY_ID[event.target.id];
        if (channel) {
            saveSoundPreference(cmid, channel, event.target.checked);
        }
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action="cycle-difficulty"]');
        if (button) {
            cycleDifficulty(button);
        }
    });
};
