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
 * Per-attempt consumable use counting, for the maxconsumables limit.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Counts consumable uses per attempt, one row per type actually used. The limit counts
 * uses regardless of source — bringing PlayerHUD stock into a match never raises the
 * per-attempt cap a teacher configured.
 */
class attempt_consumables {
    /**
     * Valid consumable types. 'hint' (Question Hint) shares this same per-attempt use-limit
     * table with the other four, even though it is bought from inside the question modal
     * rather than the side-panel shop.
     */
    public const TYPES = ['potion', 'shield', 'magic', 'sword', 'hint'];

    /**
     * Returns how many times a consumable type has already been used this attempt.
     *
     * @param int $attemptid The attempt id.
     * @param string $type One of self::TYPES.
     * @return int Uses so far (0 if never used).
     */
    public static function get_uses(int $attemptid, string $type): int {
        global $DB;

        $uses = $DB->get_field(
            'playerpuzzle_attempt_consumables',
            'timesused',
            ['attemptid' => $attemptid, 'consumabletype' => $type]
        );

        return $uses === false ? 0 : (int) $uses;
    }

    /**
     * Records one more use of a consumable type for an attempt, creating the row on first
     * use.
     *
     * @param int $attemptid The attempt id.
     * @param string $type One of self::TYPES.
     * @return void
     */
    public static function record_use(int $attemptid, string $type): void {
        global $DB;

        $existing = $DB->get_record(
            'playerpuzzle_attempt_consumables',
            ['attemptid' => $attemptid, 'consumabletype' => $type]
        );

        if ($existing) {
            $existing->timesused = (int) $existing->timesused + 1;
            $DB->update_record('playerpuzzle_attempt_consumables', $existing);
            return;
        }

        $DB->insert_record('playerpuzzle_attempt_consumables', (object) [
            'attemptid'      => $attemptid,
            'consumabletype' => $type,
            'timesused'      => 1,
        ]);
    }

    /**
     * Clears every recorded use for an attempt, once its phase's shop window has closed.
     * Called by advance_phase alongside coin_ledger::reset() — maxconsumables limits
     * purchases per phase, not for the whole Campaign attempt, so the count must start
     * clean at the same point the coin ledger itself does.
     *
     * @param int $attemptid The attempt id.
     * @return void
     */
    public static function reset_attempt(int $attemptid): void {
        global $DB;

        $DB->delete_records('playerpuzzle_attempt_consumables', ['attemptid' => $attemptid]);
    }
}
