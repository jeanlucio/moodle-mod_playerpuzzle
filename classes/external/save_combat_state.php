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
 * External function to checkpoint the in-progress board/combat state.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playerpuzzle\local\combat_state;
use moodle_exception;

/**
 * Persists a snapshot of the current phase's fight (board grid, HP, meters, turn) so a
 * reload can resume it in place instead of always restarting the phase with full HP and a
 * fresh board.
 *
 * Called from two client-side triggers, never per board move: a periodic checkpoint (only
 * when something changed and the tab is visible) and once more on page unload/backgrounding
 * via navigator.sendBeacon(). The snapshot is never validated against combat rules here — it
 * only feeds the client's own reconstruction of its board/HUD; a win/loss claim is still
 * independently checked by save_progress/advance_phase from the server's own boss HP
 * formula, so a forged snapshot cannot buy an easier fight or a false victory.
 */
class save_combat_state extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'               => new external_value(PARAM_INT, 'Course module ID'),
            'token'              => new external_value(PARAM_ALPHANUM, 'Anti-replay token of the in-progress attempt'),
            'boardgrid'          => new external_multiple_structure(
                new external_value(PARAM_INT, 'Piece type for one board cell, 0-6'),
                'Flat 8x8 board grid, row-major'
            ),
            'currentplayerhp'    => new external_value(PARAM_INT, 'Student HP right now'),
            'currentbosshp'      => new external_value(PARAM_INT, 'Boss HP right now'),
            'playershieldmeter'  => new external_value(PARAM_INT, 'Student Escudo meter, 0-100'),
            'playershieldready'  => new external_value(PARAM_BOOL, 'Whether the student\'s next hit is blocked'),
            'playerpoisonmeter'  => new external_value(PARAM_INT, 'Student Veneno meter, 0-100'),
            'playerpoisonrounds' => new external_value(PARAM_INT, 'Student Veneno rounds remaining'),
            'playermana'         => new external_value(PARAM_INT, 'Student Mana meter, 0-100'),
            'playermultiplier'   => new external_value(PARAM_FLOAT, 'Student Estrela multiplier'),
            'bossshieldmeter'    => new external_value(PARAM_INT, 'Boss Escudo meter, 0-100'),
            'bossshieldready'    => new external_value(PARAM_BOOL, 'Whether the boss\'s next hit is blocked'),
            'bosspoisonmeter'    => new external_value(PARAM_INT, 'Boss Veneno meter, 0-100'),
            'bosspoisonrounds'   => new external_value(PARAM_INT, 'Boss Veneno rounds remaining'),
            'bossmana'           => new external_value(PARAM_INT, 'Boss Mana meter, 0-100'),
            'bossmultiplier'     => new external_value(PARAM_FLOAT, 'Boss Estrela multiplier'),
            'currentturn'        => new external_value(PARAM_ALPHA, 'Whose turn is next: player or boss'),
        ]);
    }

    /**
     * Persists the checkpoint.
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token of the in-progress attempt.
     * @param int[] $boardgrid Flat 8x8 board grid.
     * @param int $currentplayerhp Student HP right now.
     * @param int $currentbosshp Boss HP right now.
     * @param int $playershieldmeter Student Escudo meter.
     * @param bool $playershieldready Whether the student's next hit is blocked.
     * @param int $playerpoisonmeter Student Veneno meter.
     * @param int $playerpoisonrounds Student Veneno rounds remaining.
     * @param int $playermana Student Mana meter.
     * @param float $playermultiplier Student Estrela multiplier.
     * @param int $bossshieldmeter Boss Escudo meter.
     * @param bool $bossshieldready Whether the boss's next hit is blocked.
     * @param int $bosspoisonmeter Boss Veneno meter.
     * @param int $bosspoisonrounds Boss Veneno rounds remaining.
     * @param int $bossmana Boss Mana meter.
     * @param float $bossmultiplier Boss Estrela multiplier.
     * @param string $currentturn Whose turn is next.
     * @return array Result with success.
     */
    public static function execute(
        int $cmid,
        string $token,
        array $boardgrid,
        int $currentplayerhp,
        int $currentbosshp,
        int $playershieldmeter,
        bool $playershieldready,
        int $playerpoisonmeter,
        int $playerpoisonrounds,
        int $playermana,
        float $playermultiplier,
        int $bossshieldmeter,
        bool $bossshieldready,
        int $bosspoisonmeter,
        int $bosspoisonrounds,
        int $bossmana,
        float $bossmultiplier,
        string $currentturn
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'               => $cmid,
            'token'              => $token,
            'boardgrid'          => $boardgrid,
            'currentplayerhp'    => $currentplayerhp,
            'currentbosshp'      => $currentbosshp,
            'playershieldmeter'  => $playershieldmeter,
            'playershieldready'  => $playershieldready,
            'playerpoisonmeter'  => $playerpoisonmeter,
            'playerpoisonrounds' => $playerpoisonrounds,
            'playermana'         => $playermana,
            'playermultiplier'   => $playermultiplier,
            'bossshieldmeter'    => $bossshieldmeter,
            'bossshieldready'    => $bossshieldready,
            'bosspoisonmeter'    => $bosspoisonmeter,
            'bosspoisonrounds'   => $bosspoisonrounds,
            'bossmana'           => $bossmana,
            'bossmultiplier'     => $bossmultiplier,
            'currentturn'        => $currentturn,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        if (!combat_state::is_valid_board($params['boardgrid'])) {
            throw new moodle_exception('invalidcombatstate', 'mod_playerpuzzle');
        }
        if (!in_array($params['currentturn'], ['player', 'boss'], true)) {
            throw new moodle_exception('invalidcombatstate', 'mod_playerpuzzle');
        }

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);

        $attempt = $DB->get_record('playerpuzzle_attempts', [
            'token'          => $params['token'],
            'playerpuzzleid' => (int) $cm->instance,
            'userid'         => (int) $USER->id,
            'status'         => 'inprogress',
        ]);
        if (!$attempt) {
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        $meters = $params;
        unset($meters['cmid'], $meters['token'], $meters['boardgrid']);

        $attempt->combatstate = combat_state::encode($params['boardgrid'], $meters);
        $attempt->timemodified = time();
        $DB->update_record('playerpuzzle_attempts', $attempt);

        return ['success' => true];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the checkpoint was saved'),
        ]);
    }
}
