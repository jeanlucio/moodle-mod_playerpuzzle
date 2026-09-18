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
 * External function to authorize the purchase of a combat consumable.
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
use mod_playerpuzzle\local\coin_ledger;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\question_fetcher;
use mod_playerpuzzle\local\hud_service;
use moodle_exception;

/**
 * Authorizes a consumable purchase: validates the token, the per-attempt use limit, and the
 * funding source, then debits the right ledger. The purchase's actual effect (healing,
 * filling a meter, an extra attack) is never applied here — it runs entirely in combat.js,
 * once the client sees {success: true}.
 */
class buy_consumable extends external_api {
    /**
     * PlayerHUD instance fields backing the source=hud path, keyed by consumable type. Quick
     * Magic has no PlayerHUD stock item — it is local-coin-only.
     */
    private const HUD_ITEM_FIELDS = [
        'potion' => 'hud_potion_item',
        'shield' => 'hud_shield_item',
        'sword'  => 'hud_sword_item',
    ];

    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'                 => new external_value(PARAM_INT, 'Course module ID'),
            'token'                => new external_value(PARAM_ALPHANUM, 'Anti-replay token of the in-progress attempt'),
            'type'                 => new external_value(PARAM_ALPHA, 'Consumable type: potion, shield, magic, sword or hint'),
            'source'               => new external_value(PARAM_ALPHA, 'Funding source: local (coins) or hud (PlayerHUD stock)'),
            'coinsearnedsofar'     => new external_value(PARAM_INT, 'Player coins earned so far this phase/match, client-reported'),
            'bosscoinsearnedsofar' => new external_value(PARAM_INT, 'Boss coins earned so far this phase/match, client-reported'),
            'questionid'           => new external_value(
                PARAM_INT,
                'The open question, required only for type=hint',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Authorizes a consumable purchase.
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token of the in-progress attempt.
     * @param string $type Consumable type.
     * @param string $source Funding source.
     * @param int $coinsearnedsofar Player coins earned so far, client-reported.
     * @param int $bosscoinsearnedsofar Boss coins earned so far, client-reported.
     * @param int $questionid The open question, required only for type=hint.
     * @return array Result with success, newbalance, apply and hinttext.
     */
    public static function execute(
        int $cmid,
        string $token,
        string $type,
        string $source,
        int $coinsearnedsofar,
        int $bosscoinsearnedsofar,
        int $questionid = 0
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'                 => $cmid,
            'token'                => $token,
            'type'                 => $type,
            'source'               => $source,
            'coinsearnedsofar'     => $coinsearnedsofar,
            'bosscoinsearnedsofar' => $bosscoinsearnedsofar,
            'questionid'           => $questionid,
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
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        if (!in_array($params['type'], attempt_consumables::TYPES, true)) {
            throw new moodle_exception('consumabletypeinvalid', 'mod_playerpuzzle');
        }
        if (!in_array($params['source'], ['local', 'hud'], true)) {
            throw new moodle_exception('consumablesourceinvalid', 'mod_playerpuzzle');
        }

        if (attempt_consumables::get_uses((int) $attempt->id, $params['type']) >= (int) $playerpuzzle->maxconsumables) {
            throw new moodle_exception('consumablelimitreached', 'mod_playerpuzzle');
        }

        // Instance isolation, validated before any read of the hint itself: the question
        // must belong to this instance, be approved, and actually have a hint — never
        // trusted from the client, and checked before spending any coins on it.
        $hinttext = '';
        if ($params['type'] === 'hint') {
            $hinttext = question_fetcher::get_hint_text($params['questionid'], (int) $playerpuzzle->id, $context);
            if ($hinttext === null) {
                throw new moodle_exception('hintnotavailable', 'mod_playerpuzzle');
            }
        }

        $isdemo = (bool) $attempt->isdemo;
        if ($isdemo && $params['source'] === 'hud') {
            // A Demo attempt may still use the local (in-match coin) shop — that is part of
            // what it demonstrates — but never spend the student's real PlayerHUD inventory:
            // that would be a genuine economic effect from a match meant to have none.
            throw new moodle_exception('consumablesourceunavailable', 'mod_playerpuzzle');
        }

        $difficulty = (string) $attempt->difficulty;
        $level = (int) $attempt->currentlevel;
        $phase = (int) $attempt->currentphase;

        // The ceiling is a stable per-phase value (this phase's own full boss HP), not tied
        // to damage dealt so far — a student who has not yet landed a Sword hit can still have
        // genuinely earned coins from Coin/Shield/Magic matches, which happen independently on
        // the board. See combat::coin_ceiling()'s own docblock for why. A Demo attempt always
        // fought the fixed combat::DEMO_HP instead, matching what the client was shown.
        $currentbosshp = $isdemo ? combat::DEMO_HP : combat::apply_difficulty(
            combat::calculate_boss_hp((int) $playerpuzzle->basebosshp, $level, $phase),
            $difficulty
        );
        $scaledbossdamage = combat::apply_difficulty(
            combat::calculate_boss_hp((int) $playerpuzzle->bossdamage, $level, $phase),
            $difficulty
        );
        $ceiling = combat::coin_ceiling(
            $currentbosshp,
            $scaledbossdamage,
            (int) $playerpuzzle->coingain,
            combat::difficulty_coin_factor($difficulty)
        );
        coin_ledger::sync($attempt, $params['coinsearnedsofar'], $params['bosscoinsearnedsofar'], $ceiling);

        if ($params['source'] === 'local') {
            $price = combat::consumable_price($params['type']);
            if (coin_ledger::spendable($attempt) < $price) {
                throw new moodle_exception('insufficientcoins', 'mod_playerpuzzle');
            }
            $attempt->coins_spent = (int) $attempt->coins_spent + $price;
        } else {
            if (!array_key_exists($params['type'], self::HUD_ITEM_FIELDS)) {
                throw new moodle_exception('consumablesourceunavailable', 'mod_playerpuzzle');
            }
            $itemfield = self::HUD_ITEM_FIELDS[$params['type']];
            $itemid = (int) $playerpuzzle->{$itemfield};
            $blockinstanceid = hud_service::get_block_instance_id((int) $playerpuzzle->course);
            if ($itemid <= 0 || $blockinstanceid === null) {
                throw new moodle_exception('consumablesourceunavailable', 'mod_playerpuzzle');
            }
            if (!hud_service::consume_item($blockinstanceid, (int) $USER->id, $itemid, 1)) {
                throw new moodle_exception('insufficienthudstock', 'mod_playerpuzzle');
            }
        }

        attempt_consumables::record_use((int) $attempt->id, $params['type']);
        $attempt->timemodified = time();
        $DB->update_record('playerpuzzle_attempts', $attempt);

        return [
            'success'    => true,
            'newbalance' => coin_ledger::spendable($attempt),
            'apply'      => $params['type'],
            'hinttext'   => $hinttext,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, 'Whether the purchase was authorized'),
            'newbalance' => new external_value(PARAM_INT, 'Local coin balance available after this purchase'),
            'apply'      => new external_value(PARAM_ALPHA, 'The consumable type the client should now apply'),
            'hinttext'   => new external_value(PARAM_RAW, 'Formatted hint text, only populated for type=hint'),
        ]);
    }
}
