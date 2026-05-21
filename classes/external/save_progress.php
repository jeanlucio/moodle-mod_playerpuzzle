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
 * External function to save player progress after a game session.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;

/**
 * Saves the player's coin rewards and game result to the inventory.
 */
class save_progress extends external_api {

    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'Course module ID'),
            'gold'    => new external_value(PARAM_INT, 'Gold coins earned in this session'),
            'victory' => new external_value(PARAM_INT, 'Whether it was a victory (1) or defeat (0)'),
            'damage'  => new external_value(PARAM_INT, 'Damage dealt to the boss'),
        ]);
    }

    /**
     * Saves coins earned to the player inventory (fetch → modify in PHP → update).
     *
     * @param int $cmid Course module ID.
     * @param int $gold Gold coins earned.
     * @param int $victory Whether it was a victory.
     * @param int $damage Damage dealt to the boss.
     * @return array Result with status, message, and total coins.
     */
    public static function execute(int $cmid, int $gold, int $victory, int $damage): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'gold'    => $gold,
            'victory' => $victory,
            'damage'  => $damage,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $safegold = max(0, $params['gold']);
        $inventory = $DB->get_record('playerpuzzle_inventory', ['userid' => $USER->id]);

        if ($inventory) {
            $inventory->coins += $safegold;
            $inventory->timemodified = time();
            $DB->update_record('playerpuzzle_inventory', $inventory);
        } else {
            $inventory = new stdClass();
            $inventory->userid = $USER->id;
            $inventory->coins = $safegold;
            $inventory->swordlevel = 1;
            $inventory->shieldlevel = 1;
            $inventory->timecreated = time();
            $inventory->timemodified = time();
            $DB->insert_record('playerpuzzle_inventory', $inventory);
        }

        return [
            'status'     => 'success',
            'message'    => get_string('progresssaved', 'mod_playerpuzzle', $inventory->coins),
            'totalcoins' => (int) $inventory->coins,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'     => new external_value(PARAM_ALPHA, 'Success status'),
            'message'    => new external_value(PARAM_TEXT, 'Feedback message for the player'),
            'totalcoins' => new external_value(PARAM_INT, 'Total coins the player now holds'),
        ]);
    }
}
