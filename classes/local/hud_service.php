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
 * PlayerHUD integration helpers for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Provides coin and upgrade-level integration between mod_playerpuzzle and block_playerhud.
 *
 * PlayerPuzzle has no permanent economy of its own: SCOPE.md decides that a teacher who has
 * not added a block_playerhud instance to the course gets no persistent progression at all
 * (only the per-attempt tactical balance spent on in-game consumables, which never touches
 * this class). Where PlayerHUD is available, the teacher picks, per instance, which of the
 * block's own items stand in for "coins", "sword level", and "shield level" — the same
 * teacher-picked-dropdown pattern already used by mod_playerwords/mod_playercross, so adding
 * this integration never requires a change to block_playerhud itself. PlayerHUD's own
 * auto-generated PlayerCoin item is simply one of the options in that dropdown, like any
 * other item the teacher could pick or create.
 */
class hud_service {
    /**
     * Returns the PlayerHUD block instance ID for the given course, or null if none.
     *
     * @param int $courseid Course ID.
     * @return int|null
     */
    public static function get_block_instance_id(int $courseid): ?int {
        global $DB;

        $sql = "SELECT bi.id
                  FROM {block_instances} bi
                  JOIN {context} ctx ON bi.parentcontextid = ctx.id
                 WHERE bi.blockname = 'playerhud'
                   AND ctx.contextlevel = :clevel
                   AND ctx.instanceid  = :courseid";

        $id = $DB->get_field_sql($sql, [
            'clevel'   => CONTEXT_COURSE,
            'courseid' => $courseid,
        ]);

        return ($id !== false) ? (int) $id : null;
    }

    /**
     * Whether the block_playerhud plugin is installed on this site at a version that exposes
     * the item API this class calls.
     *
     * @return bool
     */
    public static function is_installed(): bool {
        return class_exists('\block_playerhud\local\external_items');
    }

    /**
     * Whether PlayerHUD integration should be offered for this course: the block plugin must
     * be installed, and a block_playerhud instance must actually exist at course level.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public static function is_available_for_course(int $courseid): bool {
        return self::is_installed() && self::get_block_instance_id($courseid) !== null;
    }

    /**
     * Returns enabled items for a block instance, sorted by name.
     *
     * @param int $blockinstanceid Block instance ID.
     * @return \stdClass[] Array of objects with id and name fields.
     */
    public static function get_items_for_block(int $blockinstanceid): array {
        global $DB;
        return array_values($DB->get_records(
            'block_playerhud_items',
            ['blockinstanceid' => $blockinstanceid, 'enabled' => 1],
            'name ASC',
            'id, name'
        ));
    }

    /**
     * Returns the formatted display name of an item, or empty string if it does not belong to
     * $blockinstanceid.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $itemid PlayerHUD item ID.
     * @return string
     */
    public static function get_item_name(int $blockinstanceid, int $itemid): string {
        if (!self::is_installed()) {
            return '';
        }
        return \block_playerhud\local\external_items::get_name($blockinstanceid, $itemid);
    }

    /**
     * Returns how many units of $itemid the user currently holds, read as an upgrade tier
     * (e.g. "Sword level 3" means the student holds 3 units of the configured sword item).
     *
     * Zero when the item is unconfigured (id 0), does not belong to $blockinstanceid, or
     * PlayerHUD is not installed — all three collapse to "no upgrade", matching SCOPE.md's
     * base multiplier for a student with no PlayerHUD progression.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $userid User ID.
     * @param int $itemid PlayerHUD item ID configured as the upgrade item.
     * @return int
     */
    public static function get_upgrade_level(int $blockinstanceid, int $userid, int $itemid): int {
        if (!self::is_installed() || $itemid <= 0) {
            return 0;
        }
        return \block_playerhud\local\external_items::get_available_quantity($blockinstanceid, $itemid, $userid);
    }

    /**
     * Grants $qty units of the configured "coin" item to $userid — the leftover per-attempt
     * balance banked on victory. Returns false without granting anything when the item is
     * unconfigured, PlayerHUD is unavailable, or there is nothing to grant.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $userid User ID.
     * @param int $itemid PlayerHUD item ID configured as the coin item.
     * @param int $qty Number of coins to grant.
     * @return bool Whether the coins were actually banked.
     */
    public static function credit_coins(int $blockinstanceid, int $userid, int $itemid, int $qty): bool {
        if (!self::is_installed() || $itemid <= 0 || $qty <= 0) {
            return false;
        }
        \block_playerhud\local\external_items::grant($blockinstanceid, $itemid, $userid, $qty, 'playerpuzzle', false);
        return true;
    }
}
