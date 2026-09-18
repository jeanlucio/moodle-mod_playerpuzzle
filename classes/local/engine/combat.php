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
     * Fixed shop prices in local coins, per consumable type. Not teacher-configurable — only
     * the per-attempt use limit (maxconsumables) is.
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
     * @param string $difficulty One of the PLAYERPUZZLE_DIFFICULTY_* values.
     * @return float
     */
    public static function difficulty_boss_factor(string $difficulty): float {
        return self::DIFFICULTY_BOSS_FACTORS[$difficulty] ?? 1.0;
    }

    /**
     * Returns the coin-reward multiplier for a difficulty. Unknown values fall back to 1.0.
     *
     * @param string $difficulty One of the PLAYERPUZZLE_DIFFICULTY_* values.
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
     * @param string $difficulty One of the PLAYERPUZZLE_DIFFICULTY_* values.
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
     * @param string $difficulty One of the PLAYERPUZZLE_DIFFICULTY_* values.
     * @param string $qtype 'multichoice' or 'truefalse'.
     * @return float A probability in the 0..1 range.
     */
    public static function boss_guess_probability(string $difficulty, string $qtype): float {
        $row = self::BOSS_GUESS_PROBABILITIES[$difficulty] ?? self::BOSS_GUESS_PROBABILITIES['normal'];
        return $row[$qtype] ?? $row['multichoice'];
    }

    /**
     * Plausibility ceiling for how many coins could genuinely have been earned in this
     * phase/match: a rough 1-to-1 bound between the number of Sword combos it would take to
     * clear this phase's own boss HP and an equal number of Coin combos. A **stable per-phase
     * value**, not tied to the damage actually dealt so far — Coin/Shield/Magic matches are
     * independent of Sword matches on the board, so a student who has not yet landed a hit
     * (boss still at full HP) can still have genuinely earned coins, and a ceiling keyed to
     * live damage would wrongly floor to 0 for them. Not a precise economic model — it exists
     * to catch a client reporting a wildly inflated coin value, bounding it to a generous
     * multiple of this phase's own size, never to police real-time play.
     *
     * @param int $bosshp This phase's own scaled boss HP (its full value, not what remains).
     * @param int $scaledbossdamage The phase's own scaled combat damage value (a single
     *  3-piece Sword combo's worth), always at least 1 to avoid dividing by zero.
     * @param int $coingain Base coins per 3-piece Coin combo, unscaled by level/phase.
     * @param float $coinfactor Difficulty coin multiplier.
     * @return int The ceiling, never negative.
     */
    public static function coin_ceiling(int $bosshp, int $scaledbossdamage, int $coingain, float $coinfactor): int {
        $safebosshp = max(0, $bosshp);
        $safebossdamage = max(1, $scaledbossdamage);

        return (int) floor(($safebosshp / $safebossdamage) * $coingain * $coinfactor);
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
