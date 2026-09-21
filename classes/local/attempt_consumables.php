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
 * Per-attempt consumable use counting, for the fixed per-phase use limit.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Counts consumable uses per attempt, one row per type actually used, checked against the
 * fixed per-phase limit each type carries (PHASE_LIMITS below).
 */
class attempt_consumables {
    /**
     * Valid consumable types. 'hint' shares this same per-attempt use-counting table with
     * the other four, even though it has no fixed phase limit of its own — see
     * PHASE_LIMITS below.
     */
    public const TYPES = ['potion', 'shield', 'magic', 'sword', 'hint'];

    /**
     * Fixed maximum uses per phase/match, by type — not a teacher-configurable setting,
     * since the split follows the mechanic itself: Shield and Quick Magic recharge on their
     * own during a match (filling a meter from board pieces), so buying more than one charge
     * ahead of time has no purpose; Potion and Sword have no meter of their own, so a
     * student may want several banked. A type absent here (only 'hint') has no fixed
     * limit — its real cap is however much stock the student bought, checked separately.
     */
    private const PHASE_LIMITS = ['shield' => 1, 'magic' => 1, 'potion' => 3, 'sword' => 3];

    /**
     * Whether a consumable type has already hit its fixed per-phase/match use limit for this
     * attempt. Always false for a type with no fixed limit (currently only 'hint') — its use
     * is bounded by owned stock instead, checked by the caller separately.
     *
     * @param int $attemptid The attempt id.
     * @param string $type One of self::TYPES.
     * @return bool
     */
    public static function phase_limit_reached(int $attemptid, string $type): bool {
        if (!array_key_exists($type, self::PHASE_LIMITS)) {
            return false;
        }

        return self::get_uses($attemptid, $type) >= self::PHASE_LIMITS[$type];
    }

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
     * Returns how many times every consumable type has already been used this attempt, in one
     * query — the bulk counterpart to get_uses(), for a caller that needs all of self::TYPES
     * at once (game_page_service::build_game_config(), reporting the whole shop's state to the
     * client on every play.php load) instead of looping get_uses() once per type.
     *
     * @param int $attemptid The attempt id.
     * @return array Type => uses so far, one key per self::TYPES (0 for a type never used).
     */
    public static function get_uses_by_type(int $attemptid): array {
        global $DB;

        $uses = array_fill_keys(self::TYPES, 0);
        $rows = $DB->get_records(
            'playerpuzzle_attempt_consumables',
            ['attemptid' => $attemptid],
            '',
            'consumabletype, timesused'
        );
        foreach ($rows as $row) {
            if (array_key_exists($row->consumabletype, $uses)) {
                $uses[$row->consumabletype] = (int) $row->timesused;
            }
        }

        return $uses;
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
     * Called by advance_phase alongside coin_ledger::reset() — the fixed use limit applies
     * per phase, not for the whole Campaign attempt, so the count must start clean at the
     * same point the coin ledger itself does.
     *
     * @param int $attemptid The attempt id.
     * @return void
     */
    public static function reset_attempt(int $attemptid): void {
        global $DB;

        $DB->delete_records('playerpuzzle_attempt_consumables', ['attemptid' => $attemptid]);
    }
}
