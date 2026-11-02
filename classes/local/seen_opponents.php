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
 * Service tracking which opponents each user has already faced.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Remembers, per user and across every activity, the opponents they have already faced, so the
 * balloon explaining an opponent's powers shows only the first time they meet it. Kept in a
 * single user preference holding the opponent keys, like the sound preferences: it is interface
 * state, not game data, and db/uninstall.php already clears every mod_playerpuzzle_ preference.
 */
class seen_opponents {
    /** @var string The user preference holding the comma-separated opponent keys. */
    public const PREFERENCE = 'mod_playerpuzzle_seenopponents';

    /**
     * The opponents a user has already faced.
     *
     * @param int $userid User id.
     * @return string[] Opponent keys.
     */
    public static function seen(int $userid): array {
        $stored = (string) get_user_preferences(self::PREFERENCE, '', $userid);
        return $stored === '' ? [] : explode(',', $stored);
    }

    /**
     * Records that a user is facing an opponent, and tells whether it is the first time.
     *
     * @param string $opponent An opponent key.
     * @param int $userid User id.
     * @return bool True the first time, false once it was already recorded.
     */
    public static function first_meeting(string $opponent, int $userid): bool {
        $seen = self::seen($userid);
        if (in_array($opponent, $seen, true)) {
            return false;
        }
        $seen[] = $opponent;
        set_user_preference(self::PREFERENCE, implode(',', $seen), $userid);
        return true;
    }
}
