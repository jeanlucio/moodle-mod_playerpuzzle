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
 * Encode/decode/validate helper for the recorded-moves checkpoint.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Reads and writes the JSON array stored in playerpuzzle_attempts.movelog: the board swaps
 * (each a plain {r1,c1,r2,c2} coordinate pair) recorded since the last accepted checkpoint,
 * for a future server-side replay to derive the true match outcome instead of trusting the
 * client's own reported totals.
 *
 * Deliberately records swaps only — never consumable uses or question answers, both of
 * which are already independently authoritative through their own web services
 * (use_stock.php/validate_answer.php) and need no RNG-replay of their own.
 */
class move_log {
    /**
     * Maximum swaps accepted in a single checkpoint call — the periodic checkpoint fires
     * every ~10s, so this is a generous multiple of what a genuine player could produce in
     * that window; rejecting outright rather than silently truncating, since a value this
     * far out of range signals a bug or a forged request, not an edge case to tolerate.
     */
    public const MAX_MOVES_PER_CHECKPOINT = 200;

    /** @var int Board dimension (8x8, board.js — combat_state::BOARD_CELLS is 8*8=64). */
    private const BOARD_DIMENSION = 8;

    /**
     * Whether a client-reported move log has a shape safe to store: within the per-checkpoint
     * size cap, and every coordinate within the board's own bounds. Moodle's own parameter
     * validation already guarantees each field is an integer before this ever runs.
     *
     * @param array $movelog Raw value from the web service parameter, already type-validated.
     * @return bool
     */
    public static function is_valid(array $movelog): bool {
        if (count($movelog) > self::MAX_MOVES_PER_CHECKPOINT) {
            return false;
        }

        foreach ($movelog as $move) {
            foreach (['r1', 'c1', 'r2', 'c2'] as $key) {
                if ($move[$key] < 0 || $move[$key] >= self::BOARD_DIMENSION) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Builds the JSON payload persisted to movelog.
     *
     * @param array $movelog List of {r1,c1,r2,c2} swaps, already validated.
     * @return string|null JSON-encoded list, or null when there is nothing to store.
     */
    public static function encode(array $movelog): ?string {
        return $movelog === [] ? null : json_encode($movelog);
    }

    /**
     * Decodes a stored move log back into a plain array.
     *
     * @param string|null $raw Raw JSON from the attempt row (null when nothing is pending).
     * @return array Decoded list of swaps, or an empty array if there is none or it fails to
     *  decode — a broken log is treated the same as an empty one, never blocking anything.
     */
    public static function decode(?string $raw): array {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
