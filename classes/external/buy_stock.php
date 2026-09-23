<?php
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
 * External function to buy one unit of loadout stock, before a match starts.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playerpuzzle\local\attempt_consumables;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\user_stock;
use moodle_exception;

/**
 * Buys 1 unit of a consumable type for the Lobby loadout, spending PuzzleCoin — the
 * PlayerPuzzle's own balance, always available regardless of PlayerHUD. PlayerHUD coins
 * never fund a purchase directly; they only reach PuzzleCoin through a separate, explicit
 * transfer (transfer_hud_coins.php).
 */
class buy_stock extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'type' => new external_value(PARAM_ALPHA, 'Consumable type: potion, shield, magic, sword or hint'),
        ]);
    }

    /**
     * Buys 1 unit of loadout stock.
     *
     * @param int $cmid Course module ID.
     * @param string $type Consumable type.
     * @return array Result with success and newquantity.
     */
    public static function execute(int $cmid, string $type): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'type' => $type,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);
        // The shop and the transfer feed real play, which the guest account never gets — see
        // game_page_service::check_guest_demo_only().
        if (isguestuser()) {
            throw new moodle_exception('guestdemoonly', 'mod_playerpuzzle');
        }

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        if (!in_array($params['type'], attempt_consumables::TYPES, true)) {
            throw new moodle_exception('consumabletypeinvalid', 'mod_playerpuzzle');
        }

        // Locked by user+instance, not by an attempt token: a purchase happens before any
        // match starts, so there is no in-progress attempt to lock on — see
        // security::with_locked_user_stock()'s own docblock.
        $result = security::with_locked_user_stock(
            (int) $USER->id,
            (int) $playerpuzzle->id,
            function () use ($USER, $playerpuzzle, $params): array {
                $price = combat::consumable_price($params['type']);
                $debited = user_stock::debit(
                    (int) $USER->id,
                    (int) $playerpuzzle->id,
                    user_stock::CURRENCY_TYPE,
                    $price
                );
                if (!$debited) {
                    throw new moodle_exception('insufficientcoins', 'mod_playerpuzzle');
                }

                user_stock::credit((int) $USER->id, (int) $playerpuzzle->id, $params['type'], 1);

                return [
                    'success'        => true,
                    'newquantity'    => user_stock::get_quantity(
                        (int) $USER->id,
                        (int) $playerpuzzle->id,
                        $params['type']
                    ),
                    'newcoinbalance' => user_stock::get_quantity(
                        (int) $USER->id,
                        (int) $playerpuzzle->id,
                        user_stock::CURRENCY_TYPE
                    ),
                ];
            }
        );

        if ($result === false) {
            // Another purchase for the same user/instance is already mutating the coin
            // balance and stock right now — a genuinely parallel double-click/second tab,
            // not a coding mistake.
            throw new moodle_exception('stockpurchaselocked', 'mod_playerpuzzle');
        }

        return $result;
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'        => new external_value(PARAM_BOOL, 'Whether the purchase succeeded'),
            'newquantity'    => new external_value(PARAM_INT, 'Units owned of this type after the purchase'),
            'newcoinbalance' => new external_value(PARAM_INT, 'PuzzleCoin balance remaining after the purchase'),
        ]);
    }
}
