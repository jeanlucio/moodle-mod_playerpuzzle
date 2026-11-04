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
 * The heroes a student plays and how they grow: their specialty, the stages they evolve through and
 * what each purchase of HP, attack or armor is worth.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Pure rules, no database: a hero grows only by what the student buys with PuzzleCoin, and the numbers
 * here come from the Campaign simulation (tests/js/engine/simulate_heroes.js).
 *
 * A Campaign allows a fixed number of buys per attribute for every level it has, spread over four stages.
 * Each stage caps how many of an attribute may have been bought, and the hero moves up a stage, for free,
 * once the Campaign has passed the stage's milestone and the specialty is at the cap. The specialty also
 * starts with a small head start, a share of the attribute's whole range rather than a number of buys,
 * because a buy of HP, attack and armor are worth very different amounts.
 */
final class heroes {
    /** @var string Attribute: the student's maximum HP. */
    public const HP = 'hp';

    /** @var string Attribute: the multiplier on the hits the student lands. */
    public const ATTACK = 'attack';

    /** @var string Attribute: the share of each hit the student takes that is absorbed. */
    public const ARMOR = 'armor';

    /** @var string[] Every attribute a purchase can raise. */
    public const ATTRIBUTES = [self::HP, self::ATTACK, self::ARMOR];

    /** @var array The six heroes and the attribute each one is a specialist in. */
    public const CATALOG = [
        'warriorf' => self::ATTACK,
        'warriorm' => self::ATTACK,
        'magef' => self::HP,
        'magem' => self::HP,
        'archerm' => self::ARMOR,
        'archerf' => self::ARMOR,
    ];

    /** @var int Visual stages: the first look and three evolutions. */
    public const STAGES = 4;

    /** @var int Buys of each attribute a Campaign allows for every level it has. */
    public const BUYS_PER_LEVEL = 4;

    /** @var int PuzzleCoin one buy costs. */
    public const PRICE = 12;

    /** @var float The specialty's head start, as a share of the attribute's whole range. */
    public const BONUS_SHARE = 0.05;

    /** @var float What the HP multiple reaches when every buy is made, in a Campaign of one level. */
    public const HP_FINAL_BASE = 2.4;

    /** @var float What that grows by for each further level, so the hero keeps up with the opponents. */
    public const HP_FINAL_PER_LEVEL = 0.62;

    /** @var float What the attack multiple reaches when every buy is made. */
    public const ATTACK_FINAL = 1.9;

    /** @var float What the armor reaches when every buy is made. */
    public const ARMOR_FINAL = 0.25;

    /** @var float The most of a hit any armor can absorb, as in the combat rules. */
    public const ARMOR_CAP = 0.6;

    /** @var int Phases in a level. */
    private const PHASES_PER_LEVEL = 10;

    /**
     * Whether a string names one of the heroes.
     *
     * @param string $hero A hero key.
     * @return bool
     */
    public static function is_valid(string $hero): bool {
        return isset(self::CATALOG[$hero]);
    }

    /**
     * The attribute a hero is a specialist in.
     *
     * @param string $hero A valid hero key.
     * @return string One of self::ATTRIBUTES.
     */
    public static function specialty(string $hero): string {
        return self::CATALOG[$hero];
    }

    /**
     * The sprite key for a hero at a stage, the name of its file under pix/heroes/.
     *
     * @param string $hero A valid hero key.
     * @param int $stage Stage, 1 to self::STAGES.
     * @return string
     */
    public static function sprite(string $hero, int $stage): string {
        return $hero . max(1, min(self::STAGES, $stage));
    }

    /**
     * The hero a sprite belongs to.
     *
     * @param string $sprite A sprite key, see self::sprite(), or ''.
     * @return string A key of self::CATALOG, or '' when the sprite names no hero.
     */
    public static function of_sprite(string $sprite): string {
        if (!preg_match('/^([a-z]+)\d+$/', $sprite, $matches)) {
            return '';
        }

        return self::is_valid($matches[1]) ? $matches[1] : '';
    }

    /**
     * Where a sprite is served from. The art is WebP, which image_url() does not resolve, so it is
     * linked directly, as the opponents' and stages' are.
     *
     * @param string $sprite A sprite key, see self::sprite().
     * @return \moodle_url
     */
    public static function sprite_url(string $sprite): \moodle_url {
        return new \moodle_url('/mod/playerpuzzle/pix/heroes/' . $sprite . '.webp');
    }

