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
 * Calculates the opponent's HP scaling by level and phase (Campaign mode only), and reads what the student
 * fights a phase with.
 *
 * Single Match mode has no scaling: its attempts always carry currentlevel =
 * currentphase = 1, which the formulas map back to the configured base HP unchanged,
 * so callers never need to branch on gamemode themselves. The student's HP does not scale: it grows
 * only with what they buy for their hero (see mod_playerpuzzle\local\heroes).
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
     * Multiplier per student-chosen difficulty on the opponent's own amounts: its hits, its Potions and the poison it
     * inflicts. Applied on top of the level/phase scaling, never to the student's own HP, and never to the grade
     * directly — a win on any difficulty still clears 100% of that difficulty's own boss HP,
     * so Easy is a safety net for weaker students rather than a grade penalty.
     */
    private const DIFFICULTY_BOSS_FACTORS = ['easy' => 0.5, 'normal' => 1.0, 'hard' => 2.0];

    /**
     * Multiplier per difficulty on the opponent's max HP: Easy halves it and Hard doubles it, as on its hits.
     */
    private const DIFFICULTY_HP_FACTORS = ['easy' => 0.5, 'normal' => 1.0, 'hard' => 2.0];

    /**
     * What a Normal final boss has on top of the HP the level, the phase and its own profile give: 15% more, so it
     * stands a little above what a hero with every upgrade can reach, instead of a little below. Only the final
     * boss, since the hero's ceiling only matters at the end, and only on Normal, as Hard is already far above
     * it. Tuned with tests/js/engine/simulate_difficulty.js.
     */
    private const NORMAL_FINAL_BOSS_HP_FACTOR = 1.15;

    /**
     * How much harder, at the Campaign's last phase, a Normal opponent hits than at its first: the extra grows
     * in a straight line over the Campaign's phases. The hero grows in three ways (HP, attack, armor) and the
     * opponent only in HP, so without this the Normal went from about 55% of phases won at the first try to
     * about 90% by the end. Normal only: Hard is already far above the hero, and Easy is meant to stay easy.
     * Tuned with tests/js/engine/simulate_heroes.js.
     */
    private const NORMAL_ATTACK_GROWTH = 0.6;

    /** @var int Phases in a level, which the Campaign's progress is counted in. */
    private const PHASES_PER_LEVEL = 10;
    /**
     * Multiplier per difficulty on the unit the student's own amounts are counted in. Easy leaves it alone, so
     * that halving the opponent's HP makes the match shorter instead of cancelling out against the student's
     * hits; Hard doubles it along with the opponent's HP, as it always did.
     */
    private const DIFFICULTY_PLAYER_FACTORS = ['easy' => 1.0, 'normal' => 1.0, 'hard' => 2.0];

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
    public const DEMO_HP = 100;

    /**
     * The opponent's side of a phase as the fight runs it: its max HP, the phase's base damage
     * (the unit every hit, heal and poison tick is counted in, for both sides), the attack and
     * armor its profile gives it, its powers and its starting items. The game page, the server replay and the
     * scoring all read
     * it from here, so they always fight the same opponent.
     *
     * A real phase uses the values frozen when it started (an attempt row from before a column
     * existed falls back to the instance), scaled by level, phase and difficulty, and then by the
     * opponent's profile. A Demo fights at the fixed DEMO_HP, with no difficulty, no profile, no
     * powers and no items.
     *
     * @param \stdClass $attempt The attempt: isdemo, difficulty, currentlevel, currentphase,
     *  frozenbasebosshp, frozenbossdamage and frozenopponent are read, and frozenhero, which decides the second
     *  power the final boss fights with (see opponents::counter()).
     * @param \stdClass $instance The activity: basebosshp and bossdamage are read, and maxlevels (1 when absent).
     * @return array ['maxbosshp' => int, 'basedamage' => int (the opponent's unit), 'playerbasedamage' => int (the
     *  student's unit), 'bossattack' => float, 'bossarmor' => float,
     *  'bosspowers' => string[], 'bossbreathevery' => int (0 for the default rhythm),
     *  'bosskit' => ['potion' => int, 'sword' => int]]
     */
    public static function phase_opponent(\stdClass $attempt, \stdClass $instance): array {
        $level = (int) $attempt->currentlevel;
        $phase = (int) $attempt->currentphase;

        if ((bool) $attempt->isdemo) {
            return [
                'maxbosshp' => self::DEMO_HP,
                'basedamage' => self::calculate_boss_hp((int) $instance->bossdamage, $level, $phase),
                'playerbasedamage' => self::calculate_boss_hp((int) $instance->bossdamage, $level, $phase),
                'bossattack' => 1.0,
                'bossarmor' => 0.0,
                'bosspowers' => [],
                'bossbreathevery' => 0,
                'bosskit' => ['potion' => 0, 'sword' => 0],
            ];
        }

        $difficulty = (string) $attempt->difficulty;
        $modifiers = opponents::modifiers((string) $attempt->frozenopponent);
        $counter = opponents::counter((string) $attempt->frozenopponent, (string) ($attempt->frozenhero ?? ''));
        $basehp = (int) $attempt->frozenbasebosshp ?: (int) $instance->basebosshp;
        $basedamage = (int) $attempt->frozenbossdamage ?: (int) $instance->bossdamage;
        $maxbosshp = (int) round(self::calculate_boss_hp($basehp, $level, $phase) * self::difficulty_hp_factor($difficulty));
        $isfinalboss = (string) $attempt->frozenopponent === opponents::FINAL_BOSS;
        $finalbossfactor = $isfinalboss && $difficulty === constants::DIFFICULTY_NORMAL
            ? self::NORMAL_FINAL_BOSS_HP_FACTOR
            : 1.0;
        $unit = self::calculate_boss_hp($basedamage, $level, $phase);

        // Halves round up with floor(), never round(): the replay mirror in JavaScript must land
        // on the same whole number, and round() pre-rounds values like 227.49999999999997 up.
        return [
            'maxbosshp' => (int) floor($maxbosshp * $modifiers['hp'] * $finalbossfactor + 0.5),
            'basedamage' => self::apply_difficulty($unit, $difficulty),
            'playerbasedamage' => (int) round($unit * self::difficulty_player_factor($difficulty)),
            'bossattack' => self::scale_attack(
                (float) $modifiers['attack'],
                $difficulty,
                $level,
                $phase,
                (int) ($instance->maxlevels ?? 1)
            ),
            'bossarmor' => $modifiers['armor'] + $counter['armor'],
            'bosspowers' => array_values(array_unique(
                array_merge(opponents::powers((string) $attempt->frozenopponent), $counter['powers'])
            )),
            'bossbreathevery' => $counter['breathevery'],
            'bosskit' => combat_engine::opponent_kit(
                $level,
                $difficulty,
                (string) $attempt->frozenopponent === opponents::FINAL_BOSS
            ),
        ];
    }

    /**
     * The multiplier on the opponent's hits once the Campaign's progress is counted in: a Normal opponent hits
     * up to NORMAL_ATTACK_GROWTH harder the further into the Campaign the phase is, from nothing at the first
     * phase to the full extra at the last. Rounded to three decimals with floor(), like the other values the
     * replay mirror in JavaScript must land on exactly.
     *
     * @param float $attack The opponent's own multiplier, from its profile.
     * @param string $difficulty The phase's difficulty.
     * @param int $level The phase's level.
     * @param int $phase The phase within the level.
     * @param int $maxlevels The Campaign's level count.
     * @return float
     */
    public static function scale_attack(float $attack, string $difficulty, int $level, int $phase, int $maxlevels): float {
        if ($difficulty !== constants::DIFFICULTY_NORMAL) {
            return $attack;
        }
        $last = max(1, $maxlevels) * self::PHASES_PER_LEVEL - 1;
        $progress = min(1.0, max(0.0, (($level - 1) * self::PHASES_PER_LEVEL + $phase - 1) / $last));

        return floor($attack * (1 + self::NORMAL_ATTACK_GROWTH * $progress) * 1000 + 0.5) / 1000;
    }

    /**
     * The student's side of a phase as it was fixed when the phase started: max HP, the multiplier on
     * the hits they land and the share of each hit they take that their armor absorbs. Read by the
     * server replay and by the page that starts the live client, so both fight with the same numbers.
     * A row from before these were frozen has no HP stored; it falls back to the instance's base HP,
     * with no attack bonus and no armor.
     *
     * @param \stdClass $attempt The attempt row (currentlevel, currentphase, frozenplayerhp,
     *  frozenplayerattack, frozenplayerarmor).
     * @param \stdClass $instance The instance record (basestudenthp).
     * @return array ['maxhp' => int, 'attack' => float, 'armor' => float]
     */
    public static function phase_student(\stdClass $attempt, \stdClass $instance): array {
        $frozenhp = (int) ($attempt->frozenplayerhp ?? 0);
        if ($frozenhp > 0) {
            return [
                'maxhp' => $frozenhp,
                'attack' => (float) $attempt->frozenplayerattack,
                'armor' => (float) $attempt->frozenplayerarmor,
            ];
        }

        return [
            'maxhp' => (int) $instance->basestudenthp,
            'attack' => 1.0,
            'armor' => 0.0,
        ];
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
     * Returns the multiplier on the opponent's max HP for a difficulty. Unknown values fall back to 1.0 (Normal).
     *
     * @param string $difficulty One of the constants::DIFFICULTY_* values.
     * @return float
     */
    public static function difficulty_hp_factor(string $difficulty): float {
        return self::DIFFICULTY_HP_FACTORS[$difficulty] ?? 1.0;
    }

    /**
     * Returns the multiplier on the student's own unit of damage for a difficulty. Unknown values fall back to 1.0.
     *
     * @param string $difficulty One of the constants::DIFFICULTY_* values.
     * @return float
     */
    public static function difficulty_player_factor(string $difficulty): float {
        return self::DIFFICULTY_PLAYER_FACTORS[$difficulty] ?? 1.0;
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
