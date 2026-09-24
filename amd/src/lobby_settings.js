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
import {get_string as getString} from 'core/str';

/** @type {Object<string, string>} Checkbox element id to sound_preferences channel. */
const CHANNELS_BY_ID = {
    'pp-lobby-speech': 'speech',
    'pp-lobby-music': 'music',
    'pp-lobby-sfx': 'sfx',
};

/**
 * Persists an audio preference, fire-and-forget — mirrors ui.js's own saveSoundPreference()
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
 * Updates the main view's compact difficulty readout to match the radio the student just
 * picked, so the choice stays visible without reopening the Settings panel.
 *
 * @param {HTMLInputElement} radio The radio just selected.
 * @return {Promise<void>}
 */
const updateDifficultyReadout = async(radio) => {
    const readout = document.getElementById('pp-lobby-difficulty-readout');
    if (!readout) {
        return;
    }
    const option = radio.closest('.pp-lobby-difficulty-option');
    const label = option ? option.querySelector('label') : null;
    if (!label) {
        return;
    }
    readout.textContent = await getString('lobby_difficulty_selected', 'mod_playerpuzzle', label.textContent);
};

/**
 * Wires the narration/Music/Sound Effects checkboxes and the difficulty picker in the
 * Settings panel.
 *
 * @param {number} cmid Course module id.
 */
export const init = (cmid) => {
    document.addEventListener('change', (event) => {
        const channel = CHANNELS_BY_ID[event.target.id];
        if (channel) {
            saveSoundPreference(cmid, channel, event.target.checked);
            return;
        }

        if (event.target.name === 'difficulty') {
            updateDifficultyReadout(event.target);
        }
    });
};
