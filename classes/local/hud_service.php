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
 * PlayerPuzzle has no permanent economy of its own: a teacher who has not added a
 * block_playerhud instance to the course gets no persistent progression at all
 * (only the per-attempt tactical balance spent on in-game consumables, which never touches
 * this class). Where PlayerHUD is available, the teacher picks, per instance, which of the
 * block's own items stand in for "coins", "sword level", and "shield level" — the same
 * teacher-picked-dropdown pattern already used by mod_playerwords/mod_playercross, so adding
 * this integration never requires a change to block_playerhud itself. PlayerHUD's own
 * auto-generated PlayerCoin item is simply one of the options in that dropdown, like any
 * other item the teacher could pick or create.
 */
class hud_service {
    /** @var string The class of PlayerHUD's public item API. */
    private const ITEMS_API = '\\block_playerhud\\local\\external_items';

    /** @var bool In PHPUnit only: behave as if PlayerHUD were older than its batch item functions. */
    private static bool $forcelegacyapi = false;

    /**
     * Whether PlayerHUD's item API has the given method. Each method is checked on its own: the API grew over several
     * releases, and an older PlayerHUD still accepted by is_installed() lacks the newest ones.
     *
     * @param string $method Method of \block_playerhud\local\external_items.
     * @return bool
     */
    private static function api_has(string $method): bool {
        return !self::$forcelegacyapi && method_exists(self::ITEMS_API, $method);
    }

