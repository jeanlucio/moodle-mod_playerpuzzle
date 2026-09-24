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
use mod_playerpuzzle\local\question_results;

/**
 * The board/combat scenarios below (seeds 2, 11 and 56) were cross-checked against a JS mirror
 * of this class's own turn loop, run headless through Node against the real
 * amd/src/engine/board_rules.js and combat_rules.js — never hand-computed. The mirror script is
 * not part of the repository; the seed/event log/expected result triples it produced are
 * hardcoded here as fixtures. The question-reconciliation cases reuse those same seeds and only
 * vary what the log and the server-decided outcomes say about each question.
 *
 * @covers \mod_playerpuzzle\local\engine\replay
 * @covers \mod_playerpuzzle\local\question_results
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
            'questionresults' => null,
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
            'id' => 0,
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
            'movelog' => move_log::encode([['type' => 'question', 'side' => 'player', 'outcome' => 'answered']]),
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

        $this->assertSame(['damage' => 10, 'playergold' => 15, 'bossgold' => 0, 'bossdefeated' => true], $result);
    }

    /**
     * Records consumable uses the way use_stock.php would have counted them.
     *
     * @param int $attemptid The attempt id.
     * @param array $uses Type => times used.
     * @return void
     */
    private function record_server_uses(int $attemptid, array $uses): void {
        global $DB;

        foreach ($uses as $type => $times) {
            $DB->insert_record('playerpuzzle_attempt_consumables', (object) [
                'attemptid' => $attemptid,
                'consumabletype' => $type,
                'timesused' => $times,
            ]);
        }
    }

    /**
     * Tests that a Sword used at the start of the player's turn is applied right there: on
     * seed 2 with a 10 HP boss, the Sword alone (baseDamage 10) finishes it before any move.
     *
     * @return void
     */
    public function test_derive_applies_a_logged_consumable_before_the_move(): void {
        $attempt = $this->make_attempt([
            'id' => 97,
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'consumable', 'kind' => 'sword']]),
            'frozenbasebosshp' => 10,
        ]);
        $this->record_server_uses(97, ['sword' => 1]);

        $result = replay::derive($attempt, $this->make_playerpuzzle());

        $this->assertSame(['damage' => 10, 'playergold' => 0, 'bossgold' => 0, 'bossdefeated' => true], $result);
    }

    /**
     * Tests that a combat consumable the server counted but the log never placed makes the
     * replay inconclusive — a Shield armed off the record would block a hit the simulation
     * never saw. Same fixture as test_derive_resolves_a_real_single_move_win(), which is
     * conclusive without the stray use.
     *
     * @return void
     */
    public function test_derive_returns_null_when_a_counted_consumable_is_missing_from_the_log(): void {
        $attempt = $this->make_attempt([
            'id' => 99,
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 3, 'c1' => 1, 'r2' => 3, 'c2' => 2]]),
            'frozenbasebosshp' => 10,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
        ]);
        $this->record_server_uses(99, ['shield' => 1]);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests that a consumable in the log that use_stock.php never authorised is not applied
     * on the client's word.
     *
     * @return void
     */
    public function test_derive_returns_null_when_a_logged_consumable_was_never_counted(): void {
        $attempt = $this->make_attempt([
            'id' => 96,
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'consumable', 'kind' => 'sword']]),
            'frozenbasebosshp' => 10,
        ]);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests that a match's damage is rounded the way the live client rounds it before it is
     * dealt: on seed 4, a 4-piece Sword combo with an odd baseDamage (11) hits for 16.5,
     * which the client deals as 17 — enough to finish a 17 HP boss. Dealt unrounded, the boss
     * would be left on half a point and the client's real win would not verify.
     *
     * @return void
     */
    public function test_derive_rounds_match_damage_like_the_client(): void {
        $attempt = $this->make_attempt([
            'rngseed' => 4,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 2, 'c1' => 5, 'r2' => 3, 'c2' => 5]]),
            'frozenbasebosshp' => 17,
            'frozenbossdamage' => 11,
        ]);

        $result = replay::derive($attempt, $this->make_playerpuzzle(['basestudenthp' => 100]));

        $this->assertSame(['damage' => 17, 'playergold' => 0, 'bossgold' => 0, 'bossdefeated' => true], $result);
    }

    /**
     * Tests that a match the player loses is conclusive too, and says the boss survived: on
     * seed 2, after a coin match, the boss's own turn finishes a 1 HP student.
     *
     * @return void
     */
    public function test_derive_resolves_a_real_defeat(): void {
        $attempt = $this->make_attempt([
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 0, 'c1' => 4, 'r2' => 1, 'c2' => 4]]),
        ]);

        $result = replay::derive($attempt, $this->make_playerpuzzle(['basestudenthp' => 1]));

        $this->assertSame(['damage' => 0, 'playergold' => 10, 'bossgold' => 0, 'bossdefeated' => false], $result);
    }

    /**
     * Tests that a used Hint — the one consumable type with no combat effect of its own — does
     * not, on its own, skip verification.
     *
     * @return void
     */
    public function test_derive_still_verifies_when_only_hint_was_used(): void {
        global $DB;

        $attempt = $this->make_attempt([
            'id' => 98,
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 3, 'c1' => 1, 'r2' => 3, 'c2' => 2]]),
            'frozenbasebosshp' => 10,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
        ]);
        $DB->insert_record('playerpuzzle_attempt_consumables', (object) [
            'attemptid' => 98,
            'consumabletype' => 'hint',
            'timesused' => 3,
        ]);

        $result = replay::derive($attempt, $this->make_playerpuzzle());

        $this->assertSame(['damage' => 10, 'playergold' => 15, 'bossgold' => 0, 'bossdefeated' => true], $result);
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
                ['type' => 'question', 'side' => 'boss', 'outcome' => 'answered'],
                ['type' => 'move', 'r1' => 0, 'c1' => 3, 'r2' => 0, 'c2' => 4],
                ['type' => 'move', 'r1' => 0, 'c1' => 2, 'r2' => 0, 'c2' => 3],
            ]),
            'frozenbasebosshp' => 15,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
            'questionresults' => question_results::append(null, 'boss', true, false),
        ]);

        $result = replay::derive($attempt, $this->make_playerpuzzle());

        $this->assertSame(['damage' => 15, 'playergold' => 0, 'bossgold' => 0, 'bossdefeated' => true], $result);
    }

    /**
     * Tests that a genuinely pathological cascade (an event log crafted to never let the
     * board settle) is treated as inconclusive rather than looping forever or throwing an
     * uncaught error out of derive().
     *
     * @return void
     */
    public function test_derive_returns_null_on_malformed_move_coordinates(): void {
        // A move referencing the same cell twice is a no-op swap, so it can never produce the
        // match every recorded move must have produced on the live board.
        $attempt = $this->make_attempt([
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 0]]),
        ]);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle()));
    }

    /**
     * Tests that a recorded swap matching nothing on the simulated board stops the replay,
     * instead of being applied and simulated past. The live client never records such a swap,
     * so it proves the simulated board is not the one really played (a reload desync, a
     * tampered log). Without this check, this exact log (an impossible first swap on seed 2's
     * board, then a real one) resolved to a clean, conclusive "win" — a derived value
     * describing a match that never happened.
     *
     * @return void
     */
    public function test_derive_returns_null_when_a_recorded_swap_matches_nothing(): void {
        $attempt = $this->make_attempt([
            'rngseed' => 2,
            'movelog' => move_log::encode([
                ['type' => 'move', 'r1' => 0, 'c1' => 2, 'r2' => 0, 'c2' => 3],
                ['type' => 'move', 'r1' => 1, 'c1' => 2, 'r2' => 2, 'c2' => 2],
            ]),
            'frozenbasebosshp' => 10,
        ]);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle(['basestudenthp' => 100])));
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
                ['type' => 'question', 'side' => 'boss', 'outcome' => 'answered'],
                ['type' => 'move', 'r1' => 0, 'c1' => 3, 'r2' => 0, 'c2' => 4],
                ['type' => 'move', 'r1' => 0, 'c1' => 2, 'r2' => 0, 'c2' => 3],
            ]),
            'frozenbasebosshp' => 15,
            'questionresults' => question_results::append(null, 'boss', true, false),
        ]);

        $result = replay::derive($attempt, $this->make_playerpuzzle(['minquestions' => 5]));

        // The kill event is the log's very last one — with minquestions unmet, the boss
        // revives instead of the match ending there, and no further move is recorded for
        // the player's next turn, so this correctly comes back inconclusive.
        $this->assertNull($result);
    }

    /**
     * Builds seed 56's one-move attempt: its first swap triggers a player question five
     * cascade rounds in, and with the boss at 40 HP only a correct answer's critical hit
     * finishes it inside that cascade.
     *
     * @param string $outcome How the logged question ended.
     * @param array $serveroutcomes Outcomes as validate_answer would have stored them.
     * @param int $bosshp The phase's base boss HP.
     * @return \stdClass
     */
    private function make_seed56_attempt(string $outcome, array $serveroutcomes, int $bosshp = 40): \stdClass {
        $raw = null;
        foreach ($serveroutcomes as [$side, $correct, $counted]) {
            $raw = question_results::append($raw, $side, $correct, $counted);
        }

        return $this->make_attempt([
            'rngseed' => 56,
            'movelog' => move_log::encode([
                ['type' => 'move', 'r1' => 0, 'c1' => 7, 'r2' => 1, 'c2' => 7],
                ['type' => 'question', 'side' => 'player', 'outcome' => $outcome],
            ]),
            'questionresults' => $raw,
            'questions_total' => count($serveroutcomes),
            'frozenbasebosshp' => $bosshp,
        ]);
    }

    /**
     * Tests that whether an answer was right comes from the server's own outcome, never the
     * client's log: the exact same log is a win when the server says the answer was correct,
     * and inconclusive when it says it was wrong (no critical hit, so the boss survives and
     * the log runs out).
     *
     * @return void
     */
    public function test_derive_takes_the_question_outcome_from_the_server(): void {
        $pp = $this->make_playerpuzzle(['basestudenthp' => 100]);

        $right = replay::derive($this->make_seed56_attempt('answered', [['player', true, true]]), $pp);
        $wrong = replay::derive($this->make_seed56_attempt('answered', [['player', false, true]]), $pp);

        $this->assertSame(['damage' => 40, 'playergold' => 25, 'bossgold' => 0, 'bossdefeated' => true], $right);
        $this->assertNull($wrong);
    }

    /**
     * Tests that an "answered" marker with no server outcome behind it is not trusted — a
     * forged log cannot claim an answer the server never judged.
     *
     * @return void
     */
    public function test_derive_returns_null_when_an_answer_has_no_server_outcome(): void {
        $attempt = $this->make_seed56_attempt('answered', []);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle(['basestudenthp' => 100])));
    }

    /**
     * Tests that a server outcome for the other side does not reconcile with the player's
     * question.
     *
     * @return void
     */
    public function test_derive_returns_null_when_the_server_outcome_is_for_the_other_side(): void {
        $attempt = $this->make_seed56_attempt('answered', [['boss', true, false]]);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle(['basestudenthp' => 100])));
    }

    /**
     * Tests that a skipped player question is accepted and changes nothing: with the boss at
     * 10 HP the cascade alone finishes it.
     *
     * @return void
     */
    public function test_derive_accepts_a_skipped_player_question(): void {
        $attempt = $this->make_seed56_attempt('skipped', [], 10);

        $result = replay::derive($attempt, $this->make_playerpuzzle(['basestudenthp' => 100]));

        $this->assertSame(['damage' => 10, 'playergold' => 25, 'bossgold' => 0, 'bossdefeated' => true], $result);
    }

    /**
     * Tests that a server outcome left unconsumed at the end of the match makes the replay
     * inconclusive — here, a wrong answer the client reported as skipped to dodge its penalty.
     *
     * @return void
     */
    public function test_derive_returns_null_when_a_server_outcome_is_left_over(): void {
        $attempt = $this->make_seed56_attempt('skipped', [['player', false, true]], 10);

        $this->assertNull(replay::derive($attempt, $this->make_playerpuzzle(['basestudenthp' => 100])));
    }

    /**
     * Tests that a player question whose validation never came back is accepted as the wrong
     * answer the client applied on its own, while the boss's is inconclusive (there is no
     * safe way to guess which way the server would have drawn it).
     *
     * @return void
     */
    public function test_derive_handles_a_failed_validation_by_side(): void {
        $player = $this->make_seed56_attempt('failed', [], 10);
        $boss = $this->make_attempt([
            'rngseed' => 11,
            'movelog' => move_log::encode([
                ['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 1, 'c2' => 0],
                ['type' => 'move', 'r1' => 1, 'c1' => 4, 'r2' => 1, 'c2' => 5],
                ['type' => 'question', 'side' => 'boss', 'outcome' => 'failed'],
                ['type' => 'move', 'r1' => 0, 'c1' => 3, 'r2' => 0, 'c2' => 4],
                ['type' => 'move', 'r1' => 0, 'c1' => 2, 'r2' => 0, 'c2' => 3],
            ]),
            'frozenbasebosshp' => 15,
        ]);

        $pp = $this->make_playerpuzzle(['basestudenthp' => 100]);
        $expected = ['damage' => 10, 'playergold' => 25, 'bossgold' => 0, 'bossdefeated' => true];
        $this->assertSame($expected, replay::derive($player, $pp));
        $this->assertNull(replay::derive($boss, $this->make_playerpuzzle()));
    }

    /**
     * Tests that "no question available" is only accepted when the instance really had no
     * approved question to draw — otherwise it is a draw call that never came back (or was
     * blocked on purpose to dodge the question).
     *
     * @return void
     */
    public function test_derive_accepts_an_unavailable_question_only_with_an_empty_pool(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('playerpuzzle', ['course' => $course->id]);
        $attempt = $this->make_seed56_attempt('unavailable', [], 10);

        $empty = replay::derive($attempt, $this->make_playerpuzzle(['id' => $instance->id, 'basestudenthp' => 100]));

        global $DB;
        $DB->insert_record('playerpuzzle_questions', (object) [
            'playerpuzzleid' => $instance->id,
            'qtype' => 'truefalse',
            'questiontext' => 'Q',
            'approved' => 1,
            'timecreated' => time(),
            'addedby' => 2,
        ]);
        $withpool = replay::derive($attempt, $this->make_playerpuzzle(['id' => $instance->id, 'basestudenthp' => 100]));

        $this->assertSame(['damage' => 10, 'playergold' => 25, 'bossgold' => 0, 'bossdefeated' => true], $empty);
        $this->assertNull($withpool);
    }

    /**
     * Tests that rebuilding a phase with nothing logged yet gives the fresh board and hands
     * over the PRNG exactly where generating it left off: the next draw from the returned
     * state is the next draw of the original sequence.
     *
     * @return void
     */
    public function test_snapshot_of_an_empty_log_is_the_fresh_board_and_prng_position(): void {
        $snap = replay::snapshot($this->make_attempt(['rngseed' => 2]), $this->make_playerpuzzle());

        $rng = prng::create(2);
        $fresh = board_engine::generate_grid(8, 8, null, $rng);
        $this->assertSame(array_merge(...$fresh), $snap['grid']);
        $this->assertSame($rng(), prng::create($snap['rngstate'])());
        $this->assertSame('player', $snap['turn']);
        $this->assertNull($snap['pendingquestion']);
        $this->assertFalse($snap['terminal']);
        $this->assertSame(0, $snap['eventcount']);
    }

    /**
     * Tests that a log ending after the player's move resumes on the player's next turn, with
     * the boss's own (unlogged, deterministic) turn already played, and that a log ending on
     * the finishing blow comes back terminal.
     *
     * @return void
     */
    public function test_snapshot_resumes_after_the_boss_turn_or_reports_the_end(): void {
        $move = move_log::encode([['type' => 'move', 'r1' => 3, 'c1' => 1, 'r2' => 3, 'c2' => 2]]);
        $pp = $this->make_playerpuzzle(['basestudenthp' => 100]);

        $ongoing = replay::snapshot($this->make_attempt(['rngseed' => 2, 'movelog' => $move]), $pp);
        $won = replay::snapshot($this->make_attempt(['rngseed' => 2, 'movelog' => $move, 'frozenbasebosshp' => 10]), $pp);

        $this->assertSame('player', $ongoing['turn']);
        $this->assertFalse($ongoing['terminal']);
        $this->assertNotContains(null, $ongoing['grid']);
        $this->assertTrue($won['terminal']);
        $this->assertEquals(0, $won['state']['currentHp']);
    }

    /**
     * Tests that a log ending as a question opens resumes with that question pending — the
     * cells its match emptied still empty, gravity not yet applied — unless the server had
     * already judged it, in which case the outcome is applied and the missing marker returned
     * for storage.
     *
     * @return void
     */
    public function test_snapshot_stops_at_an_open_question_unless_the_server_judged_it(): void {
        $pp = $this->make_playerpuzzle(['basestudenthp' => 100]);

        $movelog = move_log::encode([['type' => 'move', 'r1' => 0, 'c1' => 7, 'r2' => 1, 'c2' => 7]]);
        $open = replay::snapshot($this->make_attempt([
            'rngseed' => 56,
            'movelog' => $movelog,
            'frozenbasebosshp' => 100000,
        ]), $pp);
        $judged = replay::snapshot($this->make_attempt([
            'rngseed' => 56,
            'movelog' => $movelog,
            'questionresults' => question_results::append(null, 'player', true, true),
            'questions_total' => 1,
            'frozenbasebosshp' => 100000,
        ]), $pp);

        $this->assertSame('player', $open['pendingquestion']);
        $this->assertContains(null, $open['grid']);
        $this->assertSame([], $open['appendedevents']);
        $this->assertNull($judged['pendingquestion']);
        $this->assertSame([['type' => 'question', 'side' => 'player', 'outcome' => 'answered']], $judged['appendedevents']);
        $this->assertSame(2, $judged['eventcount']);
    }

    /**
     * Tests that a combat consumable use_stock counted but the log never received is applied
     * at the resume point and returned for storage, so its already-spent stock still has its
     * effect: a counted Sword on seed 2's untouched board with a 10 HP boss ends the match.
     *
     * @return void
     */
    public function test_snapshot_applies_a_counted_but_unlogged_consumable(): void {
        $this->record_server_uses(95, ['sword' => 1]);

        $snap = replay::snapshot(
            $this->make_attempt(['id' => 95, 'rngseed' => 2, 'frozenbasebosshp' => 10]),
            $this->make_playerpuzzle()
        );

        $this->assertSame([['type' => 'consumable', 'kind' => 'sword']], $snap['appendedevents']);
        $this->assertTrue($snap['terminal']);
    }
}
