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
 * Constants shared across PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Game modes, grade methods and difficulties. Autoloaded, so they exist wherever the
 * plugin's code runs without lib.php having been included first.
 */
final class constants {
    /** @var string Campaign game mode: levels/phases progression with server-scaled HP. */
    public const GAMEMODE_CAMPAIGN = 'campaign';

    /** @var string Single-match game mode: one self-contained, repeatable match. */
    public const GAMEMODE_SINGLE = 'single';

    /** @var int Grade method: highest score among all matches. */
    public const GRADE_HIGHEST = 1;

    /** @var int Grade method: average of the matches actually played. */
    public const GRADE_AVERAGE = 2;

    /** @var int Grade method: score of the first match only. */
    public const GRADE_FIRST = 3;

    /** @var int Grade method: score of the most recent match only. */
    public const GRADE_LAST = 4;

    /**
     * @var int Grade method: sum of match scores divided by max_single_matches, so an unplayed
     *  match counts as zero. Requires max_single_matches to not be unlimited.
     */
    public const GRADE_AVERAGE_ALL = 5;

    /** @var string Difficulty: halves the boss HP/damage and the coin reward. */
    public const DIFFICULTY_EASY = 'easy';

    /** @var string Difficulty: the configured boss HP/damage and coin reward, unchanged. */
    public const DIFFICULTY_NORMAL = 'normal';

    /** @var string Difficulty: doubles the boss HP/damage and triples the coin reward. */
    public const DIFFICULTY_HARD = 'hard';
}
