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
 * Lobby loadout shop: buys 1 unit of a consumable type (spending PuzzleCoin) and transfers
 * PlayerHUD coins into PuzzleCoin, updating the balances already on the page instead of
 * reloading it.
 *
 * @module     mod_playerpuzzle/lobby_shop
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

/**
 * Updates every PuzzleCoin balance chip on the page — the coin chip is duplicated between
 * the Lobby's main view and the Shop panel (so the balance stays visible while shopping,
 * without needing both views open at once), so a purchase or transfer must refresh all of
 * them, not just the first match.
 *
 * @param {number} newbalance The authoritative new balance from the server's response.
 * @return {Promise<void>}
 */
const updateCoinChips = async(newbalance) => {
    const label = await getString('lobby_puzzlecoinbalance', 'mod_playerpuzzle', newbalance);
    document.querySelectorAll('[data-role="coinvalue"]').forEach((el) => {
        el.textContent = newbalance;
    });
    document.querySelectorAll('[data-role="coinchip"]').forEach((el) => {
        el.setAttribute('aria-label', label);
    });
};

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

        await updateCoinChips(result.newcoinbalance);
    } catch (error) {
        // A rejected purchase (insufficient coins, a locked concurrent purchase) is an
        // anticipated workflow outcome the server already explains via its own
        // moodle_exception message, not an unexpected bug — Notification.alert() shows
        // that message directly.
        Notification.alert(await getString('lobby_shop_title', 'mod_playerpuzzle'), error.message);
    } finally {
        button.disabled = false;
    }
};

/**
 * Converts the amount typed in the transfer input from PlayerHUD coins into PuzzleCoin, then
 * updates both balance chips from the server's authoritative response.
 *
 * @param {number} cmid Course module id.
 * @param {HTMLElement} button The clicked "Transfer" button.
 * @return {Promise<void>}
 */
const transfer = async(cmid, button) => {
    const widget = button.closest('.pp-lobby-transfer');
    const amountInput = widget ? widget.querySelector('.pp-lobby-transfer-input') : null;
    const amount = amountInput ? parseInt(amountInput.value, 10) : 0;

    button.disabled = true;
    try {
        const result = await Ajax.call([{
            methodname: 'mod_playerpuzzle_transfer_hud_coins',
            args: {cmid, amount},
        }])[0];

        const hudValueEl = widget ? widget.querySelector('[data-role="hudcoinvalue"]') : null;
        const hudChipEl = widget ? widget.querySelector('[data-role="hudcoinchip"]') : null;
        if (hudValueEl) {
            hudValueEl.textContent = result.newhudbalance;
        }
        if (hudChipEl) {
            hudChipEl.setAttribute(
                'aria-label',
                await getString('lobby_hudcoinbalance', 'mod_playerpuzzle', result.newhudbalance)
            );
        }

        await updateCoinChips(result.newpuzzlecoinbalance);
    } catch (error) {
        // A rejected transfer (invalid amount, insufficient PlayerHUD balance, a locked
        // concurrent request) is an anticipated workflow outcome the server already
        // explains via its own moodle_exception message.
        Notification.alert(await getString('lobby_transfer_title', 'mod_playerpuzzle'), error.message);
    } finally {
        button.disabled = false;
    }
};

/**
 * Wires every "Buy" button and the "Transfer" button in the Lobby.
 *
 * @param {number} cmid Course module id.
 */
export const init = (cmid) => {
    document.addEventListener('click', (event) => {
        const buyButton = event.target.closest('.pp-lobby-buy');
        if (buyButton) {
            buy(cmid, buyButton);
            return;
        }

        const transferButton = event.target.closest('.pp-lobby-transfer-btn');
        if (transferButton) {
            transfer(cmid, transferButton);
        }
    });
};
