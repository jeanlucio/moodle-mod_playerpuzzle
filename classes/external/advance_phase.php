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
 * External function to advance a Campaign attempt to its next phase.
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
use mod_playerpuzzle\local\hud_service;
use moodle_exception;

/**
 * Advances a Campaign attempt to its next phase (or next level, every 10th phase) after
 * the boss for the current phase has genuinely been defeated.
 *
 * Unlike save_progress, the attempt is never finalised here: it stays 'inprogress' and
 * the same row continues — winning a phase never opens a new attempt. The token is
 * rotated on every call (the old one becomes invalid immediately), the same
 * anti-replay guarantee save_progress gives its own final submission, so a captured
 * advance_phase request cannot be replayed to skip ahead a second time.
 */
class advance_phase extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course module ID'),
            'token'  => new external_value(PARAM_ALPHANUM, 'Anti-replay token issued when the attempt started'),
            'damage' => new external_value(PARAM_INT, 'Damage dealt to the boss this phase'),
            'gold'   => new external_value(PARAM_INT, 'Gold coins earned this phase'),
        ]);
    }

    /**
     * Validates the phase was genuinely won, advances the attempt to its next phase (or
     * level), banks this phase's coins, and returns the scaled HP for the new phase
     * together with a fresh token.
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token issued when the attempt started.
     * @param int $damage Damage dealt to the boss this phase.
     * @param int $gold Gold coins earned this phase.
     * @return array Result with the new token, level, phase, scaled boss/student HP, and coins banked.
     */
    public static function execute(int $cmid, string $token, int $damage, int $gold): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'   => $cmid,
            'token'  => $token,
            'damage' => $damage,
            'gold'   => $gold,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        $attempt = $DB->get_record('playerpuzzle_attempts', [
            'token'          => $params['token'],
            'playerpuzzleid' => (int) $playerpuzzle->id,
            'userid'         => (int) $USER->id,
            'status'         => 'inprogress',
        ]);
        if (!$attempt) {
            // Token unknown, already rotated/consumed, or belongs to a different
            // user/instance: a replay or forged submission, not a coding mistake.
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        $currentlevel = (int) $attempt->currentlevel;
        $currentphase = (int) $attempt->currentphase;

        // Sanity check: the client cannot simply claim victory — the reported damage
        // must genuinely clear the boss HP the server itself calculated for the phase
        // being left, or advancing is refused outright.
        $currentbosshp = combat::calculate_boss_hp((int) $playerpuzzle->basebosshp, $currentlevel, $currentphase);
        if ($params['damage'] < $currentbosshp) {
            throw new moodle_exception('phasenotwon', 'mod_playerpuzzle');
        }

        if ($currentphase < 10) {
            $newlevel = $currentlevel;
            $newphase = $currentphase + 1;
        } else if ($currentlevel < (int) $playerpuzzle->maxlevels) {
            $newlevel = $currentlevel + 1;
            $newphase = 1;
        } else {
            // Already at the last phase of the last level: nothing left to advance to.
            // The client must call save_progress with victory instead, to finish the
            // whole campaign, not this endpoint.
            throw new moodle_exception('nonextphase', 'mod_playerpuzzle');
        }

        $newtoken = bin2hex(random_bytes(32));
        $attempt->token = $newtoken;
        $attempt->currentlevel = $newlevel;
        $attempt->currentphase = $newphase;
        $attempt->timemodified = time();
        $DB->update_record('playerpuzzle_attempts', $attempt);

        // Coins are banked per phase won, not only at the end of the whole campaign — a
        // student clearing several phases before eventually losing still keeps what they
        // earned along the way, mirroring save_progress's own victory banking below.
        $coinsbanked = 0;
        $safegold = max(0, $params['gold']);
        if ($safegold > 0) {
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
            'token'        => $newtoken,
            'currentlevel' => $newlevel,
            'currentphase' => $newphase,
            'bosshp'       => combat::calculate_boss_hp((int) $playerpuzzle->basebosshp, $newlevel, $newphase),
            'studenthp'    => combat::calculate_student_hp((int) $playerpuzzle->basestudenthp, $newlevel, $newphase),
            'coinsbanked'  => $coinsbanked,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token'        => new external_value(PARAM_ALPHANUM, 'New anti-replay token for the advanced phase'),
            'currentlevel' => new external_value(PARAM_INT, 'Level the attempt is now on'),
            'currentphase' => new external_value(PARAM_INT, 'Phase the attempt is now on'),
            'bosshp'       => new external_value(PARAM_INT, 'Scaled boss HP for the new phase'),
            'studenthp'    => new external_value(PARAM_INT, 'Scaled student HP for the new phase'),
            'coinsbanked'  => new external_value(PARAM_INT, 'Coins banked into PlayerHUD for this phase'),
        ]);
    }
}