    /**
     * How many buys of each attribute a whole Campaign allows.
     *
     * @param int $maxlevels The activity's level count.
     * @return int
     */
    public static function total_buys(int $maxlevels): int {
        return self::BUYS_PER_LEVEL * max(1, $maxlevels);
    }

    /**
     * The most buys of one attribute a hero in a stage may have made: the stage's share of the total,
     * which is also the share of the Campaign the stage ends at.
     *
     * @param int $maxlevels The activity's level count.
     * @param int $stage Stage, 1 to self::STAGES.
     * @return int
     */
    public static function stage_cap(int $maxlevels, int $stage): int {
        return (int) round(self::total_buys($maxlevels) * max(1, min(self::STAGES, $stage)) / self::STAGES);
    }

    /**
     * How many phases of the Campaign must be won before a hero may leave a stage: the stage's share
     * of the Campaign's phases, rounded up.
     *
     * @param int $maxlevels The activity's level count.
     * @param int $stage The stage being left, 1 to self::STAGES - 1.
     * @return int
     */
    public static function milestone(int $maxlevels, int $stage): int {
        return (int) ceil(max(1, $maxlevels) * self::PHASES_PER_LEVEL * $stage / self::STAGES);
    }

    /**
     * What one buy of an attribute adds: the attribute's whole range split over the Campaign's buys.
     *
     * @param string $attribute One of self::ATTRIBUTES.
     * @param int $maxlevels The activity's level count.
     * @return float The HP multiple, attack multiple or armor share added by one buy.
     */
    public static function step(string $attribute, int $maxlevels): float {
        $range = match ($attribute) {
            self::HP => self::HP_FINAL_BASE + (self::HP_FINAL_PER_LEVEL * (max(1, $maxlevels) - 1)) - 1,
            self::ATTACK => self::ATTACK_FINAL - 1,
            default => self::ARMOR_FINAL,
        };

        return $range / self::total_buys($maxlevels);
    }

    /**
     * What a hero fights with, from what the student has bought.
     *
     * @param string $hero A valid hero key.
     * @param array $buys Buys made so far, keyed by attribute (a missing one is 0).
     * @param int $maxlevels The activity's level count.
     * @param int $basehp The activity's base student HP.
     * @return array ['hp' => int, 'attack' => float, 'armor' => float], attack and armor to three decimals,
     *  which is what a phase freezes.
     */
    public static function attributes(string $hero, array $buys, int $maxlevels, int $basehp): array {
        $worth = function (string $attribute) use ($hero, $buys, $maxlevels): float {
            $head = self::specialty($hero) === $attribute ? self::BONUS_SHARE * self::total_buys($maxlevels) : 0;
            return (int) ($buys[$attribute] ?? 0) + $head;
        };

        return [
            'hp' => (int) round($basehp * (1 + ($worth(self::HP) * self::step(self::HP, $maxlevels)))),
            'attack' => round(1 + ($worth(self::ATTACK) * self::step(self::ATTACK, $maxlevels)), 3),
            'armor' => round(min(self::ARMOR_CAP, $worth(self::ARMOR) * self::step(self::ARMOR, $maxlevels)), 3),
        ];
    }

    /**
     * The stage a hero is in once it has done everything the Campaign lets it: it moves up while the
     * next milestone has been passed and the specialty is at the current stage's cap. Free, and never
     * backwards.
     *
     * @param string $hero A valid hero key.
     * @param array $buys Buys made so far, keyed by attribute.
     * @param int $stage The stage it is in now.
     * @param int $maxlevels The activity's level count.
     * @param int $completed Phases of the Campaign already won.
     * @return int The stage it should be in.
     */
    public static function evolved_stage(string $hero, array $buys, int $stage, int $maxlevels, int $completed): int {
        $bought = (int) ($buys[self::specialty($hero)] ?? 0);
        while (
            $stage < self::STAGES
            && $completed >= self::milestone($maxlevels, $stage)
            && $bought >= self::stage_cap($maxlevels, $stage)
        ) {
            $stage++;
        }

        return $stage;
    }
}
