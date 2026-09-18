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
 * External function to persist a Music/Sound Effects toggle as a user preference.
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
use mod_playerpuzzle\local\sound_preferences;
use moodle_exception;

/**
 * Saves whether the Music or Sound Effects channel is enabled for the current user.
 *
 * The preference itself is site-wide, not scoped to this instance (a student who mutes
 * music in one PlayerPuzzle activity should not hear it start again in another) — cmid is
 * only used to prove the caller is a real playerpuzzle context with view access, the same
 * defensive pattern every other combat web service already follows.
 */
class set_sound_preference extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'Course module ID'),
            'type'    => new external_value(PARAM_ALPHA, 'Sound channel: music or sfx'),
            'enabled' => new external_value(PARAM_BOOL, 'Whether the channel should be enabled'),
        ]);
    }

    /**
     * Saves the given channel's preference for the current user.
     *
     * @param int $cmid Course module ID.
     * @param string $type Sound channel: 'music' or 'sfx'.
     * @param bool $enabled Whether the channel should be enabled.
     * @return array Result with success.
     */
    public static function execute(int $cmid, string $type, bool $enabled): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'type'    => $type,
            'enabled' => $enabled,
        ]);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        if (!in_array($params['type'], sound_preferences::TYPES, true)) {
            throw new moodle_exception('invalidsoundtype', 'mod_playerpuzzle');
        }

        sound_preferences::set_enabled($params['type'], $params['enabled'], (int) $USER->id);

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
