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
 * External function to convert PlayerHUD coins into PuzzleCoin.
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
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\hud_service;
use mod_playerpuzzle\local\user_stock;
use moodle_exception;

/**
 * Converts a student-chosen amount of the instance's configured PlayerHUD coin item into
 * PuzzleCoin, 1:1. This is the only way PlayerHUD coins reach PuzzleCoin — PlayerPuzzle
 * itself never credits hud_coin_item automatically; a student who wants to bring in coins
 * earned elsewhere in the PlayerHUD economy does so explicitly, choosing how much.
 */
class transfer_hud_coins extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course module ID'),
            'amount' => new external_value(PARAM_INT, 'Units to convert from the PlayerHUD coin item'),
        ]);
    }

    /**
     * Converts PlayerHUD coins into PuzzleCoin.
     *
     * @param int $cmid Course module ID.
     * @param int $amount Units to convert.
     * @return array Result with success and the two updated balances.
     */
    public static function execute(int $cmid, int $amount): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'   => $cmid,
            'amount' => $amount,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        if ($params['amount'] <= 0) {
            throw new moodle_exception('invalidtransferamount', 'mod_playerpuzzle');
        }

        // Locked by user+instance, not by an attempt token — the transfer, like the loadout
        // purchase it feeds, happens outside any match. See
        // security::with_locked_user_stock()'s own docblock.
        $result = security::with_locked_user_stock(
            (int) $USER->id,
            (int) $playerpuzzle->id,
            function () use ($USER, $playerpuzzle, $params): array {
                $itemid = (int) $playerpuzzle->hud_coin_item;
                $blockinstanceid = hud_service::get_block_instance_id((int) $playerpuzzle->course);
                if ($itemid <= 0 || $blockinstanceid === null) {
                    throw new moodle_exception('hudeconomyunavailable', 'mod_playerpuzzle');
                }

                if (!hud_service::consume_item($blockinstanceid, (int) $USER->id, $itemid, $params['amount'])) {
                    throw new moodle_exception('insufficienthudstock', 'mod_playerpuzzle');
                }

                user_stock::credit(
                    (int) $USER->id,
                    (int) $playerpuzzle->id,
                    user_stock::CURRENCY_TYPE,
                    $params['amount']
                );

                return [
                    'success'             => true,
                    'newpuzzlecoinbalance' => user_stock::get_quantity(
                        (int) $USER->id,
                        (int) $playerpuzzle->id,
                        user_stock::CURRENCY_TYPE
                    ),
                    'newhudbalance'       => hud_service::get_upgrade_level(
                        $blockinstanceid,
                        (int) $USER->id,
                        $itemid
                    ),
                ];
            }
        );

        if ($result === false) {
            // Another transfer or purchase for the same user/instance is already mutating
            // these balances right now — a genuinely parallel double-click/second tab, not a
            // coding mistake.
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
            'success'              => new external_value(PARAM_BOOL, 'Whether the transfer succeeded'),
            'newpuzzlecoinbalance' => new external_value(PARAM_INT, 'PuzzleCoin balance after the transfer'),
            'newhudbalance'        => new external_value(PARAM_INT, 'PlayerHUD coin item balance after the transfer'),
        ]);
    }
}
