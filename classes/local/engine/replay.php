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
 * Server-side deterministic replay of a finished phase, re-deriving the true damage/coin
 * totals from the recorded seed and event log instead of trusting the client's own report.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

use mod_playerpuzzle\local\attempt_consumables;
use mod_playerpuzzle\local\move_log;
use mod_playerpuzzle\local\question_results;

/**
 * Re-simulates a phase's whole board/combat history from its seed and recorded event log
 * (board_engine.php/combat_engine.php do the actual mechanics; this class only orchestrates
 * the turn-by-turn sequence they run in) and derives the boss damage and coin totals that
 * really happened — the same quantities save_progress.php/advance_phase.php currently accept
 * from the client, sanity-clamped but never truly verified.
 *
 * derive() never throws and never blocks a match from closing: any condition it cannot
 * resolve with confidence — a gap in the record, an engine-version mismatch, a malformed
 * event, a pathologically long cascade — returns null rather than a guessed value, and the
 * caller falls back to exactly today's plausibility-ceiling behaviour for that call. A
 * definitive result is only ever returned once the simulation reaches a real terminal state
 * (the boss dying for good, or the player doing so) by consuming the recorded events; running
 * out of events with neither side dead is treated the same as any other gap, never as a
 * partial answer, since a partial replay could under-credit a match that genuinely finished
 * — the one outcome this whole mechanism exists to avoid.
 */
class replay {
    /**
     * Ceiling on destroy/refill rounds within a single swap's cascade — a real board can only
     * chain so many times before running out of matches; this exists purely as a backstop
     * against a corrupted or deliberately pathological event log, never expected to bind on
     * genuine play.
     */
    private const MAX_CASCADE_ROUNDS = 200;

    /**
     * Ceiling on player+boss turn pairs processed for one phase — generous enough that no
     * genuine fight gets anywhere close to it, and a backstop against an event log crafted to
     * make the simulation loop indefinitely.
     */
    private const MAX_TURNS = 500;

    /** @var int Board dimension (8x8, board.js). */
    private const ROWS = 8;

    /** @var int Board dimension (8x8, board.js). */
    private const COLS = 8;

