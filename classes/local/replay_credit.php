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
 * Shared save_progress.php/advance_phase.php helper deciding whether to trust the replay's
 * own derived totals or the client's claimed ones.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context_module;
use mod_playerpuzzle\event\replay_diverged;
use mod_playerpuzzle\event\replay_inconclusive;
use mod_playerpuzzle\local\engine\replay;

/**
 * Wraps replay::derive(): when it reaches a conclusive result, that value is what gets
 * credited (a replay_diverged event is fired first if it disagrees with the client's own
 * claim, purely for observability — the derived value is credited either way). When it
 * cannot (a gap in the record, a version mismatch, a pathological input), the client's own
 * claim is returned unchanged, and a real match fires replay_inconclusive — callers still apply
 * their own existing sanity clamps (the boss-HP cap, the coin ceiling) to whichever value
 * comes back, since a null from replay::derive() means "not verified", not "trust blindly".
 */
class replay_credit {
    /**
     * Resolves the damage/coin totals to credit for a just-finished phase.
     *
     * @param \stdClass $attempt The attempt row (read-only here; not persisted).
     * @param \stdClass $playerpuzzle The instance record.
     * @param context_module $context Module context, for the divergence event.
     * @param int $claimeddamage Client-reported damage dealt to the boss this phase.
     * @param int $claimedplayergold Client-reported player gold earned this phase.
     * @param int $claimedbossgold Client-reported boss gold earned this phase.
     * @return array ['damage' => int, 'playergold' => int, 'bossgold' => int] — the replay's
     *  own derived values when it reached a conclusive result, otherwise the claimed ones.
     */
    public static function resolve(
        \stdClass $attempt,
        \stdClass $playerpuzzle,
        context_module $context,
        int $claimeddamage,
        int $claimedplayergold,
        int $claimedbossgold
    ): array {
        $derived = replay::derive($attempt, $playerpuzzle);
        if ($derived === null) {
            if (!(bool) $attempt->isdemo) {
                replay_inconclusive::create([
                    'objectid' => $attempt->id,
                    'context' => $context,
                    'other' => [
                        'claimeddamage' => $claimeddamage,
                        'claimedplayergold' => $claimedplayergold,
                        'claimedbossgold' => $claimedbossgold,
                    ],
                ])->trigger();
            }
            return [
                'damage' => $claimeddamage,
                'playergold' => $claimedplayergold,
                'bossgold' => $claimedbossgold,
            ];
        }

        if (
            $derived['damage'] !== $claimeddamage
            || $derived['playergold'] !== $claimedplayergold
            || $derived['bossgold'] !== $claimedbossgold
        ) {
            $event = replay_diverged::create([
                'objectid' => $attempt->id,
                'context' => $context,
                'other' => [
                    'claimeddamage' => $claimeddamage,
                    'deriveddamage' => $derived['damage'],
                    'claimedplayergold' => $claimedplayergold,
                    'derivedplayergold' => $derived['playergold'],
                    'claimedbossgold' => $claimedbossgold,
                    'derivedbossgold' => $derived['bossgold'],
                ],
            ]);
            $event->trigger();
        }

        return $derived;
    }
}
