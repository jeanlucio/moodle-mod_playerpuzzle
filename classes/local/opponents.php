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
 * The opponents and the stage each one is fought on.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use moodle_url;
use stdClass;

/**
 * Every opponent the game can show, keyed by a short name, with the stage it is fought on.
 *
 * A Campaign fights them in CAMPAIGN_ORDER, one per phase, again on every level, except that
 * the last phase of the last level is the final boss. A Single Match fights the one the teacher
 * picked in the activity's bossavatar field, which now stores one of these keys.
 */
class opponents {
    /** @var string[] The Campaign's opponent for phases 1 to 10, easiest first. */
    public const CAMPAIGN_ORDER = [
        'slime', 'goblin', 'golem', 'mummy', 'plant', 'ghost', 'pirate', 'wolf', 'spider', 'dragon',
    ];

    /** @var string The final boss: only ever met on the last phase of the last level. */
    public const FINAL_BOSS = 'vampire';

    /** @var string The opponent used when a stored value names none of the known ones. */
    public const DEFAULT = 'slime';

    /** @var array The stage (pix/stages/<stage>.webp) each opponent is fought on. */
    private const STAGES = [
        'slime' => 'ruins',
        'goblin' => 'nightforest',
        'golem' => 'snow',
        'mummy' => 'desert',
        'plant' => 'swamp',
        'ghost' => 'cemetery',
        'pirate' => 'harbour',
        'wolf' => 'sunnyforest',
        'spider' => 'cave',
        'dragon' => 'volcano',
        'vampire' => 'castle',
    ];

    /**
     * Every opponent key, in Campaign order with the final boss last.
     *
     * @return string[]
     */
    public static function keys(): array {
        return array_keys(self::STAGES);
    }

    /**
     * Turns a stored bossavatar value into an opponent key. Values saved before opponents
     * had keys were image filenames ("dragon.png"), and an activity restored from such a
     * backup still carries one; anything unknown falls back to DEFAULT.
     *
     * @param string|null $stored The stored value.
     * @return string An opponent key.
     */
    public static function normalise(?string $stored): string {
        $key = preg_replace('/\.png$/', '', (string) $stored);
        return array_key_exists($key, self::STAGES) ? $key : self::DEFAULT;
    }

    /**
     * The opponent a phase is fought against.
     *
     * @param stdClass $instance The activity: gamemode, maxlevels and bossavatar are read.
     * @param int $level The phase's level.
     * @param int $phase The phase within the level (1 to 10).
     * @return string An opponent key.
     */
    public static function for_phase(stdClass $instance, int $level, int $phase): string {
        if ($instance->gamemode !== constants::GAMEMODE_CAMPAIGN) {
            return self::normalise($instance->bossavatar ?? null);
        }
        if ($phase >= 10 && $level >= max(1, (int) $instance->maxlevels)) {
            return self::FINAL_BOSS;
        }
        return self::CAMPAIGN_ORDER[max(1, min(10, $phase)) - 1];
    }

    /**
     * The stage an opponent is fought on.
     *
     * @param string $key An opponent key.
     * @return string A stage name.
     */
    public static function stage(string $key): string {
        return self::STAGES[self::normalise($key)];
    }

    /**
     * The opponent's sprite. Linked directly rather than through image_url(), which does not
     * resolve WebP images.
     *
     * @param string $key An opponent key.
     * @return moodle_url
     */
    public static function sprite_url(string $key): moodle_url {
        return new moodle_url('/mod/playerpuzzle/pix/opponents/' . self::normalise($key) . '.webp');
    }

    /**
     * The opponent's stage background, linked directly for the same reason as sprite_url().
     *
     * @param string $key An opponent key.
     * @return moodle_url
     */
    public static function stage_url(string $key): moodle_url {
        return new moodle_url('/mod/playerpuzzle/pix/stages/' . self::stage($key) . '.webp');
    }

    /**
     * The choices for the activity form's opponent select, in Campaign order.
     *
     * @return array Key => display name.
     */
    public static function options(): array {
        $options = [];
        foreach (self::keys() as $key) {
            $options[$key] = get_string('bossavatar_' . $key, 'mod_playerpuzzle');
        }
        return $options;
    }
}
