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
 * External function to start the next match from inside the game.
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
use mod_playerpuzzle\local\constants;
use mod_playerpuzzle\local\match_start;

/**
 * Starts the next match (the next phase, another try after a defeat, another Demo) the way play.php does, and
 * hands the game what the page would have held, so it goes on without a reload: a reload always leaves full
 * screen. A start the rules refuse comes back as the page to go to, where the refusal is explained.
 */
class start_match extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'difficulty' => new external_value(
                PARAM_ALPHA,
                'Difficulty of a new attempt',
                VALUE_DEFAULT,
                constants::DIFFICULTY_NORMAL
            ),
            'isdemo' => new external_value(PARAM_BOOL, 'Whether the match is a Demo', VALUE_DEFAULT, false),
            'mobile' => new external_value(PARAM_BOOL, 'Whether the game runs on a phone', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Starts the match.
     *
     * @param int $cmid Course module ID.
     * @param string $difficulty Difficulty of a new attempt; a resumed one keeps its own.
     * @param bool $isdemo Whether the match is a Demo.
     * @param bool $mobile Whether the game runs on a phone.
     * @return array {redirect, config, html}
     */
    public static function execute(int $cmid, string $difficulty, bool $isdemo, bool $mobile): array {
        global $DB, $OUTPUT, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'difficulty' => $difficulty,
            'isdemo' => $isdemo,
            'mobile' => $mobile,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);
        $userid = (int) $USER->id;

        // The same request the end screens sent to play.php: no hero (a Campaign plays on with its own, a new one
        // goes back to the Lobby to choose) and no Lobby choice of difficulty.
        $start = match_start::begin($cm, $playerpuzzle, $context, $userid, [
            'isdemo' => $params['isdemo'],
            'hero' => '',
            'confirmnewcampaign' => false,
            'difficulty' => $params['difficulty'],
            'chosendifficulty' => null,
            'mobile' => $params['mobile'],
        ]);
        if ($start['redirect'] !== null) {
            return ['redirect' => $start['redirect']->out(false), 'config' => '', 'html' => ''];
        }

        return [
            'redirect' => '',
            'config' => json_encode($start['config']),
            'html' => $OUTPUT->render_from_template(
                'mod_playerpuzzle/game_parts',
                match_start::parts_context($playerpuzzle, $context, $userid, $start['config'])
            ),
        ];
    }

    /**
     * Returns the return value definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'redirect' => new external_value(PARAM_URL, 'The page to go to instead, empty when the match is ready'),
            'config' => new external_value(PARAM_RAW, "The match's game config, as JSON"),
            'html' => new external_value(PARAM_RAW, "What goes in the game's box for the match"),
        ]);
    }
}
