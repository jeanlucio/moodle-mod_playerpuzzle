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
 * External function to buy one upgrade for the student's Campaign hero.
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
use mod_playerpuzzle\local\hero_shop;
use moodle_exception;

/**
 * Buys 1 upgrade of HP, attack or armor for the hero of the student's Campaign, spending PuzzleCoin. The price,
 * the cap of the hero's stage and the evolution that may follow are the shop's (hero_shop), not decided here.
 */
class buy_upgrade extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'attribute' => new external_value(PARAM_ALPHA, 'Attribute to upgrade: hp, attack or armor'),
        ]);
    }

    /**
     * Buys 1 upgrade.
     *
     * @param int $cmid Course module ID.
     * @param string $attribute The attribute.
     * @return array The balance left, what the attribute has bought of its cap, and the hero's stage.
     */
    public static function execute(int $cmid, string $attribute): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'attribute' => $attribute,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);
        // The shop feeds real play, which a visitor never gets, see game_page_service::check_guest_demo_only().
        if (is_guest($context)) {
            throw new moodle_exception('guestdemoonly', 'mod_playerpuzzle');
        }

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        return ['success' => true] + hero_shop::buy($playerpuzzle, (int) $USER->id, $params['attribute']);
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the purchase succeeded'),
            'newcoinbalance' => new external_value(PARAM_INT, 'PuzzleCoin balance remaining after the purchase'),
            'bought' => new external_value(PARAM_INT, 'Upgrades of this attribute bought so far'),
            'cap' => new external_value(PARAM_INT, 'The most of this attribute the hero\'s stage before the purchase allowed'),
            'stage' => new external_value(PARAM_INT, 'The hero\'s stage after the purchase'),
            'evolved' => new external_value(PARAM_BOOL, 'Whether the purchase made the hero evolve'),
            'sprite' => new external_value(PARAM_ALPHANUM, 'The hero\'s sprite after the purchase'),
        ]);
    }
}
