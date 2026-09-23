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
 * Keeps an attempt's own coin ledger columns (coins_earned, boss_coins_earned) — the totals
 * replay_credit::verdict() credits for a finished phase, never a raw client report.
 *
 * The ledger window is the current phase (Campaign) or the current match (Single Match) —
 * at the end of a phase/match, save_progress/advance_phase sync both columns, then
 * advance_phase resets them once its own phase payout is banked, so the next phase starts a
 * clean window. None of this mutates the database itself; callers persist the attempt
 * alongside whatever else they change in the same update_record() call.
 */
class coin_ledger {
    /**
     * Ratchets coins_earned/boss_coins_earned forward to the phase's verified totals — never
     * allowed to decrease.
     *
     * @param stdClass $attempt The attempt row (coins_earned/boss_coins_earned read and
     *  written in place; caller persists).
     * @param int $earned Player coins earned this phase/match, as credited by
     *  replay_credit::verdict().
     * @param int $bossearned Boss coins earned this phase/match, same source.
     * @return void
     */
    public static function sync(stdClass $attempt, int $earned, int $bossearned): void {
        $attempt->coins_earned = max((int) $attempt->coins_earned, max(0, $earned));
        $attempt->boss_coins_earned = max((int) $attempt->boss_coins_earned, max(0, $bossearned));
    }

    /**
     * The final reward this window pays out: gross earned, minus the boss's own share.
     * Never negative. Used at the end of a phase/match (save_progress.php/
     * advance_phase.php, crediting PuzzleCoin).
     *
     * @param stdClass $attempt The attempt row.
     * @return int
     */
    public static function available(stdClass $attempt): int {
        return max(0, (int) $attempt->coins_earned - (int) $attempt->boss_coins_earned);
    }

    /**
     * Clears the ledger window, once its payout has been banked — called by advance_phase
     * right before a Campaign attempt moves on to its next phase, so that phase starts with
     * a clean coins_earned/boss_coins_earned of 0.
     *
     * @param stdClass $attempt The attempt row (written in place; caller persists).
     * @return void
     */
    public static function reset(stdClass $attempt): void {
        $attempt->coins_earned = 0;
        $attempt->boss_coins_earned = 0;
    }
}
