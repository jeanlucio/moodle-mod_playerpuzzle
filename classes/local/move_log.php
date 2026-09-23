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
 * Encode/decode/validate helper for the recorded combat-event checkpoint.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Reads and writes the JSON array stored in playerpuzzle_attempts.movelog: an ordered log of
 * combat events for the whole current phase, kept cumulative across every checkpoint (never
 * overwritten) so a future server-side replay can walk it from the phase's own starting board
 * forward, not just from whatever the most recent checkpoint happened to capture.
 *
 * Three event shapes share the log, distinguished by 'type':
 * - {type: 'move', r1, c1, r2, c2}: a confirmed player board swap. The board/RNG side of a
 *   replay is fully re-derivable from these alone via board_engine.php.
 * - {type: 'question', side, outcome}: a mana-triggered question for 'player' or 'boss' was
 *   closed. It only marks *where* in the turn sequence that happened and how it ended —
 *   'answered' (validate_answer.php decided it), 'skipped' (the player closed it unanswered),
 *   'unavailable' (no question could be drawn) or 'failed' (the validation call never came
 *   back). Whether an answer was right is never taken from here: the replay pairs each
 *   'answered' marker with the outcome the server itself stored (question_results.php).
 * - {type: 'consumable', kind}: a Potion/Shield/Magic/Sword was used at the start of the
 *   player's turn, before their next move — the only moment the game allows it, so the replay
 *   can apply its effect at exactly that point. Each use is also counted by use_stock.php
 *   itself (attempt_consumables.php), and the replay requires both to agree. Hint is never
 *   logged: it only reveals text.
 */
class move_log {
    /**
     * Maximum events accepted in a single checkpoint call — the periodic checkpoint fires
     * every ~10s, so this is a generous multiple of what a genuine player could produce in
     * that window; rejecting outright rather than silently truncating, since a value this
     * far out of range signals a bug or a forged request, not an edge case to tolerate.
     */
    public const MAX_EVENTS_PER_CHECKPOINT = 200;

    /**
     * Maximum events stored for one phase in total, across every checkpoint combined. A real
     * phase (finite board, finite boss HP) realistically produces at most a few hundred
     * events; this is a generous backstop against a client hammering the endpoint with
     * checkpoint after checkpoint solely to grow the stored log without bound, never a limit
     * genuine play should come near. Checked and rejected outright rather than silently
     * truncated, for the same reason as MAX_EVENTS_PER_CHECKPOINT — a truncated log would
     * quietly corrupt every replay attempted against it from then on.
     */
    public const MAX_EVENTS_PER_PHASE = 5000;

    /** @var int Board dimension (8x8, board.js — combat_state::BOARD_CELLS is 8*8=64). */
    private const BOARD_DIMENSION = 8;

    /** @var string[] Event types this log accepts. */
    private const VALID_TYPES = ['move', 'question', 'consumable'];

    /** @var string[] Valid values for a 'question' event's side. */
    private const VALID_SIDES = ['player', 'boss'];

    /** @var string[] Valid values for a 'question' event's outcome. */
    public const VALID_OUTCOMES = ['answered', 'skipped', 'unavailable', 'failed'];

    /** @var string[] Consumable kinds that change combat state, and so are logged. */
    public const COMBAT_CONSUMABLES = ['potion', 'shield', 'magic', 'sword'];

    /**
     * Whether a client-reported batch of events has a shape safe to store: within the
     * per-checkpoint size cap, and every event internally consistent for its own type.
     * Moodle's own parameter validation already guarantees each present field has the right
     * scalar type before this ever runs — this only checks which fields are present and their
     * value ranges.
     *
     * @param array $events Raw value from the web service parameter, already type-validated.
     * @return bool
     */
    public static function is_valid(array $events): bool {
        if (count($events) > self::MAX_EVENTS_PER_CHECKPOINT) {
            return false;
        }

        foreach ($events as $event) {
            if (!self::is_valid_event($event)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates a single event against the shape its own declared type requires.
     *
     * @param mixed $event One element of the raw events array.
     * @return bool
     */
    private static function is_valid_event(mixed $event): bool {
        if (!is_array($event) || !isset($event['type']) || !in_array($event['type'], self::VALID_TYPES, true)) {
            return false;
        }

        if ($event['type'] === 'move') {
            foreach (['r1', 'c1', 'r2', 'c2'] as $key) {
                if (!isset($event[$key]) || $event[$key] < 0 || $event[$key] >= self::BOARD_DIMENSION) {
                    return false;
                }
            }
            return true;
        }

        if ($event['type'] === 'consumable') {
            return isset($event['kind']) && in_array($event['kind'], self::COMBAT_CONSUMABLES, true);
        }

        return isset($event['side'], $event['outcome'])
            && in_array($event['side'], self::VALID_SIDES, true)
            && in_array($event['outcome'], self::VALID_OUTCOMES, true);
    }

    /**
     * Merges a client batch into the stored log by position: the batch says at which index of
     * the phase's log its first event sits, and only the part the server does not already
     * have is appended. A resend (the network retried, the response was lost, a beacon raced
     * a periodic checkpoint) therefore never duplicates events nor drops the new ones sent
     * along with it. A batch starting past the end of the stored log would leave a hole, so
     * it is ignored — the client resends from the stored count on its next checkpoint.
     *
     * @param array $existing Already-stored events for this phase, decoded.
     * @param int $offset Index in the phase's log of the batch's first event.
     * @param array $incoming The batch, already validated.
     * @return array The combined, ordered event list.
     */
    public static function merge(array $existing, int $offset, array $incoming): array {
        $count = count($existing);
        if ($offset < 0 || $offset > $count) {
            return $existing;
        }

        return array_merge($existing, array_slice($incoming, $count - $offset));
    }

    /**
     * Merges a validated client batch into an attempt's stored log (see merge()), keeping
     * moveseq equal to the number of events stored — the offset the client continues from.
     *
     * @param \stdClass $attempt The attempt row (movelog/moveseq updated in place, not saved).
     * @param int $offset Index in the phase's log of the batch's first event.
     * @param array $incoming The batch, already validated with is_valid().
     * @return bool False, leaving the attempt untouched, when the merged log would exceed the
     *  whole-phase budget.
     */
    public static function merge_into_attempt(\stdClass $attempt, int $offset, array $incoming): bool {
        $merged = self::merge(self::decode($attempt->movelog), $offset, $incoming);
        if (count($merged) > self::MAX_EVENTS_PER_PHASE) {
            return false;
        }

        $attempt->movelog = self::encode($merged);
        $attempt->moveseq = count($merged);

        return true;
    }

    /**
     * Builds the JSON payload persisted to movelog.
     *
     * @param array $events The full per-phase event list, already validated.
     * @return string|null JSON-encoded list, or null when there is nothing to store.
     */
    public static function encode(array $events): ?string {
        return $events === [] ? null : json_encode($events);
    }

    /**
     * Decodes a stored event log back into a plain array.
     *
     * @param string|null $raw Raw JSON from the attempt row (null when nothing is stored yet).
     * @return array Decoded list of events, or an empty array if there is none or it fails to
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
