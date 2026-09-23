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
 * Encode/decode helper for the server-decided question outcomes of the current phase.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Reads and writes the JSON array stored in playerpuzzle_attempts.questionresults: every
 * question outcome validate_answer.php decided during the current phase, in order, as
 * {side, correct, counted}. The client's event log only marks where a question happened and
 * how it ended (answered, skipped...), never whether an answer was right — the replay pairs
 * each "answered" marker with the next outcome here, so a forged log cannot turn a wrong
 * answer into a critical hit.
 *
 * counted says whether the outcome advanced the attempt's questions_total (only a real
 * player answer does), which the replay needs to reproduce the boss-revive rule.
 */
class question_results {
    /**
     * Returns the stored outcomes with one more appended, ready to persist.
     *
     * @param string|null $raw Current raw column value.
     * @param string $side 'player' or 'boss'.
     * @param bool $correct Whether the answer was right.
     * @param bool $counted Whether this outcome advanced questions_total.
     * @return string JSON-encoded list.
     */
    public static function append(?string $raw, string $side, bool $correct, bool $counted): string {
        $results = self::decode($raw);
        $results[] = ['side' => $side, 'correct' => $correct, 'counted' => $counted];

        return json_encode($results);
    }

    /**
     * Decodes the stored outcomes.
     *
     * @param string|null $raw Raw column value (null when nothing was decided yet this phase).
     * @return array List of ['side' => string, 'correct' => bool, 'counted' => bool], or an
     *  empty array when there is none or it fails to decode.
     */
    public static function decode(?string $raw): array {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
