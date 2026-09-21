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
    'mod_playerpuzzle_draw_question' => [
        'classname'    => 'mod_playerpuzzle\external\draw_question',
        'methodname'   => 'execute',
        'description'  => 'Draws (or re-serves the already-open) question for a mana-full challenge.',
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
    'mod_playerpuzzle_advance_phase' => [
        'classname'    => 'mod_playerpuzzle\external\advance_phase',
        'methodname'   => 'execute',
        'description'  => 'Advances a Campaign attempt to its next phase after the boss is defeated.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:view',
    ],
    'mod_playerpuzzle_buy_consumable' => [
        'classname'    => 'mod_playerpuzzle\external\buy_consumable',
        'methodname'   => 'execute',
        'description'  => 'Authorizes the purchase of a combat consumable.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:view',
    ],
    'mod_playerpuzzle_buy_stock' => [
        'classname'    => 'mod_playerpuzzle\external\buy_stock',
        'methodname'   => 'execute',
        'description'  => 'Buys 1 unit of loadout stock for the Lobby shop, spending PuzzleCoin.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:view',
    ],
    'mod_playerpuzzle_transfer_hud_coins' => [
        'classname'    => 'mod_playerpuzzle\external\transfer_hud_coins',
        'methodname'   => 'execute',
        'description'  => 'Converts PlayerHUD coins into PuzzleCoin, by a student-chosen amount.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:view',
    ],
    'mod_playerpuzzle_save_combat_state' => [
        'classname'    => 'mod_playerpuzzle\external\save_combat_state',
        'methodname'   => 'execute',
        'description'  => 'Checkpoints the in-progress board/combat state, for resuming after a reload.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:view',
    ],
    'mod_playerpuzzle_generate_questions' => [
        'classname'    => 'mod_playerpuzzle\external\generate_questions',
        'methodname'   => 'execute',
        'description'  => 'Generates an AI question preview batch, without saving it.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:managequestions',
    ],
    'mod_playerpuzzle_save_generated_questions' => [
        'classname'    => 'mod_playerpuzzle\external\save_generated_questions',
        'methodname'   => 'execute',
        'description'  => 'Saves the AI-generated questions a teacher confirmed, pending approval.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:managequestions',
    ],
    'mod_playerpuzzle_set_sound_preference' => [
        'classname'    => 'mod_playerpuzzle\external\set_sound_preference',
        'methodname'   => 'execute',
        'description'  => 'Saves whether the Music or Sound Effects channel is enabled for the current user.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/playerpuzzle:view',
    ],
];