    /**
     * Makes this class behave, in PHPUnit, as if PlayerHUD lacked its batch item functions, so the path an older
     * PlayerHUD takes can be tested on a site that has the newest one.
     *
     * @param bool $legacy Whether to hide them.
     * @return void
     * @throws \coding_exception Outside PHPUnit.
     */
    public static function force_legacy_api_for_tests(bool $legacy): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('force_legacy_api_for_tests() is for PHPUnit only');
        }
        self::$forcelegacyapi = $legacy;
    }
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
     * Whether block_playerhud is installed on this site at a version older than the v1.7.1
     * floor is_installed() requires — i.e. \block_playerhud\game exists but
     * \block_playerhud\local\external_items does not.
     *
     * Lets settings.php tell an admin who already installed PlayerHUD that their version is
     * too old, instead of the misleading "the plugin is not installed" message is_installed()
     * alone would otherwise imply.
     *
     * @return bool
     */
    public static function is_outdated(): bool {
        return class_exists('\block_playerhud\game') && !self::is_installed();
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
     * Returns enabled items for a block instance, sorted by name: the ones a grant can still award.
     *
     * Read through PlayerHUD's own API when it has get_enabled_names(). An older PlayerHUD has no such function, so its
     * table is read instead, and a change to that table only leaves the list empty rather than breaking the activity's
     * settings form.
     *
     * @param int $blockinstanceid Block instance ID.
     * @return \stdClass[] Array of objects with id and name fields.
     */
    public static function get_items_for_block(int $blockinstanceid): array {
        global $DB;

        if (self::api_has('get_enabled_names')) {
            $items = [];
            $api = self::ITEMS_API;
            foreach ($api::get_enabled_names($blockinstanceid) as $id => $name) {
                $items[] = (object) ['id' => (int) $id, 'name' => $name];
            }
            return $items;
        }
        try {
            return array_values($DB->get_records(
                'block_playerhud_items',
                ['blockinstanceid' => $blockinstanceid, 'enabled' => 1],
                'name ASC',
                'id, name'
            ));
        } catch (\dml_exception $e) {
            return [];
        }
    }

    /**
     * Returns the plain-text name of an item, for a template or a lang string that escapes it once, or empty string
     * if it does not belong to $blockinstanceid.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $itemid PlayerHUD item ID.
     * @return string
     */
    public static function get_item_name_plain(int $blockinstanceid, int $itemid): string {
        if (!self::is_installed()) {
            return '';
        }
        return \block_playerhud\local\external_items::get_name_plain($blockinstanceid, $itemid);
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
     * PlayerHUD is not installed — all three collapse to "no upgrade", the base multiplier
     * for a student with no PlayerHUD progression.
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
     * Consumes $qty units of a configured PlayerHUD item — the `source=hud` path of a
     * consumable purchase, spending stock instead of local session coins. Delegates to
     * external_items::consume(), which holds the same atomic per-item/user lock grant() uses.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $userid User ID.
     * @param int $itemid PlayerHUD item ID configured for this consumable.
     * @param int $qty Number of units to consume.
     * @return bool|null True when the units were consumed; false when they could not be
     *  (unconfigured, PlayerHUD unavailable, or insufficient stock); null when the item no
     *  longer belongs to this block (deleted, or the block was replaced), which can never be
     *  paid, so the caller should waive the cost instead of blocking the student forever.
     */
    public static function consume_item(int $blockinstanceid, int $userid, int $itemid, int $qty): ?bool {
        if (!self::is_installed() || $itemid <= 0 || $qty <= 0) {
            return false;
        }
        return \block_playerhud\local\external_items::consume($blockinstanceid, $itemid, $userid, $qty, 'playerpuzzle');
    }

    /**
     * Grants $qty units of the configured victory-reward item to $userid, separate from the
     * coin balance. A no-op when the item is unconfigured or PlayerHUD is unavailable.
     *
     * $suppressxp withholds the item's own XP value even though it is granted — mirroring
     * block_playerhud's own "infinite drop gives no XP" anti-farming rule, replicated here
     * (same pattern as mod_playerwords's own win grant) because this call never goes through
     * a real PlayerHUD drop, so the block has no way to know on its own whether the caller
     * represents an unbounded source; the caller (an Unlimited maxattempts/max_single_matches)
     * decides that and passes it in.
     *
     * @param int $blockinstanceid Block instance ID the item must belong to.
     * @param int $userid User ID.
     * @param int $itemid PlayerHUD item ID configured as the win-grant item.
     * @param int $qty Number of items to grant.
     * @param bool $suppressxp Whether to withhold the item's XP even though it was granted.
     * @return void
     */
    public static function grant_item(int $blockinstanceid, int $userid, int $itemid, int $qty, bool $suppressxp): void {
        if (!self::is_installed() || $itemid <= 0 || $qty <= 0) {
            return;
        }
        \block_playerhud\local\external_items::grant($blockinstanceid, $itemid, $userid, $qty, 'playerpuzzle', $suppressxp);
    }

    /**
     * Resolves the XP value of several items at once, keyed by item id. Items that do not belong to
     * $blockinstanceid (deleted, or configured for another course) or are disabled, so granting them awards
     * nothing, are absent from the map: a caller treats a missing key as zero.
     *
     * @param int $blockinstanceid Block instance ID every item must belong to.
     * @param int[] $itemids Item IDs to resolve.
     * @return array Item id => XP value.
     */
    public static function get_xp_for_items(int $blockinstanceid, array $itemids): array {
        global $DB;

        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        if ($itemids === []) {
            return [];
        }
        if (self::api_has('get_enabled_xp')) {
            $api = self::ITEMS_API;
            return $api::get_enabled_xp($blockinstanceid, $itemids);
        }

        // An older PlayerHUD has no batch function: its table is read instead, and a change to that table only leaves
        // the estimate without this activity's XP rather than breaking it.
        [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $params['blockinstanceid'] = $blockinstanceid;
        try {
            $records = $DB->get_records_select(
                'block_playerhud_items',
                "blockinstanceid = :blockinstanceid AND enabled = 1 AND id $insql",
                $params,
                '',
                'id, xp'
            );
        } catch (\dml_exception $e) {
            return [];
        }

        $map = [];
        foreach ($records as $record) {
            $map[(int) $record->id] = (int) $record->xp;
        }

        return $map;
    }
}
