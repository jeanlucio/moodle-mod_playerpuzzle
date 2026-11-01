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
 * Combat scaling engine for PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

use mod_playerpuzzle\local\constants;
use mod_playerpuzzle\local\opponents;

/**
 * Calculates boss/student HP scaling by level and phase (Campaign mode only).
 *
 * Single Match mode has no scaling: its attempts always carry currentlevel =
 * currentphase = 1, which these formulas map back to the configured base HP unchanged,
 * so callers never need to branch on gamemode themselves.
 */
class combat {
    /**
     * HP growth per level, applied to the boss.
     */
    private const LEVEL_HP_FACTOR = 0.5;

    /**
     * HP growth per phase within a level, applied to the boss.
     */
    private const PHASE_HP_FACTOR = 0.1;

    /**
     * HP growth per level, applied to the student.
     */
    private const LEVEL_STUDENT_FACTOR = 0.3;

    /**
     * HP growth per phase within a level, applied to the student.
     */
    private const PHASE_STUDENT_FACTOR = 0.05;

    /**
     * Boss HP and boss damage multiplier per student-chosen difficulty. Applied on top of
     * the level/phase scaling, never to the student's own HP, and never to the grade
     * directly — a win on any difficulty still clears 100% of that difficulty's own boss HP,
     * so Easy is a safety net for weaker students rather than a grade penalty.
     */
    private const DIFFICULTY_BOSS_FACTORS = ['easy' => 0.5, 'normal' => 1.0, 'hard' => 2.0];

    /**
     * Coin reward multiplier per difficulty. Easy earns less, Hard earns more; applied
     * server-side when coins are banked, so the client cannot inflate it.
     */
    private const DIFFICULTY_COIN_FACTORS = ['easy' => 0.5, 'normal' => 1.0, 'hard' => 3.0];

    /**
     * Probability the boss's guess lands on the correct answer, by difficulty and question
     * type. The draw is done server-side (the client never learns which answer is right),
     * so this concretises the "aggressive AI" of Hard mode. True/False starts at 0.5 rather
     * than 0.33 because pure chance on a two-option question is already 50%.
     */
    private const BOSS_GUESS_PROBABILITIES = [
        'easy'   => ['multichoice' => 0.33, 'truefalse' => 0.5],
        'normal' => ['multichoice' => 0.66, 'truefalse' => 0.75],
        'hard'   => ['multichoice' => 1.0, 'truefalse' => 1.0],
    ];

    /**
     * Fixed loadout shop prices in PuzzleCoin, per consumable type. Not teacher-configurable
     * — nor is the per-phase use limit (attempt_consumables::PHASE_LIMITS), which follows
     * the mechanic itself rather than a setting.
     */
    private const CONSUMABLE_PRICES = ['potion' => 8, 'shield' => 10, 'magic' => 12, 'sword' => 10, 'hint' => 5];

    /**
     * Calculates the boss HP for a given level/phase, scaled from the teacher-configured
     * base HP.
     *
     * @param int $basehp Base boss HP configured by the teacher (Level 1, Phase 1).
     * @param int $level Current level (1-10).
     * @param int $phase Current phase within the level (1-10).
     * @return int The scaled boss HP.
     */
    public static function calculate_boss_hp(int $basehp, int $level, int $phase): int {
        return (int) round($basehp * (
            1
            + self::LEVEL_HP_FACTOR * ($level - 1)
            + self::PHASE_HP_FACTOR * ($phase - 1)
        ));
    }

    /**
     * Fixed boss/student HP for a Demo match — a short, on-demand practice fight the
     * student can request from the Lobby at any time. Deliberately
     * ignores the instance's own basebosshp/basestudenthp/difficulty/level/phase scaling
     * entirely: a Demo is not a scaled-down real fight, it is a fixed, predictable one.
     */
    public const DEMO_HP = 50;

