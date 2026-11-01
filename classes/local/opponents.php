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

use mod_playerpuzzle\local\engine\combat_engine;
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

    /** @var string Profile of an opponent that hits harder. */
    public const PROFILE_BRUTE = 'brute';

    /** @var string Profile of an opponent whose armor softens every hit it takes. */
    public const PROFILE_ARMORED = 'armored';

    /** @var string Profile of an opponent with more HP. */
    public const PROFILE_TOUGH = 'tough';

    /** @var array The profile of each opponent. The final boss has none. */
    private const PROFILES = [
        'slime' => self::PROFILE_TOUGH,
        'goblin' => self::PROFILE_BRUTE,
        'golem' => self::PROFILE_ARMORED,
        'mummy' => self::PROFILE_BRUTE,
        'plant' => self::PROFILE_ARMORED,
        'ghost' => self::PROFILE_TOUGH,
        'pirate' => self::PROFILE_BRUTE,
        'wolf' => self::PROFILE_BRUTE,
        'spider' => self::PROFILE_TOUGH,
        'dragon' => self::PROFILE_BRUTE,
    ];

    /**
     * @var array What each profile changes in the fight: a multiplier on the opponent's HP, a
     *  multiplier on the hits it lands, and the share of each hit it takes that its armor absorbs.
     *  Starting values, to be tuned against simulated matches.
     */
    private const PROFILE_MODIFIERS = [
        self::PROFILE_BRUTE => ['hp' => 1.0, 'attack' => 1.25, 'armor' => 0.0],
        self::PROFILE_ARMORED => ['hp' => 1.0, 'attack' => 1.0, 'armor' => 0.25],
        self::PROFILE_TOUGH => ['hp' => 1.3, 'attack' => 1.0, 'armor' => 0.0],
    ];

    /** @var array The modifiers of an opponent with no profile: nothing changes. */
    private const NO_MODIFIERS = ['hp' => 1.0, 'attack' => 1.0, 'armor' => 0.0];

    /**
     * @var array The powers of each opponent. The rules of each live in combat_rules.js and its
     *  PHP twin combat_engine.php, which name them and receive the list in the phase's config.
     */
    private const POWERS = [
        'golem' => [combat_engine::POWER_ICESHIELD],
        'mummy' => [combat_engine::POWER_LONGPOISON],
        'plant' => [combat_engine::POWER_ROOTHEAL],
        'wolf' => [combat_engine::POWER_MANABOOST],
        'spider' => [combat_engine::POWER_FASTGRIMOIRE],
    ];

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
     * An opponent's profile.
     *
     * @param string $key An opponent key.
     * @return string|null One of the PROFILE_* values, or null for an opponent without one.
     */
    public static function profile(string $key): ?string {
        return self::PROFILES[$key] ?? null;
    }

    /**
     * What an opponent's profile changes in the fight. Only an exact key counts: an empty value
     * (an attempt that never had its opponent frozen) gets no modifiers, like the final boss.
     *
     * @param string $key An opponent key.
     * @return array ['hp' => float, 'attack' => float, 'armor' => float]
     */
    public static function modifiers(string $key): array {
        $profile = self::profile($key);
        return $profile === null ? self::NO_MODIFIERS : self::PROFILE_MODIFIERS[$profile];
    }

    /**
     * An opponent's powers. Only an exact key counts, as in modifiers().
     *
     * @param string $key An opponent key.
     * @return string[] combat_engine::POWER_* values, possibly none.
     */
    public static function powers(string $key): array {
        return self::POWERS[$key] ?? [];
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
