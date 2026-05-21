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
 * External function definitions for PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_playerpuzzle_save_progress' => [
        'classname'    => 'mod_playerpuzzle\external\save_progress',
        'methodname'   => 'execute',
        'description'  => 'Saves the player progress and coin rewards after a game session.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:view',
    ],
    'mod_playerpuzzle_validate_answer' => [
        'classname'    => 'mod_playerpuzzle\external\validate_answer',
        'methodname'   => 'execute',
        'description'  => 'Validates a player answer during the combat phase.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:view',
    ],
];
