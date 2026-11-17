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
 * External function to save whether the student shows their name in the class ranking.
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
use mod_playerpuzzle\local\ranking_preferences;

/**
 * Saves whether the current user shows their name in the ranking. The preference is site-wide, not scoped to the
 * instance: cmid only proves the caller is in a real playerpuzzle context with view access, like every other
 * web service of the plugin.
 */
class set_ranking_preference extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'Course module ID'),
            'visible' => new external_value(PARAM_BOOL, 'Whether the student shows their name in the ranking'),
        ]);
    }

    /**
     * Saves the choice for the current user.
     *
     * @param int $cmid Course module ID.
     * @param bool $visible Whether the student shows their name in the ranking.
     * @return array Result with success.
     */
    public static function execute(int $cmid, bool $visible): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'visible' => $visible]);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        ranking_preferences::set_anonymous((int) $USER->id, !$params['visible']);

        return ['success' => true];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the preference was saved'),
        ]);
    }
}
