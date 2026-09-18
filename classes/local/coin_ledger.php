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
 * Server-side coin ledger for the current phase/match window.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use stdClass;

/**
 * Reconciles the coin amounts a client reports (coinsearnedsofar/bosscoinsearnedsofar)
 * against an attempt's own ledger columns (coins_earned, boss_coins_earned, coins_spent).
 *
 * The ledger window is the current phase (Campaign) or the current match (Single Match) —
 * buy_consumable and, at the end of a phase/match, save_progress/advance_phase all sync the
 * same three columns, then advance_phase resets them once its own phase payout is banked, so
 * the next phase starts a clean window. None of this mutates the database itself; callers
 * persist the attempt alongside whatever else they change in the same update_record() call.
 */
class coin_ledger {
    /**
     * Ratchets coins_earned/boss_coins_earned forward from a client report, each capped by
     * the same plausibility ceiling — never allowed to decrease, and never trusted past what
     * the reported damage makes plausible.
     *
     * @param stdClass $attempt The attempt row (coins_earned/boss_coins_earned read and
     *  written in place; caller persists).
     * @param int $reportedearned Client-reported coinsearnedsofar this phase/match.
     * @param int $reportedbossearned Client-reported bosscoinsearnedsofar this phase/match.
     * @param int $ceiling Plausibility ceiling from combat::coin_ceiling(), applied to both
     *  sides — the boss's own combat output is symmetric to the student's, by design.
     * @return void
     */
    public static function sync(stdClass $attempt, int $reportedearned, int $reportedbossearned, int $ceiling): void {
        $attempt->coins_earned = max(
            (int) $attempt->coins_earned,
            min(max(0, $reportedearned), $ceiling)
        );
        $attempt->boss_coins_earned = max(
            (int) $attempt->boss_coins_earned,
            min(max(0, $reportedbossearned), $ceiling)
        );
    }

    /**
     * The final reward this window pays out: gross earned, minus the boss's own share,
     * minus whatever has already been spent on consumables. Never negative. Used only for
     * the actual payout at the end of a phase/match (save_progress.php/advance_phase.php,
     * crediting hud_coin_item) — never to gate a purchase mid-match, which is what
     * spendable() below is for.
     *
     * @param stdClass $attempt The attempt row.
     * @return int
     */
    public static function available(stdClass $attempt): int {
        $net = max(0, (int) $attempt->coins_earned - (int) $attempt->boss_coins_earned);

        return max(0, $net - (int) $attempt->coins_spent);
    }

    /**
     * The amount actually spendable on a consumable right now, mid-match: the student's own
     * gross earnings, minus whatever has already been spent this window. Never negative.
     *
     * Deliberately does not subtract boss_coins_earned the way available() does. The boss's
     * own coin gains (from it combining Coin pieces on its own turns, per the symmetric
     * effect system) were always meant to net against the student's total only in the final
     * reward at the end of a phase/match, never against mid-match spending power — but
     * buy_consumable.php used to call available() for its purchase gate too, which meant a
     * boss that had simply been matching Coin pieces on its own turns could silently block
     * the student from spending coins the student had genuinely and separately earned,
     * mid-fight — coins shown on screen, both consumables greyed out, no damage dealt yet,
     * because the boss's own coin total was being subtracted from spending power it was
     * never meant to affect.
     *
     * @param stdClass $attempt The attempt row.
     * @return int
     */
    public static function spendable(stdClass $attempt): int {
        return max(0, (int) $attempt->coins_earned - (int) $attempt->coins_spent);
    }

    /**
     * Clears the ledger window, once its payout has been banked — called by advance_phase
     * right before a Campaign attempt moves on to its next phase, so that phase starts with
     * a clean coins_earned/boss_coins_earned/coins_spent of 0.
     *
     * @param stdClass $attempt The attempt row (written in place; caller persists).
     * @return void
     */
    public static function reset(stdClass $attempt): void {
        $attempt->coins_earned = 0;
        $attempt->boss_coins_earned = 0;
        $attempt->coins_spent = 0;
    }
}
