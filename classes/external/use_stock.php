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
 * External function to use one unit of loadout stock during a match.
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
use mod_playerpuzzle\local\attempt_consumables;
use mod_playerpuzzle\local\engine\question_fetcher;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\user_stock;
use moodle_exception;

/**
 * Consumes 1 unit of an already-owned consumable type during a match, applying the
 * attempt's own fixed per-phase use limit. There is no purchase here — stock is only ever
 * bought pre-match, in the Lobby (buy_stock.php); this is the only place it is spent.
 *
 * A Demo attempt never touches the real stock table at all: playerpuzzle_user_stock is
 * keyed by user+instance, not by attempt, so debiting it during a disposable practice fight
 * would eat into the same balance a real Campaign attempt paid real coins for. The per-phase
 * limit is still enforced (attempt_consumables is scoped per attempt already), so a Demo
 * cannot be spammed without bound, but using a type never costs the student anything real.
 */
class use_stock extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'       => new external_value(PARAM_INT, 'Course module ID'),
            'token'      => new external_value(PARAM_ALPHANUM, 'Anti-replay token of the in-progress attempt'),
            'type'       => new external_value(PARAM_ALPHA, 'Consumable type: potion, shield, magic, sword or hint'),
            'questionid' => new external_value(
                PARAM_INT,
                'The open question, required only for type=hint',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Uses 1 unit of loadout stock.
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token of the in-progress attempt.
     * @param string $type Consumable type.
     * @param int $questionid The open question, required only for type=hint.
     * @return array Result with success, newquantity and hinttext.
     */
    public static function execute(int $cmid, string $token, string $type, int $questionid = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'token'      => $token,
            'type'       => $type,
            'questionid' => $questionid,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        if (!in_array($params['type'], attempt_consumables::TYPES, true)) {
            throw new moodle_exception('consumabletypeinvalid', 'mod_playerpuzzle');
        }

        // The whole use (limit check through to debiting stock) runs inside the attempt's
        // own lock — see security::with_locked_attempt()'s own docblock for why.
        $result = security::with_locked_attempt(
            $params['token'],
            (int) $playerpuzzle->id,
            (int) $USER->id,
            function (\stdClass $attempt) use ($USER, $playerpuzzle, $context, $params): array {
                if (attempt_consumables::phase_limit_reached((int) $attempt->id, $params['type'])) {
                    throw new moodle_exception('consumablelimitreached', 'mod_playerpuzzle');
                }

                // Instance isolation, validated before any read of the hint itself: the
                // question must belong to this instance, be approved, and actually have a
                // hint — never trusted from the client.
                $hinttext = '';
                if ($params['type'] === 'hint') {
                    $hinttext = question_fetcher::get_hint_text(
                        $params['questionid'],
                        (int) $playerpuzzle->id,
                        $context
                    );
                    if ($hinttext === null) {
                        throw new moodle_exception('hintnotavailable', 'mod_playerpuzzle');
                    }
                }

                $isdemo = (bool) $attempt->isdemo;
                if ($isdemo) {
                    // Free to use, never touches the real stock table — see this class's
                    // own docblock.
                    $newquantity = user_stock::get_quantity(
                        (int) $USER->id,
                        (int) $playerpuzzle->id,
                        $params['type']
                    );
                } else {
                    $debited = user_stock::debit((int) $USER->id, (int) $playerpuzzle->id, $params['type'], 1);
                    if (!$debited) {
                        throw new moodle_exception('insufficientstock', 'mod_playerpuzzle');
                    }
                    $newquantity = user_stock::get_quantity(
                        (int) $USER->id,
                        (int) $playerpuzzle->id,
                        $params['type']
                    );
                }

                attempt_consumables::record_use((int) $attempt->id, $params['type']);

                return [
                    'success'     => true,
                    'newquantity' => $newquantity,
                    'hinttext'    => $hinttext,
                ];
            }
        );

        if ($result === false) {
            // Token unknown, already rotated/consumed, or belongs to a different
            // user/instance: a replay or forged submission, not a coding mistake.
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        return $result;
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'     => new external_value(PARAM_BOOL, 'Whether the use was authorized'),
            'newquantity' => new external_value(PARAM_INT, 'Units owned of this type after the use'),
            'hinttext'    => new external_value(PARAM_RAW, 'Formatted hint text, only populated for type=hint'),
        ]);
    }
}
