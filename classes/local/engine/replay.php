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
 * caller treats the match as unverified (see replay_credit::verdict()). A definitive result
 * is only ever returned once the simulation reaches a real terminal state (the boss dying
 * for good, or the player doing so) by consuming the recorded events; running
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

    /** @var string What a snapshot reports as the pending question while the opening question is still open. */
    public const OPENING = 'opening';

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
     * @return array|null ['damage' => int, 'playergold' => int, 'bossgold' => int,
     *  'bossdefeated' => bool] (whether the match ended with the boss dead for good, or with
     *  the player), or null when the phase could not be conclusively re-derived.
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
            return self::simulate($attempt, $playerpuzzle, false);
        } catch (\Throwable $e) {
            debugging(
                'mod_playerpuzzle replay could not verify attempt ' . $attempt->id . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return null;
        }
    }

    /**
     * Rebuilds the exact point an in-progress phase reached, for a page reload to resume from:
     * the same simulation derive() runs, stopped where the recorded events end instead of
     * requiring a finished match. The client loads this instead of trusting its own last
     * checkpoint, so a reload resumes on the very board the server will later verify — with
     * the PRNG where the server's own sequence is — and with HP/meters the server computed,
     * not ones the client declared.
     *
     * Two gaps a reload can open are closed on the way, since the server already knows what
     * happened: a question answered but not yet logged takes its stored outcome, and a
     * combat consumable use_stock counted but never logged is applied at the resume point.
     * The events added for either are returned so the caller can store them.
     *
     * @param \stdClass $attempt The in-progress attempt row.
     * @param \stdClass $playerpuzzle The instance record.
     * @return array|null ['grid' => (int|null)[] (flat, row-major; null = a cell emptied by
     *  the pending question's match), 'rngstate' => int, 'turn' => string,
     *  'pendingquestion' => string|null, 'terminal' => bool, 'state' => array,
     *  'eventcount' => int, 'appendedevents' => array, 'unusedoutcomes' => int], or null when
     *  the log does not replay consistently. 'unusedoutcomes' counts the server's recorded
     *  question outcomes the replay did not reach: non-zero means an answer exists that the
     *  log never accounts for.
     */
    public static function snapshot(\stdClass $attempt, \stdClass $playerpuzzle): ?array {
        if ((int) $attempt->engineversion !== (int) get_config('mod_playerpuzzle', 'version')) {
            // Same reason as derive(): today's engine may not rebuild what yesterday's played.
            return null;
        }

        try {
            return self::simulate($attempt, $playerpuzzle, true);
        } catch (\Throwable $e) {
            debugging(
                'mod_playerpuzzle replay could not rebuild attempt ' . $attempt->id . ': ' . $e->getMessage(),
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
     * @param bool $snapshotmode True to stop where the events end and describe that point
     *  (see snapshot()), false to require a finished match (see derive()).
     * @return array|null See derive() / snapshot().
     */
    private static function simulate(\stdClass $attempt, \stdClass $playerpuzzle, bool $snapshotmode): ?array {
        // Counting draws is enough to hand the generator's position to the client: each draw
        // only adds a fixed step to its state (see prng::create()).
        $seed = (int) $attempt->rngseed;
        $inner = prng::create($seed);
        $draws = new \stdClass();
        $draws->count = 0;
        $rng = function () use ($inner, $draws): float {
            $draws->count++;
            return $inner();
        };
        $grid = board_engine::generate_grid(self::ROWS, self::COLS, null, $rng);

        $level = (int) $attempt->currentlevel;
        $phase = (int) $attempt->currentphase;
        $difficulty = (string) $attempt->difficulty;

        $opponent = combat::phase_opponent($attempt, $playerpuzzle);
        $config = [
            'baseDamage' => $opponent['basedamage'],
            'playerBaseDamage' => $opponent['playerbasedamage'],
            'coinGain' => (int) $attempt->frozencoingain,
            'coinFactor' => combat::difficulty_coin_factor($difficulty),
            'bossAttack' => $opponent['bossattack'],
            'bossArmor' => $opponent['bossarmor'],
            'bossPowers' => $opponent['bosspowers'],
            'bossKit' => $opponent['bosskit'],
            'difficulty' => $difficulty,
        ];
        if ($opponent['bossbreathevery'] > 0) {
            $config['bossBreathEvery'] = $opponent['bossbreathevery'];
        }
        $student = combat::phase_student($attempt, $playerpuzzle);
        // Only a phase with something to apply carries the keys, so the config stays what it was for
        // a student with no bonus.
        if ($student['attack'] !== 1.0) {
            $config['playerAttack'] = $student['attack'];
        }
        if ($student['armor'] !== 0.0) {
            $config['playerArmor'] = $student['armor'];
        }
        $maxbosshp = $opponent['maxbosshp'];
        $maxplayerhp = $student['maxhp'];

        $state = combat_engine::initial_state((float) $maxplayerhp, (float) $maxbosshp, $config);

        $outcomes = question_results::decode($attempt->questionresults);
        // Everything the turn loop consumes as it goes. The minimum-questions counter is per
        // match: it starts at zero and advances one counted outcome at a time, so needs_revive()
        // sees what the live client's own mirror saw at each point. (questions_total on the
        // attempt is the lifetime tally the grade reads and plays no part here.)
        $sim = [
            'events' => move_log::decode($attempt->movelog),
            'eventindex' => 0,
            'outcomes' => $outcomes,
            'outcomeindex' => 0,
            'questionstotal' => 0,
            'minquestions' => (int) $playerpuzzle->minquestions,
            'playerpuzzleid' => (int) ($playerpuzzle->id ?? 0),
            'poolempty' => null,
            'serveruses' => attempt_consumables::get_uses_by_type((int) $attempt->id),
            'loggeduses' => array_fill_keys(move_log::COMBAT_CONSUMABLES, 0),
            'snapshotmode' => $snapshotmode,
            'appended' => [],
            'stop' => null,
        ];
        // Builds this method's return value at a stopping point, in whichever mode it runs.
        $finish = function (string $turn, bool $terminal) use (&$grid, &$state, &$sim, $maxbosshp, $seed, $draws): ?array {
            if (!$sim['snapshotmode']) {
                return $terminal ? self::result($maxbosshp, $state, $sim) : null;
            }
            return self::describe($grid, $state, $sim, $turn, $terminal, ($seed + $draws->count * 0x6D2B79F5) & 0xFFFFFFFF);
        };
        $minquestions = (int) $playerpuzzle->minquestions;

        // Every real phase opens with a question that decides who moves first: the student when it is answered
        // right (or no question could be drawn), the opponent when it is answered wrong, failed or skipped.
        $turn = self::consume_opening($sim);
        if ($turn === null) {
            return $sim['stop'] !== null ? $finish('player', false) : null;
        }
        if ($turn === 'boss') {
            // The opponent's turn begins the way it does after a move of the student's, before it moves.
            $start = combat_engine::start_turn('boss', $state, $config);
            $state = $start['state'];
            if ($state['currentPlayerHp'] <= 0) {
                return $finish('boss', true);
            }
        }

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
                            $state['currentHp'] = (float) combat_engine::revive_hp();
                        } else {
                            return $finish('player', true);
                        }
                    }
                }

                $event = $sim['events'][$sim['eventindex']] ?? null;
                if ($event === null) {
                    // Nothing more recorded. Verifying, that is either a genuine gap (a lost
                    // checkpoint, a sendBeacon that never arrived) or a phase not finished from
                    // this log's point of view — inconclusive either way. Rebuilding, it is
                    // exactly where the player resumes, their move pending.
                    if ($snapshotmode && !self::apply_unlogged_consumables($sim, $state, $config)) {
                        return null;
                    }
                    if ($snapshotmode && $state['currentHp'] <= 0) {
                        if (combat_engine::needs_revive($minquestions, $sim['questionstotal'])) {
                            $state['currentHp'] = (float) combat_engine::revive_hp();
                        } else {
                            return $finish('player', true);
                        }
                    }
                    return $finish('player', false);
                }
                if (($event['type'] ?? null) !== 'move' || !isset($event['r1'], $event['c1'], $event['r2'], $event['c2'])) {
                    return null;
                }
                // The board only lets a player swap neighbours (board.js), and move_log refuses any other
                // swap; a stored log that still holds one is not a game the rules allow.
                if (!board_engine::is_adjacent_swap($event['r1'], $event['c1'], $event['r2'], $event['c2'])) {
                    return null;
                }
                $sim['eventindex']++;

                board_engine::swap_in_grid($grid, $event['r1'], $event['c1'], $event['r2'], $event['c2']);
                if (!self::resolve_cascade($grid, $rng, $state, $config, $sim, 'player')) {
                    return $sim['stop'] !== null ? $finish('player', false) : null;
                }

                if ($state['currentHp'] <= 0) {
                    if (combat_engine::needs_revive($minquestions, $sim['questionstotal'])) {
                        $state['currentHp'] = (float) combat_engine::revive_hp();
                    } else {
                        return $finish('player', true);
                    }
                }
                if ($state['currentPlayerHp'] <= 0) {
                    return $finish('player', true);
                }

                $turn = 'boss';
                $start = combat_engine::start_turn('boss', $state, $config);
                $state = $start['state'];
                if ($start['poisonTicked'] && $state['currentHp'] <= 0) {
                    if (combat_engine::needs_revive($minquestions, $sim['questionstotal'])) {
                        $state['currentHp'] = (float) combat_engine::revive_hp();
                    } else {
                        return $finish('player', true);
                    }
                }
                // The opponent's breath lands as its turn begins, before it moves.
                if ($state['currentPlayerHp'] <= 0) {
                    return $finish('boss', true);
                }
            } else {
                $weights = combat_engine::opponent_move_weights($state, $config);
                $move = board_engine::choose_move($grid, self::ROWS, self::COLS, $weights);
                if ($move === null) {
                    board_engine::shuffle_until_valid($grid, self::ROWS, self::COLS, $rng);
                    $move = board_engine::choose_move($grid, self::ROWS, self::COLS, $weights);
                    if ($move === null) {
                        // Reaching here means something is structurally wrong, not a real game
                        // state — shuffle_until_valid() guarantees a move exists afterwards.
                        return null;
                    }
                }
                board_engine::swap_in_grid($grid, $move['r1'], $move['c1'], $move['r2'], $move['c2']);
                if (!self::resolve_cascade($grid, $rng, $state, $config, $sim, 'boss')) {
                    return $sim['stop'] !== null ? $finish('boss', false) : null;
                }

                if ($state['currentPlayerHp'] <= 0) {
                    return $finish('boss', true);
                }

                $turn = 'player';
                $start = combat_engine::start_turn('player', $state, $config);
                $state = $start['state'];
                if ($start['poisonTicked'] && $state['currentPlayerHp'] <= 0) {
                    return $finish('player', true);
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
     * recursive settle loop, run synchronously instead of through the stage's delayed calls.
     *
     * @param array $grid The board grid (mutated in place).
     * @param \Closure $rng The phase's seeded PRNG (mutated in place via its own closure state).
     * @param array $state Combat state (mutated in place).
     * @param array $config Keys baseDamage/coinGain/coinFactor/bossAttack/bossArmor/bossPowers.
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
            // reshape board.js does when it maps board_rules groups onto the real pieces
            // before calling combat_rules with {type, size: pieces.length}.
            $sizedgroups = array_map(
                static fn(array $group): array => ['type' => $group['type'], 'size' => count($group['cells'])],
                $matchgroups
            );

            // The opponent standing on its last HP waiting for the missing questions: the Orb asks them at once.
            $config['orbFull'] = combat_engine::boss_resists($state, $sim['minquestions'], $sim['questionstotal']);
            $result = combat_engine::resolve_match_effects($turn, $destroyedtypes, $sizedgroups, $state, $config);
            $state = $result['state'];

            if ($result['damageDealt'] > 0) {
                $scaled = combat_engine::match_hit_amount($turn, $result['damageDealt'], $state, $config);
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
                    $state['currentHp'] += combat_engine::drain_amount($applied['appliedAmount'], $state, $config);
                }
            }

            if ($result['questionTriggered']) {
                if (!self::consume_question($sim, $result['triggeredBy'], $state, $config)) {
                    return false;
                }
            }

            board_engine::apply_gravity_to_grid($grid, self::ROWS, self::COLS, $rng);
        }

        return false;
    }

    /**
     * Consumes the opening question every real phase starts with, which decides who moves first. It is the first
     * event of the phase and has no effect on the fight: no damage, no multiplier lost. A question answered counts
     * towards the minimum like any other, and what it was answered is taken from the server's own outcome, never
     * from the client.
     *
     * @param array $sim The simulation context (mutated in place).
     * @return string|null Who moves first, 'player' or 'boss', or null when the log does not reconcile or, in
     *  snapshot mode, the question is still open (then $sim['stop'] is set).
     */
    private static function consume_opening(array &$sim): ?string {
        $event = $sim['events'][$sim['eventindex']] ?? null;
        if ($event === null) {
            if (!$sim['snapshotmode']) {
                return null;
            }
            // Answered before a reload but never logged: the server already judged it. Otherwise this is where the
            // student resumes, the question open.
            $next = $sim['outcomes'][$sim['outcomeindex']] ?? null;
            if (($next['side'] ?? null) !== 'player') {
                $sim['stop'] = self::OPENING;
                return null;
            }
            $event = ['type' => 'question', 'side' => 'player', 'outcome' => 'answered'];
            $sim['events'][] = $event;
            $sim['appended'][] = $event;
        }
        if (($event['type'] ?? null) !== 'question' || ($event['side'] ?? null) !== 'player') {
            return null;
        }
        $sim['eventindex']++;

        switch ($event['outcome'] ?? null) {
            case 'answered':
                $outcome = $sim['outcomes'][$sim['outcomeindex']] ?? null;
                if ($outcome === null || ($outcome['side'] ?? null) !== 'player') {
                    return null;
                }
                $sim['outcomeindex']++;
                if (!empty($outcome['counted'])) {
                    $sim['questionstotal']++;
                }
                return !empty($outcome['correct']) ? 'player' : 'boss';

            case 'skipped':
            case 'failed':
                return 'boss';

            case 'unavailable':
                // Only honest when there was truly nothing to draw.
                return self::question_pool_is_empty($sim) ? 'player' : null;
        }

        return null;
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
     * @param array $config The phase's rule config: baseDamage, bossAttack and bossArmor are read.
     * @return bool False when the log and the server's outcomes do not reconcile here.
     */
    private static function consume_question(array &$sim, string $side, array &$state, array $config): bool {
        $event = $sim['events'][$sim['eventindex']] ?? null;
        if ($event === null && $sim['snapshotmode']) {
            // The events end right as this question opens. If the server already judged it
            // (answered before the reload, logged too late), its outcome is taken and the
            // missing marker added; otherwise this is where the player resumes, question open.
            $next = $sim['outcomes'][$sim['outcomeindex']] ?? null;
            if (($next['side'] ?? null) !== $side) {
                $sim['stop'] = $side;
                return false;
            }
            $event = ['type' => 'question', 'side' => $side, 'outcome' => 'answered'];
            $sim['events'][] = $event;
            $sim['appended'][] = $event;
        }
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
                self::apply_question_outcome($side, (bool) $outcome['correct'], $state, $config);
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
                self::apply_question_outcome('player', false, $state, $config);
                return true;
        }

        return false;
    }

    /**
     * Applies, at the resume point, every combat consumable use_stock counted but the log never
     * received (used just before the reload, its checkpoint lost): the stock is already spent,
     * so dropping the effect would cost the player twice, and logging it keeps the phase's
     * later verification consistent with that count.
     *
     * @param array $sim The simulation context (mutated in place).
     * @param array $state Combat state (mutated in place).
     * @param array $config Keys baseDamage/coinGain/coinFactor/bossAttack/bossArmor/bossPowers.
     * @return bool False when the log holds more uses than the server counted.
     */
    private static function apply_unlogged_consumables(array &$sim, array &$state, array $config): bool {
        foreach (move_log::COMBAT_CONSUMABLES as $kind) {
            $missing = (int) ($sim['serveruses'][$kind] ?? 0) - $sim['loggeduses'][$kind];
            if ($missing < 0) {
                return false;
            }
            for ($i = 0; $i < $missing; $i++) {
                $event = ['type' => 'consumable', 'kind' => $kind];
                $sim['events'][] = $event;
                $sim['appended'][] = $event;
                $sim['eventindex']++;
                $sim['loggeduses'][$kind]++;
                self::apply_consumable($kind, $state, $config);
            }
        }

        return true;
    }

    /**
     * Describes where a rebuilt phase stands — see snapshot().
     *
     * @param array $grid The board grid at the stopping point.
     * @param array $state Combat state at the stopping point.
     * @param array $sim The simulation context.
     * @param string $turn Whose turn it is.
     * @param bool $terminal Whether the match has already ended.
     * @param int $rngstate The PRNG's internal state at the stopping point.
     * @return array See snapshot().
     */
    private static function describe(array $grid, array $state, array $sim, string $turn, bool $terminal, int $rngstate): array {
        $flat = [];
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                $flat[] = $cell;
            }
        }

        return [
            'grid' => $flat,
            'rngstate' => $rngstate,
            // The opening question is asked before anyone has a turn: the student's board is waiting for it.
            'turn' => $sim['stop'] === self::OPENING ? 'player' : ($sim['stop'] ?? $turn),
            'pendingquestion' => $sim['stop'],
            'terminal' => $terminal,
            'state' => $state,
            'eventcount' => count($sim['events']),
            'appendedevents' => $sim['appended'],
            'unusedoutcomes' => count($sim['outcomes']) - $sim['outcomeindex'],
        ];
    }

    /**
     * Applies a consumable's effect — mirrors combat.js::applyConsumableEffect(): the shared
     * meter/heal logic, plus the Sword's direct hit on the boss (through its shield, like any
     * other hit).
     *
     * @param string $kind One of move_log::COMBAT_CONSUMABLES.
     * @param array $state Combat state (mutated in place).
     * @param array $config Keys baseDamage/coinGain/coinFactor/bossAttack/bossArmor/bossPowers.
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
     * @param array $config The phase's rule config: baseDamage, bossAttack and bossArmor are read.
     * @return void
     */
    private static function apply_question_outcome(string $side, bool $correct, array &$state, array $config): void {
        $outcome = combat_engine::question_outcome($side, $correct, $state, $config);
        if ($outcome['hitBoss'] !== null) {
            $applied = combat_engine::resolve_damage($state['currentHp'], $state['bossShieldReady'], $outcome['hitBoss']);
            $state['currentHp'] = $applied['newHp'];
            if ($applied['shieldConsumed']) {
                $state['bossShieldReady'] = false;
            }
        }
        if ($outcome['hitPlayer'] !== null) {
            $applied = combat_engine::resolve_damage(
                $state['currentPlayerHp'],
                $state['playerShieldReady'],
                $outcome['hitPlayer']
            );
            $state['currentPlayerHp'] = $applied['newHp'];
            if ($applied['shieldConsumed']) {
                $state['playerShieldReady'] = false;
            }
            // Only the opponent's critical answer hits the student, and it drains like its other hits.
            if ($side === 'boss') {
                $state['currentHp'] += combat_engine::drain_amount($applied['appliedAmount'], $state, $config);
            }
        }
        if ($outcome['resetPlayerMultiplier']) {
            $state['playerMultiplier'] = 1.0;
        }
        if ($outcome['resetBossMultiplier']) {
            $state['bossMultiplier'] = 1.0;
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
     * @return array|null ['damage' => int, 'playergold' => int, 'bossgold' => int,
     *  'bossdefeated' => bool], or null when something was left unconsumed.
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
            // Read off the simulation itself, not off the rounded damage: a boss left on a
            // fraction of a hit point when the player falls rounds to full damage, yet lived.
            'bossdefeated' => $state['currentHp'] <= 0,
        ];
    }
}
