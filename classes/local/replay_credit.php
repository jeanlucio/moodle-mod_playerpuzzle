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
 * Shared save_progress.php/advance_phase.php helper deciding how a finished match counts,
 * from the server-side replay rather than the client's claim.
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
use mod_playerpuzzle\local\engine\security;

/**
 * Turns the replay into a verdict for a finished match. A match the replay verifies counts
 * exactly as the replay says — won or lost, with the damage and coins it derived — whatever
 * the client claimed (a replay_diverged event records a disagreement). A match it cannot
 * verify never counts as a win: a claimed victory restarts the phase within the same attempt
 * (an honest player who hit a rare failure loses only that phase's progress), up to
 * MAX_PHASE_RESTARTS times, after which it counts as a defeat; a claimed defeat is simply
 * taken, since nobody gains anything by forging one. Either way, only what the replay can
 * vouch for is credited. A Demo is never verified (it grants nothing) and keeps the claim.
 */
class replay_credit {
    /**
     * Restarts of one phase allowed after an unverifiable victory before it counts as a
     * defeat — enough for an honest player hit by a rare failure, while a client forging
     * victories to escape a losing fight gets only this many fresh starts, not an unlimited
     * supply.
     */
    public const MAX_PHASE_RESTARTS = 2;

    /**
     * Decides how a finished match counts.
     *
     * @param \stdClass $attempt The attempt row, with the phase's final events already merged
     *  (read-only here; not persisted).
     * @param \stdClass $playerpuzzle The instance record.
     * @param context_module $context Module context, for the events.
     * @param bool $claimedvictory Whether the client reports a win.
     * @param int $claimeddamage Client-reported damage dealt to the boss this phase.
     * @param int $claimedplayergold Client-reported player gold earned this phase.
     * @param int $claimedbossgold Client-reported boss gold earned this phase.
     * @return array ['outcome' => 'won'|'lost'|'restart', 'damage' => int,
     *  'playergold' => int, 'bossgold' => int] — the values to credit.
     */
    public static function verdict(
        \stdClass $attempt,
        \stdClass $playerpuzzle,
        context_module $context,
        bool $claimedvictory,
        int $claimeddamage,
        int $claimedplayergold,
        int $claimedbossgold
    ): array {
        if ((bool) $attempt->isdemo) {
            return [
                'outcome' => $claimedvictory ? 'won' : 'lost',
                'damage' => $claimeddamage,
                'playergold' => $claimedplayergold,
                'bossgold' => $claimedbossgold,
            ];
        }

        $derived = replay::derive($attempt, $playerpuzzle);
        if ($derived !== null) {
            if (
                $derived['damage'] !== $claimeddamage
                || $derived['playergold'] !== $claimedplayergold
                || $derived['bossgold'] !== $claimedbossgold
            ) {
                replay_diverged::create([
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
                ])->trigger();
            }

            return [
                'outcome' => $derived['bossdefeated'] ? 'won' : 'lost',
                'damage' => $derived['damage'],
                'playergold' => $derived['playergold'],
                'bossgold' => $derived['bossgold'],
            ];
        }

        replay_inconclusive::create([
            'objectid' => $attempt->id,
            'context' => $context,
            'other' => [
                'claimeddamage' => $claimeddamage,
                'claimedplayergold' => $claimedplayergold,
                'claimedbossgold' => $claimedbossgold,
            ],
        ])->trigger();

        $restart = $claimedvictory && (int) $attempt->phaserestarts < self::MAX_PHASE_RESTARTS;

        return [
            'outcome' => $restart ? 'restart' : 'lost',
            'damage' => $restart ? 0 : self::verified_damage($attempt, $playerpuzzle),
            'playergold' => 0,
            'bossgold' => 0,
        ];
    }

    /**
     * The damage the replay can vouch for in a match it could not verify to the end: what the
     * phase had dealt at the last point its log still replays consistently, or nothing.
     *
     * @param \stdClass $attempt The attempt row.
     * @param \stdClass $playerpuzzle The instance record.
     * @return int
     */
    private static function verified_damage(\stdClass $attempt, \stdClass $playerpuzzle): int {
        $snapshot = replay::snapshot($attempt, $playerpuzzle);
        if ($snapshot === null) {
            return 0;
        }

        $state = $snapshot['state'];
        return max(0, (int) round($state['maxBossHp']) - max(0, (int) round($state['currentHp'])));
    }

    /**
     * Restarts the attempt's current phase from scratch, keeping the attempt: a new seed and
     * an empty log (see security::seed_replay_state()), a fresh board and full HP, the phase's
     * coins and per-phase consumable uses cleared, and one more restart counted.
     *
     * @param \stdClass $attempt The attempt row (written in place; caller persists).
     * @param \stdClass $playerpuzzle The instance record.
     * @return void
     */
    public static function restart_phase(\stdClass $attempt, \stdClass $playerpuzzle): void {
        security::seed_replay_state($attempt, $playerpuzzle);
        $attempt->combatstate = null;
        $attempt->currentquestionid = 0;
        $attempt->phaserestarts = (int) $attempt->phaserestarts + 1;
        coin_ledger::reset($attempt);
        attempt_consumables::reset_attempt((int) $attempt->id);
    }
}
