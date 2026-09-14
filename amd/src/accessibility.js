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
 * Shared accessibility announcement helper for PlayerPuzzle.
 *
 * @module     mod_playerpuzzle/accessibility
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    let speechEnabled = false;

    return {
        /**
         * Turns speechSynthesis narration on or off for every future announce() call. Read
         * once at boot from the site-wide "Narração de jogadas" setting (off by default) —
         * never toggled per call site, so combat.js/board.js don't each need to know about it.
         *
         * @param {boolean} enabled Whether announce() should also speak the text aloud.
         * @return {void}
         */
        setSpeechEnabled(enabled) {
            speechEnabled = enabled;
        },

        /**
         * Writes text to the shared #pp-aria-live region, and — only when enabled via
         * setSpeechEnabled() — also speaks it through the Web Speech API. Centralizing both
         * here means every event (turn start, damage, mana full, end of match...) gets speech
         * for free the moment it already calls this instead of touching the DOM directly,
         * with no per-call-site speechSynthesis logic to duplicate or forget.
         *
         * @param {string} text The announcement text.
         * @return {void}
         */
        announce(text) {
            const liveRegion = document.getElementById('pp-aria-live');
            if (liveRegion) {
                liveRegion.textContent = text;
            }

            if (speechEnabled && window.speechSynthesis) {
                // Cancels whatever is still being read before speaking the new line — without
                // this, fast-fired announcements (e.g. a match immediately followed by the
                // boss's own turn) would queue up and read out of sync with what's on screen.
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(new SpeechSynthesisUtterance(text));
            }
        }
    };
});
