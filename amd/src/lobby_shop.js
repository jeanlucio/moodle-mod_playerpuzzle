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
 * The shop, in the Lobby and on the screen between phases: buys 1 unit of a consumable type or 1 upgrade of
 * the hero (spending PuzzleCoin) and transfers PlayerHUD coins into PuzzleCoin, updating what is already
 * on the page instead of reloading it.
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
 * Tells the student why a purchase was refused. A native dialog opened with showModal(), like the screen
 * between phases, sits above every Moodle modal, so a refusal there is written into the shop itself; anywhere
 * else it is a Moodle alert.
 *
 * @param {HTMLElement} button The button that was clicked.
 * @param {string} title The alert's title.
 * @param {string} message What the server said.
 * @return {Promise<void>}
 */
const showRefusal = async(button, title, message) => {
    const notice = button.closest('.pp-shop-panel')?.querySelector('.pp-shop-error');
    if (notice && button.closest('dialog')) {
        notice.textContent = message;
        notice.hidden = false;
        return;
    }
    Notification.alert(title, message);
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
        await showRefusal(button, await getString('lobby_shop_title', 'mod_playerpuzzle'), error.message);
    } finally {
        button.disabled = false;
    }
};

/**
 * Buys 1 upgrade of the clicked button's attribute for the hero, then redraws the shop from the server, which
 * is where the new counts, caps and balance are worked out. A purchase that makes the hero evolve says so
 * and, in the Lobby, shows the hero's new look.
 *
 * @param {number} cmid Course module id.
 * @param {HTMLElement} button The clicked "Buy" button.
 * @return {Promise<void>}
 */
const buyUpgrade = async(cmid, button) => {
    const panel = button.closest('.pp-shop-panel');

    button.disabled = true;
    try {
        const result = await Ajax.call([{
            methodname: 'mod_playerpuzzle_buy_upgrade',
            args: {cmid, attribute: button.dataset.attribute},
        }])[0];
        await updateCoinChips(result.newcoinbalance);

        const {html} = await Ajax.call([{methodname: 'mod_playerpuzzle_get_shop', args: {cmid}}])[0];
        const holder = document.createElement('template');
        holder.innerHTML = html;
        const fresh = holder.content.firstElementChild;
        // The opponent's card stays open if the student opened it, instead of folding again at every buy.
        const unfolded = panel.querySelector('.pp-opponent-card')?.open === true;
        panel.replaceWith(fresh);
        if (unfolded) {
            fresh.querySelector('.pp-opponent-card').open = true;
        }

        if (result.evolved) {
            const notice = fresh.querySelector('.pp-hero-evolved');
            notice.textContent = await getString('shop_hero_evolved', 'mod_playerpuzzle');
            notice.hidden = false;
            const lobbyHero = document.querySelector('.pp-lobby-hero img');
            if (lobbyHero) {
                lobbyHero.src = `${M.cfg.wwwroot}/mod/playerpuzzle/pix/heroes/${result.sprite}.webp`;
            }
        }
    } catch (error) {
        await showRefusal(button, await getString('shop_upgrades_title', 'mod_playerpuzzle'), error.message);
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

/** @type {boolean} Whether the click handler is already on the document. */
let wired = false;

/** @type {boolean} Whether a purchase or transfer is under way. */
let busy = false;

/**
 * Runs one shop action, and drops a click that comes while another is under way. A purchase redraws the whole shop
 * from the server when it ends: a second click on the redrawn buttons, or on the old ones, would start another
 * purchase whose redraw can land before the first one's, leaving the screen showing a state the server has already
 * left (a button for something already sold out, and an error when it is pressed).
 *
 * @param {Function} action The action, returning a promise that settles when it is over.
 * @return {Promise<void>}
 */
const run = async(action) => {
    if (busy) {
        return;
    }
    busy = true;
    try {
        await action();
    } finally {
        busy = false;
    }
};

/**
 * Wires every "Buy" button and the "Transfer" button of the shop, once however many times it is called: the
 * Lobby calls it when it loads and the screen between phases when it opens the shop.
 *
 * @param {number} cmid Course module id.
 */
export const init = (cmid) => {
    if (wired) {
        return;
    }
    wired = true;
    document.addEventListener('click', (event) => {
        const buyButton = event.target.closest('.pp-lobby-buy');
        if (buyButton) {
            run(() => buy(cmid, buyButton));
            return;
        }

        const upgradeButton = event.target.closest('.pp-hero-buy');
        if (upgradeButton) {
            run(() => buyUpgrade(cmid, upgradeButton));
            return;
        }

        const transferButton = event.target.closest('.pp-lobby-transfer-btn');
        if (transferButton) {
            run(() => transfer(cmid, transferButton));
        }
    });
};