    /**
     * The opponent's side of a phase as the fight runs it: its max HP, the phase's base damage
     * (the unit every hit, heal and poison tick is counted in, for both sides), and the attack
     * and armor its profile gives it. The game page, the server replay and the scoring all read
     * it from here, so they always fight the same opponent.
     *
     * A real phase uses the values frozen when it started (an attempt row from before a column
     * existed falls back to the instance), scaled by level, phase and difficulty, and then by the
     * opponent's profile. A Demo fights at the fixed DEMO_HP, with no difficulty and no profile.
     *
     * @param \stdClass $attempt The attempt: isdemo, difficulty, currentlevel, currentphase,
     *  frozenbasebosshp, frozenbossdamage and frozenopponent are read.
     * @param \stdClass $instance The activity: basebosshp and bossdamage are read.
     * @return array ['maxbosshp' => int, 'basedamage' => int, 'bossattack' => float, 'bossarmor' => float]
     */
    public static function phase_opponent(\stdClass $attempt, \stdClass $instance): array {
        $level = (int) $attempt->currentlevel;
        $phase = (int) $attempt->currentphase;

        if ((bool) $attempt->isdemo) {
            return [
                'maxbosshp' => self::DEMO_HP,
                'basedamage' => self::calculate_boss_hp((int) $instance->bossdamage, $level, $phase),
                'bossattack' => 1.0,
                'bossarmor' => 0.0,
            ];
        }

        $difficulty = (string) $attempt->difficulty;
        $modifiers = opponents::modifiers((string) $attempt->frozenopponent);
        $basehp = (int) $attempt->frozenbasebosshp ?: (int) $instance->basebosshp;
        $basedamage = (int) $attempt->frozenbossdamage ?: (int) $instance->bossdamage;
        $maxbosshp = self::apply_difficulty(self::calculate_boss_hp($basehp, $level, $phase), $difficulty);

        // Halves round up with floor(), never round(): the replay mirror in JavaScript must land
        // on the same whole number, and round() pre-rounds values like 227.49999999999997 up.
        return [
            'maxbosshp' => (int) floor($maxbosshp * $modifiers['hp'] + 0.5),
            'basedamage' => self::apply_difficulty(self::calculate_boss_hp($basedamage, $level, $phase), $difficulty),
            'bossattack' => $modifiers['attack'],
            'bossarmor' => $modifiers['armor'],
        ];
    }

    /**
     * Calculates the student HP for a given level/phase, scaled from the teacher-configured
     * base HP.
     *
     * @param int $basehp Base student HP configured by the teacher (Level 1, Phase 1).
     * @param int $level Current level (1-10).
     * @param int $phase Current phase within the level (1-10).
     * @return int The scaled student HP.
     */
    public static function calculate_student_hp(int $basehp, int $level, int $phase): int {
        return (int) round($basehp * (
            1
            + self::LEVEL_STUDENT_FACTOR * ($level - 1)
            + self::PHASE_STUDENT_FACTOR * ($phase - 1)
        ));
    }

    /**
     * Returns the boss HP/damage multiplier for a difficulty. Unknown values fall back to
     * 1.0 (Normal), so a malformed stored value never crashes combat.
     *
     * @param string $difficulty One of the constants::DIFFICULTY_* values.
     * @return float
     */
    public static function difficulty_boss_factor(string $difficulty): float {
        return self::DIFFICULTY_BOSS_FACTORS[$difficulty] ?? 1.0;
    }

    /**
     * Returns the coin-reward multiplier for a difficulty. Unknown values fall back to 1.0.
     *
     * @param string $difficulty One of the constants::DIFFICULTY_* values.
     * @return float
     */
    public static function difficulty_coin_factor(string $difficulty): float {
        return self::DIFFICULTY_COIN_FACTORS[$difficulty] ?? 1.0;
    }

    /**
     * Applies the difficulty boss factor to an already level/phase-scaled value (boss HP or
     * boss damage), rounding to an integer.
     *
     * @param int $scaledvalue A value already returned by calculate_boss_hp().
     * @param string $difficulty One of the constants::DIFFICULTY_* values.
     * @return int
     */
    public static function apply_difficulty(int $scaledvalue, string $difficulty): int {
        return (int) round($scaledvalue * self::difficulty_boss_factor($difficulty));
    }

    /**
     * Returns the probability the boss's guess should land on the correct answer, for a
     * difficulty and question type. Unknown values fall back to the Normal / multichoice
     * cell, never above it.
     *
     * @param string $difficulty One of the constants::DIFFICULTY_* values.
     * @param string $qtype 'multichoice' or 'truefalse'.
     * @return float A probability in the 0..1 range.
     */
    public static function boss_guess_probability(string $difficulty, string $qtype): float {
        $row = self::BOSS_GUESS_PROBABILITIES[$difficulty] ?? self::BOSS_GUESS_PROBABILITIES['normal'];
        return $row[$qtype] ?? $row['multichoice'];
    }

    /**
     * Returns the fixed shop price for a consumable type, in local coins.
     *
     * @param string $type One of attempt_consumables::TYPES.
     * @return int The price, or 0 for an unknown type.
     */
    public static function consumable_price(string $type): int {
        return self::CONSUMABLE_PRICES[$type] ?? 0;
    }
}
