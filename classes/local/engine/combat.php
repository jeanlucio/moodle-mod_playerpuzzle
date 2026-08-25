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
}
