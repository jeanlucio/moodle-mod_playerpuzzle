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
 * Encode/decode helper for the in-progress combat snapshot.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Reads and writes the JSON blob stored in playerpuzzle_attempts.combatstate.
 *
 * The snapshot is opaque to the server: it is only ever read back whole by the same client
 * code that wrote it, to redraw the board/HUD on a reload instead of always starting the
 * phase fresh. It carries no weight in any anti-cheat decision — a win/loss claim is still
 * independently validated by save_progress/advance_phase from the server's own boss HP
 * formula, never from this blob, so its contents are never inspected here beyond the board's
 * own size.
 */
class combat_state {
    /**
     * Board size (8x8, board.js) — the only structural check applied before storing a
     * reported snapshot.
     */
    public const BOARD_CELLS = 64;

    /**
     * Whether a client-reported board grid has the right shape to be stored.
     *
     * @param mixed $boardgrid Raw value from the web service parameter.
     * @return bool True if it is an array of exactly BOARD_CELLS entries.
     */
    public static function is_valid_board(mixed $boardgrid): bool {
        return is_array($boardgrid) && count($boardgrid) === self::BOARD_CELLS;
    }

    /**
     * Builds the JSON payload persisted to combatstate.
     *
     * @param array $boardgrid Flat array of BOARD_CELLS piece types.
     * @param array $meters HP/meters/turn, keyed by field name, passed through as reported.
     * @return string JSON-encoded snapshot.
     */
    public static function encode(array $boardgrid, array $meters): string {
        return json_encode(array_merge(['boardgrid' => $boardgrid], $meters));
    }

    /**
     * Decodes a stored snapshot back into an array for the client.
     *
     * @param string|null $raw Raw JSON from the attempt row (null when there is no fight in
     *  progress for the current phase).
     * @return array|null Decoded snapshot, or null if there is none or it fails to decode —
     *  decoding never blocks the page from loading; a broken snapshot degrades to a fresh
     *  phase start, same as if none had been saved.
     */
    public static function decode(?string $raw): ?array {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
