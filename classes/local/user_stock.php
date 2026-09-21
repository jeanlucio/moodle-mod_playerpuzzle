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
 * Per-user, per-instance consumable stock for the pre-match loadout.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tracks how many units of each consumable type a user currently owns for an instance's
 * loadout, bought before a match starts and spent one at a time during a match.
 *
 * Unlike attempt_consumables (which counts uses within a single attempt, reset every phase),
 * this stock is persistent: it survives across attempts until spent. credit()/debit() are
 * plain read-then-write, the same idiom attempt_consumables already uses — atomicity against
 * a genuinely concurrent caller is the responsibility of whoever calls them, inside a lock
 * scoped to the operation (the attempt's own lock while spending during a match, a
 * user/instance lock while buying in the Lobby).
 */
class user_stock {
    /**
     * The consumabletype value reserved for a user's PuzzleCoin balance — the currency that
     * funds the loadout shop, never itself a purchasable item. Lives in this same table
     * (same credit()/debit()/get_quantity(), same lock) rather than a dedicated table, since
     * the shape (one quantity per user+instance+type) already fits a currency balance as well
     * as it fits consumable stock.
     */
    public const CURRENCY_TYPE = 'coin';

    /**
     * Returns how many units of a consumable type the user currently owns for an instance.
     *
     * @param int $userid User ID.
     * @param int $playerpuzzleid Activity instance ID.
     * @param string $type One of attempt_consumables::TYPES.
     * @return int Units owned (0 if none).
     */
    public static function get_quantity(int $userid, int $playerpuzzleid, string $type): int {
        global $DB;

        $qty = $DB->get_field(
            'playerpuzzle_user_stock',
            'quantity',
            ['userid' => $userid, 'playerpuzzleid' => $playerpuzzleid, 'consumabletype' => $type]
        );

        return $qty === false ? 0 : (int) $qty;
    }

    /**
     * Returns the owned quantity of every consumable type for a user/instance, in one query —
     * the bulk counterpart to get_quantity(), for a caller (the Lobby shop, the game config)
     * that needs the whole loadout at once instead of looping get_quantity() once per type.
     *
     * @param int $userid User ID.
     * @param int $playerpuzzleid Activity instance ID.
     * @return array Type => quantity owned, one key per attempt_consumables::TYPES (0 for a
     *  type never bought).
     */
    public static function get_all(int $userid, int $playerpuzzleid): array {
        global $DB;

        $stock = array_fill_keys(attempt_consumables::TYPES, 0);
        $rows = $DB->get_records(
            'playerpuzzle_user_stock',
            ['userid' => $userid, 'playerpuzzleid' => $playerpuzzleid],
            '',
            'consumabletype, quantity'
        );
        foreach ($rows as $row) {
            if (array_key_exists($row->consumabletype, $stock)) {
                $stock[$row->consumabletype] = (int) $row->quantity;
            }
        }

        return $stock;
    }

    /**
     * Credits units of a consumable type to a user's stock, creating the row on first credit.
     * A no-op when $qty is not positive.
     *
     * @param int $userid User ID.
     * @param int $playerpuzzleid Activity instance ID.
     * @param string $type One of attempt_consumables::TYPES.
     * @param int $qty Units to add.
     * @return void
     */
    public static function credit(int $userid, int $playerpuzzleid, string $type, int $qty): void {
        global $DB;

        if ($qty <= 0) {
            return;
        }

        $existing = $DB->get_record(
            'playerpuzzle_user_stock',
            ['userid' => $userid, 'playerpuzzleid' => $playerpuzzleid, 'consumabletype' => $type]
        );

        if ($existing) {
            $existing->quantity = (int) $existing->quantity + $qty;
            $DB->update_record('playerpuzzle_user_stock', $existing);
            return;
        }

        $DB->insert_record('playerpuzzle_user_stock', (object) [
            'userid'         => $userid,
            'playerpuzzleid' => $playerpuzzleid,
            'consumabletype' => $type,
            'quantity'       => $qty,
        ]);
    }

    /**
     * Debits units of a consumable type from a user's stock. Refuses (and changes nothing)
     * when the user does not own enough units, or $qty is not positive.
     *
     * @param int $userid User ID.
     * @param int $playerpuzzleid Activity instance ID.
     * @param string $type One of attempt_consumables::TYPES.
     * @param int $qty Units to remove.
     * @return bool Whether the units were actually debited.
     */
    public static function debit(int $userid, int $playerpuzzleid, string $type, int $qty): bool {
        global $DB;

        if ($qty <= 0) {
            return false;
        }

        $existing = $DB->get_record(
            'playerpuzzle_user_stock',
            ['userid' => $userid, 'playerpuzzleid' => $playerpuzzleid, 'consumabletype' => $type]
        );

        if (!$existing || (int) $existing->quantity < $qty) {
            return false;
        }

        $existing->quantity = (int) $existing->quantity - $qty;
        $DB->update_record('playerpuzzle_user_stock', $existing);

        return true;
    }

    /**
     * Deletes every stock row for an instance, called by playerpuzzle_delete_instance().
     *
     * @param int $playerpuzzleid Activity instance ID.
     * @return void
     */
    public static function delete_for_instance(int $playerpuzzleid): void {
        global $DB;

        $DB->delete_records('playerpuzzle_user_stock', ['playerpuzzleid' => $playerpuzzleid]);
    }
}
