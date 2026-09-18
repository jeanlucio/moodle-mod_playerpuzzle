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
 * Service tracking the per-user Música/Efeitos toggle preferences.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Reads and writes the two independent sound-channel preferences (Música/Efeitos).
 *
 * Kept as two separate user_preferences rows rather than one combined value: the HUD
 * already ships two independently clickable badges (mirrors mod_playerland's own
 * intro_service pattern — a single user_preferences row per concern, no dedicated table),
 * and a shared preference would force them to always move together, changing behaviour
 * that already exists today (each button mutes only its own channel).
 */
class sound_preferences {
    /** @var string[] Valid channels. */
    public const TYPES = ['music', 'sfx'];

    /** @var string Prefix shared by both preference names, and by db/uninstall.php's cleanup. */
    private const PREFERENCE_PREFIX = 'mod_playerpuzzle_';

    /**
     * Name of the underlying user preference for a channel, exposed for the privacy
     * provider and for db/uninstall.php cleanup.
     *
     * @param string $type One of self::TYPES.
     * @return string
     */
    public static function preference_name(string $type): string {
        return self::PREFERENCE_PREFIX . $type;
    }

    /**
     * Whether the given channel is enabled for the given user. Defaults to enabled,
     * matching the in-game default before this preference existed.
     *
     * @param string $type One of self::TYPES.
     * @param int $userid User id.
     * @return bool
     */
    public static function is_enabled(string $type, int $userid): bool {
        return (bool) get_user_preferences(self::preference_name($type), true, $userid);
    }

    /**
     * Sets whether the given channel is enabled for the given user.
     *
     * @param string $type One of self::TYPES.
     * @param bool $enabled New state.
     * @param int $userid User id.
     * @return void
     */
    public static function set_enabled(string $type, bool $enabled, int $userid): void {
        set_user_preference(self::preference_name($type), $enabled ? 1 : 0, $userid);
    }
}
