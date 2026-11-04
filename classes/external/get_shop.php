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
 * External function to read the shop panel as it stands.
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
use mod_playerpuzzle\local\lobby_page_service;
use moodle_exception;

/**
 * Returns the shop panel, the same one the Lobby shows, rendered with the student's balance, stock and hero
 * as they are now. The screen between phases shows it after the win is banked, and a purchase refreshes it.
 */
class get_shop extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Renders the shop panel.
     *
     * @param int $cmid Course module ID.
     * @return array The panel's HTML.
     */
    public static function execute(int $cmid): array {
        global $DB, $PAGE, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);
        // The shop feeds real play, which a visitor never gets, see game_page_service::check_guest_demo_only().
        if (is_guest($context)) {
            throw new moodle_exception('guestdemoonly', 'mod_playerpuzzle');
        }

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);
        $data = ['cmid' => (int) $cm->id]
            + lobby_page_service::build_shop_context((int) $cm->course, $playerpuzzle, (int) $USER->id)
            + hero_shop::context($playerpuzzle, (int) $USER->id);

        return ['html' => $PAGE->get_renderer('core')->render_from_template('mod_playerpuzzle/shop_panel', $data)];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'The shop panel, rendered from a template of this plugin'),
        ]);
    }
}
