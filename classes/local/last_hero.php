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
 * The hero a user chose last for a Single Match.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Remembers, per user and across every activity, the hero they last chose for a Single Match, so the
 * choice screen opens with it marked. Kept in a user preference, like seen_opponents: it is interface
 * state, not game data, and db/uninstall.php already clears every mod_playerpuzzle_ preference.
 */
class last_hero {
    /** @var string The user preference holding the hero key. */
    public const PREFERENCE = 'mod_playerpuzzle_lasthero';

    /**
     * The hero a user chose last.
     *
     * @param int $userid User id.
     * @return string A key of heroes::CATALOG, or '' when there is none or it is no longer a hero.
     */
    public static function get(int $userid): string {
        $stored = (string) get_user_preferences(self::PREFERENCE, '', $userid);

        return heroes::is_valid($stored) ? $stored : '';
    }

    /**
     * Records the hero a user chose.
     *
     * @param int $userid User id.
     * @param string $hero A key of heroes::CATALOG; anything else is ignored.
     * @return void
     */
    public static function set(int $userid, string $hero): void {
        if (heroes::is_valid($hero)) {
            set_user_preference(self::PREFERENCE, $hero, $userid);
        }
    }
}
