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
 * Unit tests for the server-side replay engine.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

use mod_playerpuzzle\local\move_log;

/**
 * Every non-trivial scenario below was cross-checked against a JS mirror of this class's own
 * turn loop, run headless through Node against the real amd/src/engine/board_rules.js and
 * combat_rules.js — never hand-computed. The mirror script is not part of the repository; the
 * seed/event log/expected result triples it produced are hardcoded here as fixtures.
 *
 * @covers \mod_playerpuzzle\local\engine\replay
 */
final class replay_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds an attempt row with sane defaults, overridable per test.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    private function make_attempt(array $overrides = []): \stdClass {
        return (object) array_merge([
            'id' => 1,
            'isdemo' => 0,
            'engineversion' => (int) get_config('mod_playerpuzzle', 'version'),
            'rngseed' => 1,
            'movelog' => null,
            'currentlevel' => 1,
            'currentphase' => 1,
            'difficulty' => 'normal',
            'questions_total' => 0,
            'frozenbasebosshp' => 1000,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
        ], $overrides);
    }

    /**
     * Builds an instance record with sane defaults, overridable per test.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    private function make_playerpuzzle(array $overrides = []): \stdClass {
        return (object) array_merge([
            'minquestions' => 0,
            'basestudenthp' => 1000,
        ], $overrides);
    }

    /**
     * Tests that a Demo attempt is never verified.
     *
     * @return void
     */
    public function test_derive_returns_null_for_demo_attempt(): void {
        $attempt = $this->make_attempt(['isdemo' => 1]);
        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests that an engine-version mismatch skips verification entirely.
     *
     * @return void
     */
    public function test_derive_returns_null_on_engine_version_mismatch(): void {
        $attempt = $this->make_attempt(['engineversion' => 1]);
        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests that an empty event log (nothing recorded yet) is inconclusive, not a divergence.
     *
     * @return void
     */
    public function test_derive_returns_null_with_empty_event_log(): void {
        $attempt = $this->make_attempt(['movelog' => null]);
        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests that a log whose first entry is not a 'move' event (the player's own turn always
     * starts with one) is rejected rather than guessed at.
     *
     * @return void
     */
    public function test_derive_returns_null_when_first_event_is_not_a_move(): void {
        $attempt = $this->make_attempt([
            'movelog' => move_log::encode([['type' => 'question', 'side' => 'player', 'correct' => true]]),
        ]);
        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests a real, single-swap, single-hit kill: seed 2's freshly generated board has a
     * 3-piece Sword match at (3,1)-(3,2) as its very first available move. With a 10 HP boss
     * and baseDamage 10, that one hit ends the phase outright — the cascade continues for a
     * few more gravity-refill rounds afterwards regardless (matching board.js, which only
     * checks game-over once a swap's whole cascade has fully settled), including a Coin match
     * that nets the player 15 gold along the way.
     *
     * @return void
     */
    public function test_derive_resolves_a_real_single_move_win(): void {
        $attempt = $this->make_attempt([
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 3, 'c1' => 1, 'r2' => 3, 'c2' => 2]]),
            'frozenbasebosshp' => 10,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
        ]);

        $result = replay::derive($attempt, $this->make_playerpuzzle());

        $this->assertSame(['damage' => 10, 'playergold' => 15, 'bossgold' => 0], $result);
    }

    /**
     * Tests that a mana-crossing match with no corresponding 'question' event in the log is
     * treated as a gap, never as a lower derived value: seed 56's first move (any available
     * one) triggers a player question five cascade rounds in, but this log only records the
     * move itself.
     *
     * @return void
     */
    public function test_derive_returns_null_when_a_question_event_is_missing(): void {
        $attempt = $this->make_attempt([
            'rngseed' => 56,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 0, 'c1' => 7, 'r2' => 1, 'c2' => 7]]),
            'frozenbasebosshp' => 100000,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
        ]);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests a full multi-turn phase: three player moves interleaved with one automatic boss
     * turn (whose own match triggers and resolves a boss-side question, correctly answered),
     * ending in a clean win. Exercises the player-move -> cascade -> poison-tick -> boss-turn
     * -> cascade -> poison-tick -> player-move sequence end to end, not just a single swap.
     *
     * @return void
     */
    public function test_derive_resolves_a_multi_turn_phase_with_a_boss_question(): void {
        $attempt = $this->make_attempt([
            'rngseed' => 11,
            'movelog' => move_log::encode([
                ['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 1, 'c2' => 0],
                ['type' => 'move', 'r1' => 1, 'c1' => 4, 'r2' => 1, 'c2' => 5],
                ['type' => 'question', 'side' => 'boss', 'correct' => true],
                ['type' => 'move', 'r1' => 0, 'c1' => 3, 'r2' => 0, 'c2' => 4],
                ['type' => 'move', 'r1' => 0, 'c1' => 2, 'r2' => 0, 'c2' => 3],
            ]),
            'frozenbasebosshp' => 15,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
            'questions_total' => 1,
        ]);

        $result = replay::derive($attempt, $this->make_playerpuzzle());

        $this->assertSame(['damage' => 15, 'playergold' => 0, 'bossgold' => 0], $result);
    }

    /**
     * Tests that a genuinely pathological cascade (an event log crafted to never let the
     * board settle) is treated as inconclusive rather than looping forever or throwing an
     * uncaught error out of derive().
     *
     * @return void
     */
    public function test_derive_returns_null_on_malformed_move_coordinates(): void {
        // A move referencing the same cell twice is a no-op swap that still needs a real
        // match to have been recorded — since the recorded log always corresponds to a swap
        // board.js confirmed produced one, a self-swap here can never match the actual seed's
        // board, so the simulation's own event bookkeeping (expecting further moves/questions
        // that were never really produced) will not resolve to a real terminal state.
        $attempt = $this->make_attempt([
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 0]]),
        ]);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests that a genuinely broken stored event (a 'move' entry missing the coordinate
     * fields it needs — something save_combat_state.php's own validation should always
     * prevent, but a corrupted row could not) is rejected cleanly rather than reaching
     * board_engine::swap_in_grid() with a missing argument.
     *
     * @return void
     */
    public function test_derive_returns_null_on_a_move_event_missing_coordinates(): void {
        $attempt = $this->make_attempt([
            'rngseed' => 2,
            'movelog' => json_encode([['type' => 'move']]),
        ]);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests that the boss revives at 50% of its own max HP, mid-simulation, when it reaches
     * 0 with minquestions not yet met — reusing the multi-turn seed-11 fixture, but this time
     * with a minquestions requirement the attempt has not satisfied, so the same event log
     * that resolved to a clean kill above must instead revive the boss once and keep going
     * until the log itself runs out (with no further moves recorded past the finishing blow,
     * this now correctly comes back inconclusive rather than crediting a win that, under this
     * instance's own rules, had not actually happened yet).
     *
     * @return void
     */
    public function test_derive_revives_the_boss_instead_of_ending_the_match(): void {
        $attempt = $this->make_attempt([
            'rngseed' => 11,
            'movelog' => move_log::encode([
                ['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 1, 'c2' => 0],
                ['type' => 'move', 'r1' => 1, 'c1' => 4, 'r2' => 1, 'c2' => 5],
                ['type' => 'question', 'side' => 'boss', 'correct' => true],
                ['type' => 'move', 'r1' => 0, 'c1' => 3, 'r2' => 0, 'c2' => 4],
                ['type' => 'move', 'r1' => 0, 'c1' => 2, 'r2' => 0, 'c2' => 3],
            ]),
            'frozenbasebosshp' => 15,
            'questions_total' => 1,
        ]);

        $result = replay::derive($attempt, $this->make_playerpuzzle(['minquestions' => 5]));

        // The kill event is the log's very last one — with minquestions unmet, the boss
        // revives instead of the match ending there, and no further move is recorded for
        // the player's next turn, so this correctly comes back inconclusive.
        $this->assertNull($result);
    }
}
