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
 * Service tracking the per-user Music/Sound Effects/narration toggle preferences.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Reads and writes the three independent audio preferences: Music, Sound Effects, and
 * spoken narration of the accessibility announcements the game already writes to its
 * screen-reader live region.
 *
 * Kept as separate user_preferences rows rather than one combined value: the HUD ships
 * independently clickable Music/Effects badges (mirrors mod_playerland's own
 * intro_service pattern — a single user_preferences row per concern, no dedicated table),
 * and a shared preference would force them to always move together, changing behaviour
 * that already exists today (each control only ever affects its own channel).
 */
class sound_preferences {
    /** @var string[] Valid channels. */
    public const TYPES = ['music', 'sfx', 'speech'];

    /** @var string Prefix shared by every preference name, and by db/uninstall.php's cleanup. */
    private const PREFERENCE_PREFIX = 'mod_playerpuzzle_';

    /**
     * Default state per channel when a user has never touched it. Music/Sound Effects
     * default on, matching the in-game default before this preference existed; narration
     * defaults off — it speaks over the game through the browser's speech synthesis, so
     * turning it on should always be a deliberate choice, never a surprise on first load.
     */
    private const DEFAULTS = ['music' => true, 'sfx' => true, 'speech' => false];

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
     * Whether the given channel is enabled for the given user, falling back to that
     * channel's own default (see self::DEFAULTS) when never explicitly set.
     *
     * @param string $type One of self::TYPES.
     * @param int $userid User id.
     * @return bool
     */
    public static function is_enabled(string $type, int $userid): bool {
        $default = self::DEFAULTS[$type] ?? true;
        return (bool) get_user_preferences(self::preference_name($type), $default, $userid);
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
