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

define(['mod_playerpuzzle/stage/emoji'], (Emoji) => {
    'use strict';

    let speechEnabled = false;

    /**
     * The live region an announcement goes to. While a modal <dialog> is open everything outside it is inert, which
     * takes it out of the accessibility tree, so a live region outside the dialog is not announced at all: the
     * announcement goes to one inside the topmost open dialog instead (made the first time it is needed).
     *
     * @return {{region: HTMLElement|null, fresh: boolean}} The region, and whether it was just made (a region made
     *  together with its text is not always announced, so the caller writes the text a moment later).
     */
    const liveRegion = () => {
        const dialogs = document.querySelectorAll('dialog[open]');
        if (!dialogs.length) {
            return {region: document.getElementById('pp-aria-live'), fresh: false};
        }
        const dialog = dialogs[dialogs.length - 1];
        let region = dialog.querySelector(':scope > .pp-dialog-live');
        if (region) {
            return {region, fresh: false};
        }
        region = document.createElement('div');
        region.className = 'visually-hidden pp-dialog-live';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        dialog.appendChild(region);
        return {region, fresh: true};
    };

    /**
     * Wraps the pictographs of a text node in <span aria-hidden="true">, so the screen reader does not speak them.
     *
     * @param {Text} node The text node.
     */
    const hideEmojisIn = node => {
        if (!node.nodeValue || !Emoji.has(node.nodeValue)) {
            return;
        }
        if (node.parentElement && node.parentElement.closest('[aria-hidden="true"]')) {
            return;
        }
        const fragment = document.createDocumentFragment();
        Emoji.split(node.nodeValue).forEach(part => {
            if (!part.emoji) {
                fragment.appendChild(document.createTextNode(part.text));
                return;
            }
            const span = document.createElement('span');
            span.setAttribute('aria-hidden', 'true');
            span.textContent = part.text;
            fragment.appendChild(span);
        });
        node.replaceWith(fragment);
    };

    /**
     * Hides the pictographs of every text node at or under a node.
     *
     * @param {Node} node The node.
     */
    const scan = node => {
        if (node.nodeType === Node.TEXT_NODE) {
            hideEmojisIn(node);
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }
        const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
        const found = [];
        while (walker.nextNode()) {
            found.push(walker.currentNode);
        }
        found.forEach(hideEmojisIn);
    };

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
         * Writes text to the live region (the one inside the open dialog, when there is one), and — only when
         * enabled via setSpeechEnabled() — also speaks it through the Web Speech API. Centralizing both
         * here means every event (turn start, damage, mana full, end of match...) gets speech
         * for free the moment it already calls this instead of touching the DOM directly,
         * with no per-call-site speechSynthesis logic to duplicate or forget. Pictographs are taken out of the
         * text first: a screen reader speaks them by name, and so does the speech synthesis.
         *
         * @param {string} text The announcement text.
         * @return {void}
         */
        announce(text) {
            const clean = Emoji.strip(text);
            const {region, fresh} = liveRegion();
            if (region) {
                if (fresh) {
                    window.setTimeout(() => {
                        region.textContent = clean;
                    }, 150);
                } else {
                    region.textContent = clean;
                }
            }

            if (speechEnabled && window.speechSynthesis) {
                // Cancels whatever is still being read before speaking the new line — without
                // this, fast-fired announcements (e.g. a match immediately followed by the
                // boss's own turn) would queue up and read out of sync with what's on screen.
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(new SpeechSynthesisUtterance(clean));
            }
        },

        /**
         * Keeps the pictographs under an element out of the accessibility tree, now and for whatever is put there
         * later (the game writes its texts, buttons and dialogs into the page as it goes). The emoji stays on screen;
         * only its <span> is aria-hidden, so the name of a button reads "Attack!" and not "crossed swords Attack!".
         *
         * @param {Element|null} root The element to watch.
         * @return {void}
         */
        hideEmojis(root) {
            if (!root) {
                return;
            }
            scan(root);
            new MutationObserver(mutations => {
                mutations.forEach(mutation => {
                    if (mutation.type === 'characterData') {
                        scan(mutation.target);
                        return;
                    }
                    mutation.addedNodes.forEach(scan);
                });
            }).observe(root, {childList: true, subtree: true, characterData: true});
        }
    };
});
