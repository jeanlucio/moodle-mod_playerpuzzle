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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\hud_service;
use moodle_exception;

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
            'token'   => new external_value(PARAM_ALPHANUM, 'Anti-replay token issued when the attempt started'),
            'gold'    => new external_value(PARAM_INT, 'Gold coins earned in this session'),
            'victory' => new external_value(PARAM_INT, 'Whether it was a victory (1) or defeat (0)'),
            'damage'  => new external_value(PARAM_INT, 'Damage dealt to the boss'),
        ]);
    }

    /**
     * Consumes the attempt token, persists the attempt outcome, and credits coins on victory.
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token issued when the attempt started.
     * @param int $gold Gold coins earned.
     * @param int $victory Whether it was a victory.
     * @param int $damage Damage dealt to the boss.
     * @return array Result with status, message, and coins banked.
     */
    public static function execute(int $cmid, string $token, int $gold, int $victory, int $damage): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'token'   => $token,
            'gold'    => $gold,
            'victory' => $victory,
            'damage'  => $damage,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        $isvictory = $params['victory'] === 1;
        $finalstatus = $isvictory ? 'won' : 'lost';

        $attempt = security::validate_and_consume_token(
            $params['token'],
            (int) $playerpuzzle->id,
            (int) $USER->id,
            $finalstatus
        );
        if (!$attempt) {
            // Token unknown, already consumed, or belongs to a different user/instance:
            // this is a replay or forged submission, not a coding mistake.
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        // Sanity check: damage can never exceed the boss HP the server itself calculated for
        // the attempt's own level/phase — never the raw configured base, which would wrongly
        // cap every Campaign attempt to the Level 1, Phase 1 value regardless of how far the
        // student actually progressed (Single Match always carries currentlevel = currentphase
        // = 1, so the formula returns the base HP unchanged there).
        $bosshp = combat::calculate_boss_hp(
            (int) $playerpuzzle->basebosshp,
            (int) $attempt->currentlevel,
            (int) $attempt->currentphase
        );
        $safedamage = max(0, min($params['damage'], $bosshp));
        $attempt->bosshp_remaining = max(0, $bosshp - $safedamage);
        $attempt->score = round(($safedamage / max(1, $bosshp)) * 100, 5);
        $DB->update_record('playerpuzzle_attempts', $attempt);

        $coinsbanked = 0;
        if ($isvictory) {
            // Defeat/timeout discards the session's coins; only a win banks them, and only into
            // the item the teacher configured — PlayerPuzzle keeps no local currency of its own.
            $safegold = max(0, $params['gold']);
            $blockinstanceid = hud_service::get_block_instance_id((int) $playerpuzzle->course);
            if ($blockinstanceid !== null) {
                $banked = hud_service::credit_coins(
                    $blockinstanceid,
                    (int) $USER->id,
                    (int) $playerpuzzle->hud_coin_item,
                    $safegold
                );
                $coinsbanked = $banked ? $safegold : 0;
            }
        }

        return [
            'status'      => 'success',
            'message'     => get_string('progresssaved', 'mod_playerpuzzle', $coinsbanked),
            'coinsbanked' => $coinsbanked,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'      => new external_value(PARAM_ALPHA, 'Success status'),
            'message'     => new external_value(PARAM_TEXT, 'Feedback message for the player'),
            'coinsbanked' => new external_value(PARAM_INT, 'Coins banked into PlayerHUD this session'),
        ]);
    }
}
