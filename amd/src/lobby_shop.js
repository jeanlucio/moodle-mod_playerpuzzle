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
 * Lobby loadout shop: buys 1 unit of a consumable type, updating the owned count and coin
 * balance already on the page instead of reloading it.
 *
 * @module     mod_playerpuzzle/lobby_shop
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

/**
 * Buys 1 unit of the clicked button's consumable type, then updates that item's owned
 * count and the coin balance chip from the server's authoritative response.
 *
 * @param {number} cmid Course module id.
 * @param {HTMLElement} button The clicked "Buy" button.
 * @return {Promise<void>}
 */
const buy = async(cmid, button) => {
    const type = button.dataset.type;
    const item = button.closest('.pp-lobby-shop-item');
    const ownedEl = item ? item.querySelector('[data-role="owned"]') : null;

    button.disabled = true;
    try {
        const result = await Ajax.call([{
            methodname: 'mod_playerpuzzle_buy_stock',
            args: {cmid, type},
        }])[0];

        if (ownedEl) {
            ownedEl.textContent = await getString('lobby_stockowned', 'mod_playerpuzzle', result.newquantity);
        }

        const coinValueEl = document.querySelector('[data-role="coinvalue"]');
        const coinChipEl = document.querySelector('[data-role="coinchip"]');
        if (coinValueEl) {
            coinValueEl.textContent = result.newcoinbalance;
        }
        if (coinChipEl) {
            coinChipEl.setAttribute(
                'aria-label',
                await getString('lobby_coinbalance', 'mod_playerpuzzle', result.newcoinbalance)
            );
        }
    } catch (error) {
        // A rejected purchase (insufficient coins, unconfigured economy, a locked
        // concurrent purchase) is an anticipated workflow outcome the server already
        // explains via its own moodle_exception message, not an unexpected bug —
        // Notification.alert() shows that message directly.
        Notification.alert(await getString('lobby_shop_title', 'mod_playerpuzzle'), error.message);
    } finally {
        button.disabled = false;
    }
};

/**
 * Wires every "Buy" button in the Lobby shop.
 *
 * @param {number} cmid Course module id.
 */
export const init = (cmid) => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.pp-lobby-buy');
        if (button) {
            buy(cmid, button);
        }
    });
};