    /**
     * Attempts to derive the true damage/coin totals for the phase an attempt just finished,
     * from its recorded seed and event log.
     *
     * @param \stdClass $attempt The attempt row (already at/about to move to a final state, or
     *  about to advance phase) — rngseed/movelog/moveseq/engineversion/frozen* fields, plus
     *  currentlevel/currentphase/difficulty/questions_total/isdemo, are all read from it.
     * @param \stdClass $playerpuzzle The instance record (minquestions/basestudenthp read from
     *  it — the latter is not itself frozen per phase, unlike the boss-side config; see
     *  simulate()'s own note on why that is safe).
     * @return array|null ['damage' => int, 'playergold' => int, 'bossgold' => int], or null
     *  when the phase could not be conclusively re-derived — the caller should fall back to
     *  its own existing plausibility check in that case.
     */
    public static function derive(\stdClass $attempt, \stdClass $playerpuzzle): ?array {
        if ((bool) $attempt->isdemo) {
            // A Demo attempt grants nothing and costs nothing to fake — not worth the CPU.
            return null;
        }

        $currentversion = (int) get_config('mod_playerpuzzle', 'version');
        if ((int) $attempt->engineversion !== $currentversion) {
            // The rules may have changed since this phase started (a balancing edit, a bug
            // fix to board_rules.js/combat_rules.js) — replaying it against today's engine
            // could disagree with what the client legitimately experienced under yesterday's.
            return null;
        }

        try {
            return self::simulate($attempt, $playerpuzzle);
        } catch (\Throwable $e) {
            debugging(
                'mod_playerpuzzle replay could not verify attempt ' . $attempt->id . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return null;
        }
    }

    /**
     * Runs the actual turn-by-turn simulation. Every exit point that is not a genuine
     * boss-dead-for-good or player-dead terminal state returns null rather than a value — see
     * this class's own docblock for why a partial result is never an acceptable substitute.
     *
     * @param \stdClass $attempt The attempt row.
     * @param \stdClass $playerpuzzle The instance record.
     * @return array|null See derive().
     */
    private static function simulate(\stdClass $attempt, \stdClass $playerpuzzle): ?array {
        $rng = prng::create((int) $attempt->rngseed);
        $grid = board_engine::generate_grid(self::ROWS, self::COLS, null, $rng);

        $level = (int) $attempt->currentlevel;
        $phase = (int) $attempt->currentphase;
        $difficulty = (string) $attempt->difficulty;

        $config = [
            'baseDamage' => combat::apply_difficulty(
                combat::calculate_boss_hp((int) $attempt->frozenbossdamage, $level, $phase),
                $difficulty
            ),
            'coinGain' => (int) $attempt->frozencoingain,
            'coinFactor' => combat::difficulty_coin_factor($difficulty),
        ];
        $maxbosshp = combat::apply_difficulty(
            combat::calculate_boss_hp((int) $attempt->frozenbasebosshp, $level, $phase),
            $difficulty
        );
        // Note: basestudenthp is not one of the columns frozen at phase start (only the boss-side
        // config feeds the damage/coin totals this derives): a teacher edit to it mid-phase
        // could make this value disagree with what the live client used for its own
        // maxPlayerHp. The only consequence is that a "player defeated" terminal state might
        // be reached earlier or later here than it truly was client-side — this never affects
        // the boss-damage/coin totals derived on a win, and on a loss it can only ever make
        // this method less likely to reach a confident terminal state (falling back to the
        // existing plausibility check), never more likely to return a wrong value.
        $maxplayerhp = combat::calculate_student_hp((int) $playerpuzzle->basestudenthp, $level, $phase);

        $state = [
            'currentPlayerHp' => (float) $maxplayerhp, 'maxPlayerHp' => (float) $maxplayerhp,
            'currentHp' => (float) $maxbosshp, 'maxBossHp' => (float) $maxbosshp,
            'playerMultiplier' => 1.0, 'bossMultiplier' => 1.0,
            'playerShieldMeter' => 0, 'playerShieldReady' => false,
            'bossShieldMeter' => 0, 'bossShieldReady' => false,
            'playerPoisonMeter' => 0, 'playerPoisonRounds' => 0,
            'bossPoisonMeter' => 0, 'bossPoisonRounds' => 0,
            'playerMana' => 0, 'bossMana' => 0,
            'playerGold' => 0.0, 'bossGold' => 0.0,
        ];

        $outcomes = question_results::decode($attempt->questionresults);
        $countedinphase = count(array_filter($outcomes, static fn(array $outcome): bool => !empty($outcome['counted'])));
        // Everything the turn loop consumes as it goes. questions_total is a lifetime counter on
        // the attempt, never reset per phase: subtracting this phase's own counted outcomes
        // gives the value it had when the phase started, advanced below one outcome at a time,
        // so needs_revive() sees what the live client's own mirror saw at each point.
        $sim = [
            'events' => move_log::decode($attempt->movelog),
            'eventindex' => 0,
            'outcomes' => $outcomes,
            'outcomeindex' => 0,
            'questionstotal' => max(0, (int) $attempt->questions_total - $countedinphase),
            'playerpuzzleid' => (int) ($playerpuzzle->id ?? 0),
            'poolempty' => null,
            'serveruses' => attempt_consumables::get_uses_by_type((int) $attempt->id),
            'loggeduses' => array_fill_keys(move_log::COMBAT_CONSUMABLES, 0),
        ];
        $minquestions = (int) $playerpuzzle->minquestions;
        $turn = 'player';

        for ($turncount = 0; $turncount < self::MAX_TURNS; $turncount++) {
            if ($turn === 'player') {
                // Consumables are only usable at the start of the player's turn, before
                // their move, so that is the only place their events can appear.
                while (($sim['events'][$sim['eventindex']]['type'] ?? null) === 'consumable') {
                    $kind = $sim['events'][$sim['eventindex']]['kind'] ?? null;
                    if (!in_array($kind, move_log::COMBAT_CONSUMABLES, true)) {
                        return null;
                    }
                    $sim['eventindex']++;
                    $sim['loggeduses'][$kind]++;
                    self::apply_consumable($kind, $state, $config);

                    if ($state['currentHp'] <= 0) {
                        if (combat_engine::needs_revive($minquestions, $sim['questionstotal'])) {
                            $state['currentHp'] = (float) combat_engine::revive_hp($maxbosshp);
                        } else {
                            return self::result($maxbosshp, $state, $sim);
                        }
                    }
                }

                $event = $sim['events'][$sim['eventindex']] ?? null;
                if ($event === null) {
                    // Nothing more recorded — either a genuine gap (a lost checkpoint, a
                    // sendBeacon that never arrived) or the phase simply is not finished from
                    // this log's point of view. Either way, inconclusive, not a divergence.
                    return null;
                }
                if (($event['type'] ?? null) !== 'move' || !isset($event['r1'], $event['c1'], $event['r2'], $event['c2'])) {
                    return null;
                }
                $sim['eventindex']++;

                board_engine::swap_in_grid($grid, $event['r1'], $event['c1'], $event['r2'], $event['c2']);
                if (!self::resolve_cascade($grid, $rng, $state, $config, $sim, 'player')) {
                    return null;
                }

                if ($state['currentHp'] <= 0) {
                    if (combat_engine::needs_revive($minquestions, $sim['questionstotal'])) {
                        $state['currentHp'] = (float) combat_engine::revive_hp($maxbosshp);
                    } else {
                        return self::result($maxbosshp, $state, $sim);
                    }
                }
                if ($state['currentPlayerHp'] <= 0) {
                    return self::result($maxbosshp, $state, $sim);
                }

                $turn = 'boss';
                $tick = combat_engine::resolve_poison_tick(
                    $state['currentHp'],
                    $state['bossPoisonRounds'],
                    (float) $config['baseDamage']
                );
                if ($tick['ticked']) {
                    $state['currentHp'] = $tick['newHp'];
                    $state['bossPoisonRounds'] = $tick['newPoisonRounds'];
                    if ($state['currentHp'] <= 0) {
                        if (combat_engine::needs_revive($minquestions, $sim['questionstotal'])) {
                            $state['currentHp'] = (float) combat_engine::revive_hp($maxbosshp);
                        } else {
                            return self::result($maxbosshp, $state, $sim);
                        }
                    }
                }
            } else {
                $move = board_engine::find_move($grid, self::ROWS, self::COLS, 3)
                    ?? board_engine::find_move($grid, self::ROWS, self::COLS, null);
                if ($move === null) {
                    board_engine::shuffle_until_valid($grid, self::ROWS, self::COLS, $rng);
                    $move = board_engine::find_move($grid, self::ROWS, self::COLS, 3)
                        ?? board_engine::find_move($grid, self::ROWS, self::COLS, null);
                    if ($move === null) {
                        // Reaching here means something is structurally wrong, not a real game
                        // state — shuffle_until_valid() guarantees a move exists afterwards.
                        return null;
                    }
                }
                board_engine::swap_in_grid($grid, $move['r1'], $move['c1'], $move['r2'], $move['c2']);
                if (!self::resolve_cascade($grid, $rng, $state, $config, $sim, 'boss')) {
                    return null;
                }

                if ($state['currentPlayerHp'] <= 0) {
                    return self::result($maxbosshp, $state, $sim);
                }

                $turn = 'player';
                $tick = combat_engine::resolve_poison_tick(
                    $state['currentPlayerHp'],
                    $state['playerPoisonRounds'],
                    (float) $config['baseDamage']
                );
                if ($tick['ticked']) {
                    $state['currentPlayerHp'] = $tick['newHp'];
                    $state['playerPoisonRounds'] = $tick['newPoisonRounds'];
                    if ($state['currentPlayerHp'] <= 0) {
                        return self::result($maxbosshp, $state, $sim);
                    }
                }
                if (!board_engine::has_available_move($grid, self::ROWS, self::COLS)) {
                    board_engine::shuffle_until_valid($grid, self::ROWS, self::COLS, $rng);
                }
            }
        }

        // Exceeded the turn cap without reaching a terminal state — pathological input.
        return null;
    }

    /**
     * Resolves one swap's full cascade (destroy -> gravity -> re-check, repeated until no
     * match remains), applying damage and consuming the question the simulation itself
     * determines was triggered along the way — mirrors board.js::checkMatches()'s own
     * recursive settle loop, run synchronously instead of through Phaser's delayed calls.
     *
     * @param array $grid The board grid (mutated in place).
     * @param \Closure $rng The phase's seeded PRNG (mutated in place via its own closure state).
     * @param array $state Combat state (mutated in place).
     * @param array $config Keys baseDamage/coinGain/coinFactor.
     * @param array $sim The simulation context built by simulate() (mutated in place).
     * @param string $turn 'player' or 'boss' — whose swap this cascade belongs to.
     * @return bool False when the cascade could not be resolved (the swap itself matched
     *  nothing, a question that does not reconcile, or the round cap was exceeded).
     */
    private static function resolve_cascade(
        array &$grid,
        \Closure $rng,
        array &$state,
        array $config,
        array &$sim,
        string $turn
    ): bool {
        for ($round = 0; $round < self::MAX_CASCADE_ROUNDS; $round++) {
            $todestroy = [];
            $matchgroups = [];
            board_engine::check_horizontal($grid, self::ROWS, self::COLS, $todestroy, $matchgroups);
            board_engine::check_vertical($grid, self::ROWS, self::COLS, $todestroy, $matchgroups);
            if (count($todestroy) === 0) {
                // A swap that matches nothing is never a real move: the live client swaps it
                // back and never records it, and the boss only ever picks a matching swap. On
                // round 0 it therefore proves this simulated board has diverged from the one
                // actually played, and every value derived from here on would be fiction.
                return $round > 0;
            }

            $destroyedtypes = [];
            foreach ($todestroy as $cell) {
                $destroyedtypes[] = $grid[$cell['row']][$cell['col']];
            }
            foreach ($todestroy as $cell) {
                $grid[$cell['row']][$cell['col']] = null;
            }

            // Note: combat_engine::resolve_match_effects() wants each group's piece COUNT
            // (['type', 'size']), not board_engine's own ['type', 'cells'] shape — the same
            // reshape board.js does when it maps board_rules groups onto real Phaser pieces
            // before calling combat_rules with {type, size: pieces.length}.
            $sizedgroups = array_map(
                static fn(array $group): array => ['type' => $group['type'], 'size' => count($group['cells'])],
                $matchgroups
            );

            $result = combat_engine::resolve_match_effects($turn, $destroyedtypes, $sizedgroups, $state, $config);
            $state = $result['state'];

            if ($result['damageDealt'] > 0) {
                $scaled = $result['damageDealt'] * ($turn === 'player' ? $state['playerMultiplier'] : 1);
                if ($turn === 'player') {
                    $applied = combat_engine::resolve_damage($state['currentHp'], $state['bossShieldReady'], $scaled);
                    $state['currentHp'] = $applied['newHp'];
                    if ($applied['shieldConsumed']) {
                        $state['bossShieldReady'] = false;
                    }
                } else {
                    $applied = combat_engine::resolve_damage(
                        $state['currentPlayerHp'],
                        $state['playerShieldReady'],
                        $scaled
                    );
                    $state['currentPlayerHp'] = $applied['newHp'];
                    if ($applied['shieldConsumed']) {
                        $state['playerShieldReady'] = false;
                    }
                }
            }

            if ($result['questionTriggered']) {
                if (!self::consume_question($sim, $result['triggeredBy'], $state, (float) $config['baseDamage'])) {
                    return false;
                }
            }

            board_engine::apply_gravity_to_grid($grid, self::ROWS, self::COLS, $rng);
        }

        return false;
    }

    /**
     * Consumes the question the simulation just triggered for $side: the next logged event
     * must be that side's question marker, and how it ended decides what (if anything) is
     * applied. An 'answered' question takes its right/wrong outcome from the server's own
     * question_results, never from the client — that is what stops a forged log from
     * turning every wrong answer into a critical hit.
     *
     * @param array $sim The simulation context (mutated in place).
     * @param string $side 'player' or 'boss' — who the simulation says was asked.
     * @param array $state Combat state (mutated in place).
     * @param float $basedamage The phase's baseDamage, for the crit-hit formula.
     * @return bool False when the log and the server's outcomes do not reconcile here.
     */
    private static function consume_question(array &$sim, string $side, array &$state, float $basedamage): bool {
        $event = $sim['events'][$sim['eventindex']] ?? null;
        if (($event['type'] ?? null) !== 'question' || ($event['side'] ?? null) !== $side) {
            return false;
        }
        $sim['eventindex']++;

        switch ($event['outcome'] ?? null) {
            case 'answered':
                $outcome = $sim['outcomes'][$sim['outcomeindex']] ?? null;
                if ($outcome === null || ($outcome['side'] ?? null) !== $side) {
                    return false;
                }
                $sim['outcomeindex']++;
                self::apply_question_outcome($side, (bool) $outcome['correct'], $state, $basedamage);
                if (!empty($outcome['counted'])) {
                    $sim['questionstotal']++;
                }
                return true;

            case 'skipped':
                // Only the player's own question has a Skip button, and skipping costs and
                // earns nothing.
                return $side === 'player';

            case 'unavailable':
                // Only honest when there was truly nothing to draw — otherwise it is a draw
                // call that never came back (or was blocked), which proves nothing either way.
                return self::question_pool_is_empty($sim);

            case 'failed':
                // The player's validation never came back, and the client applied a wrong
                // answer on its own — a penalty only to the one reporting it, so it is safe to
                // take as given. The boss has no such safe default: which way it went is
                // exactly what the missing server outcome would have decided.
                if ($side !== 'player') {
                    return false;
                }
                self::apply_question_outcome('player', false, $state, $basedamage);
                return true;
        }

        return false;
    }

    /**
     * Applies a consumable's effect — mirrors combat.js::applyConsumableEffect(): the shared
     * meter/heal logic, plus the Sword's direct hit on the boss (through its shield, like any
     * other hit).
     *
     * @param string $kind One of move_log::COMBAT_CONSUMABLES.
     * @param array $state Combat state (mutated in place).
     * @param array $config Keys baseDamage/coinGain/coinFactor.
     * @return void
     */
    private static function apply_consumable(string $kind, array &$state, array $config): void {
        $result = combat_engine::resolve_consumable_effect($kind, $state, $config);
        $state = $result['state'];

        if ($kind === 'sword') {
            $applied = combat_engine::resolve_damage(
                $state['currentHp'],
                $state['bossShieldReady'],
                (float) $result['damageAmount']
            );
            $state['currentHp'] = $applied['newHp'];
            if ($applied['shieldConsumed']) {
                $state['bossShieldReady'] = false;
            }
        }
    }

    /**
     * Whether the instance had no approved question to draw, looked up once per replay.
     *
     * @param array $sim The simulation context (caches the answer in place).
     * @return bool
     */
    private static function question_pool_is_empty(array &$sim): bool {
        global $DB;

        if ($sim['poolempty'] === null) {
            $sim['poolempty'] = !$DB->record_exists('playerpuzzle_questions', [
                'playerpuzzleid' => $sim['playerpuzzleid'],
                'approved' => 1,
            ]);
        }

        return $sim['poolempty'];
    }

    /**
     * Applies a question outcome's HP/multiplier effect — mirrors combat.js::openQuestionModal()'s
     * applyResult()/renderBossResult() branches.
     *
     * @param string $side 'player' or 'boss'.
     * @param bool $correct Whether the answer was right.
     * @param array $state Combat state (mutated in place).
     * @param float $basedamage The phase's baseDamage, for the crit-hit formula.
     * @return void
     */
    private static function apply_question_outcome(string $side, bool $correct, array &$state, float $basedamage): void {
        if ($side === 'player') {
            if ($correct) {
                $crit = $basedamage * 3 * $state['playerMultiplier'];
                $applied = combat_engine::resolve_damage($state['currentHp'], $state['bossShieldReady'], $crit);
                $state['currentHp'] = $applied['newHp'];
                if ($applied['shieldConsumed']) {
                    $state['bossShieldReady'] = false;
                }
            } else {
                $applied = combat_engine::resolve_damage($state['currentPlayerHp'], $state['playerShieldReady'], 30.0);
                $state['currentPlayerHp'] = $applied['newHp'];
                if ($applied['shieldConsumed']) {
                    $state['playerShieldReady'] = false;
                }
                $state['playerMultiplier'] = 1.0;
            }
        } else {
            if ($correct) {
                $crit = $basedamage * 3 * $state['bossMultiplier'];
                $applied = combat_engine::resolve_damage($state['currentPlayerHp'], $state['playerShieldReady'], $crit);
                $state['currentPlayerHp'] = $applied['newHp'];
                if ($applied['shieldConsumed']) {
                    $state['playerShieldReady'] = false;
                }
            } else {
                $state['bossMultiplier'] = 1.0;
            }
        }
    }

    /**
     * Builds the final derived result once a terminal state has been reached.
     *
     * Every logged event and every server-decided question outcome must have been consumed by
     * the time the match ends: a leftover outcome is a question the client never placed in
     * the log (an answer hidden as "skipped", say), and leftover events describe a match that
     * kept going after this one ended — either way the log is not the match. Likewise the
     * consumables the log applied must be exactly the ones use_stock.php counted: a use missing
     * from the log (a Shield armed but never placed) or one the server never authorised would
     * both make this a different fight from the one really played.
     *
     * @param int $maxbosshp The phase's own max boss HP.
     * @param array $state Final combat state.
     * @param array $sim The simulation context.
     * @return array|null ['damage' => int, 'playergold' => int, 'bossgold' => int], or null
     *  when something was left unconsumed.
     */
    private static function result(int $maxbosshp, array $state, array $sim): ?array {
        if ($sim['eventindex'] !== count($sim['events']) || $sim['outcomeindex'] !== count($sim['outcomes'])) {
            return null;
        }
        foreach ($sim['loggeduses'] as $kind => $count) {
            if ($count !== (int) ($sim['serveruses'][$kind] ?? 0)) {
                return null;
            }
        }

        return [
            'damage' => max(0, $maxbosshp - max(0, (int) round($state['currentHp']))),
            'playergold' => max(0, (int) round($state['playerGold'])),
            'bossgold' => max(0, (int) round($state['bossGold'])),
        ];
    }
}
