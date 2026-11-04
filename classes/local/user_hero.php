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
 * The hero a student plays in a Campaign: which one, its stage and what they have bought.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use stdClass;

/**
 * One row for each student, activity and Campaign: the student picks the hero when the Campaign starts and
 * keeps it to the end, through defeats and retries. The next Campaign gets a row of its own, starting from
 * nothing (see campaigns). Single Match and Demo heroes are only a look and keep no row.
 */
class user_hero {
    /**
     * The hero of a student's Campaign.
     *
     * @param int $playerpuzzleid Activity instance ID.
     * @param int $userid User ID.
     * @param int $campaign Campaign number.
     * @return stdClass|null The row (hero, stage, hpbuys, attackbuys, armorbuys), or null before one is chosen.
     */
    public static function get(int $playerpuzzleid, int $userid, int $campaign): ?stdClass {
        global $DB;

        $row = $DB->get_record(
            'playerpuzzle_heroes',
            ['playerpuzzleid' => $playerpuzzleid, 'userid' => $userid, 'campaign' => $campaign]
        );

        return $row ?: null;
    }

    /**
     * The hero of the student's most recent Campaign that has one, for showing and pre-selecting it when the
     * next Campaign is still to be started.
     *
     * @param int $playerpuzzleid Activity instance ID.
     * @param int $userid User ID.
     * @return stdClass|null The row, or null when the student has never had a Campaign hero.
     */
    public static function latest(int $playerpuzzleid, int $userid): ?stdClass {
        global $DB;

        $rows = $DB->get_records(
            'playerpuzzle_heroes',
            ['playerpuzzleid' => $playerpuzzleid, 'userid' => $userid],
            'campaign DESC',
            '*',
            0,
            1
        );

        return $rows ? reset($rows) : null;
    }

    /**
     * Chooses the hero of a Campaign: stage 1, nothing bought. Choosing again in the same Campaign changes
     * nothing, so a double submit never swaps a hero that already has purchases.
     *
     * @param int $playerpuzzleid Activity instance ID.
     * @param int $userid User ID.
     * @param int $campaign Campaign number.
     * @param string $hero A key of heroes::CATALOG.
     * @return stdClass The Campaign's hero row, the existing one if there already was one.
     * @throws \coding_exception When the key is not a hero.
     */
    public static function choose(int $playerpuzzleid, int $userid, int $campaign, string $hero): stdClass {
        global $DB;

        if (!heroes::is_valid($hero)) {
            throw new \coding_exception('Unknown hero: ' . $hero);
        }
        $existing = self::get($playerpuzzleid, $userid, $campaign);
        if ($existing) {
            return $existing;
        }

        $now = time();
        $row = (object) [
            'playerpuzzleid' => $playerpuzzleid,
            'userid' => $userid,
            'campaign' => $campaign,
            'hero' => $hero,
            'stage' => 1,
            'hpbuys' => 0,
            'attackbuys' => 0,
            'armorbuys' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $row->id = $DB->insert_record('playerpuzzle_heroes', $row);

        return $row;
    }

    /**
     * What a hero row has bought, keyed by attribute, in the shape the rules take.
     *
     * @param stdClass $row A playerpuzzle_heroes row.
     * @return array
     */
    public static function buys(stdClass $row): array {
        return [
            heroes::HP => (int) $row->hpbuys,
            heroes::ATTACK => (int) $row->attackbuys,
            heroes::ARMOR => (int) $row->armorbuys,
        ];
    }

    /**
     * Deletes every hero of an activity, called by playerpuzzle_delete_instance().
     *
     * @param int $playerpuzzleid Activity instance ID.
     * @return void
     */
    public static function delete_for_instance(int $playerpuzzleid): void {
        global $DB;

        $DB->delete_records('playerpuzzle_heroes', ['playerpuzzleid' => $playerpuzzleid]);
    }
}
