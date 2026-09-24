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
 * Unit tests for the match verdict helper.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\event\replay_diverged;
use mod_playerpuzzle\event\replay_inconclusive;

/**
 * The seed-2/single-move-kill scenario reused here (rngseed 2, one recorded move at
 * (3,1)-(3,2), a 10 HP boss) is the same one tests/local/engine/replay_test.php locks in as
 * always deriving damage 10, playergold 15, bossgold 0 — see that test's own docblock for
 * where those numbers come from.
 *
 * @covers \mod_playerpuzzle\local\replay_credit
 * @covers \mod_playerpuzzle\event\replay_diverged
 * @covers \mod_playerpuzzle\event\replay_inconclusive
 */
final class replay_credit_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Creates a playerpuzzle instance and its module context.
     *
     * @param array $overrides Instance field overrides.
     * @return array [\stdClass instance, \context_module context]
     */
    private function make_instance_and_context(array $overrides = []): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $record = array_merge(['course' => $this->course->id], $overrides);
        $instance = $generator->create_instance($record);

        return [$instance, \context_module::instance($instance->cmid)];
    }

    /**
     * Builds an attempt row that replay::derive() cannot conclusively verify (no movelog).
     *
     * @return \stdClass
     */
    private function make_inconclusive_attempt(): \stdClass {
        return (object) [
            'id' => 1,
            'isdemo' => 0,
            'engineversion' => (int) get_config('mod_playerpuzzle', 'version'),
            'rngseed' => 1,
            'movelog' => null,
            'questionresults' => null,
            'phaserestarts' => 0,
            'currentlevel' => 1,
            'currentphase' => 1,
            'difficulty' => 'normal',
            'questions_total' => 0,
            'frozenbasebosshp' => 1000,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
        ];
    }

    /**
     * Builds an attempt row for the seed-2 single-move-kill scenario, which replay::derive()
     * always resolves to damage 10, playergold 15, bossgold 0.
     *
     * @return \stdClass
     */
    private function make_conclusive_attempt(): \stdClass {
        return (object) [
            'id' => 2,
            'isdemo' => 0,
            'engineversion' => (int) get_config('mod_playerpuzzle', 'version'),
            'rngseed' => 2,
            'movelog' => move_log::encode([['type' => 'move', 'r1' => 3, 'c1' => 1, 'r2' => 3, 'c2' => 2]]),
            'questionresults' => null,
            'phaserestarts' => 0,
            'currentlevel' => 1,
            'currentphase' => 1,
            'difficulty' => 'normal',
            'questions_total' => 0,
            'frozenbasebosshp' => 10,
            'frozenbossdamage' => 10,
            'frozencoingain' => 10,
        ];
    }

    /**
     * Returns the events of one class caught by a sink.
     *
     * @param \phpunit_event_sink $sink The sink.
     * @param string $class Event class name.
     * @return array
     */
    private function events_of(\phpunit_event_sink $sink, string $class): array {
        return array_values(array_filter($sink->get_events(), static fn($event): bool => $event instanceof $class));
    }

    /**
     * Tests that an unverifiable victory restarts the phase while restarts are left, credits
     * nothing, and records the unverified match.
     *
     * @return void
     */
    public function test_verdict_restarts_an_unverifiable_victory(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);
        $sink = $this->redirectEvents();

        $verdict = replay_credit::verdict($this->make_inconclusive_attempt(), $instance, $context, true, 42, 7, 3);

        $this->assertSame(['outcome' => 'restart', 'damage' => 0, 'playergold' => 0, 'bossgold' => 0], $verdict);
        $inconclusive = $this->events_of($sink, replay_inconclusive::class);
        $this->assertCount(1, $inconclusive);
        $this->assertSame(
            ['claimeddamage' => 42, 'claimedplayergold' => 7, 'claimedbossgold' => 3],
            $inconclusive[0]->other
        );
        $this->assertCount(0, $this->events_of($sink, replay_diverged::class));
    }

    /**
     * Tests that an unverifiable victory with no restart left counts as a defeat, crediting
     * only the damage the log still vouches for: seed 2's recorded kill move replays fine,
     * but against a 1000 HP boss the phase never ends, so what it verifies is those 10.
     *
     * @return void
     */
    public function test_verdict_counts_an_unverifiable_victory_as_lost_once_restarts_run_out(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);
        $attempt = $this->make_conclusive_attempt();
        $attempt->frozenbasebosshp = 1000;
        $attempt->phaserestarts = replay_credit::MAX_PHASE_RESTARTS;

        $verdict = replay_credit::verdict($attempt, $instance, $context, true, 1000, 99, 0);

        $this->assertSame(['outcome' => 'lost', 'damage' => 10, 'playergold' => 0, 'bossgold' => 0], $verdict);
    }

    /**
     * Tests that an unverifiable defeat is simply taken, never restarted — nobody gains
     * anything by forging one.
     *
     * @return void
     */
    public function test_verdict_takes_an_unverifiable_defeat(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);

        $verdict = replay_credit::verdict($this->make_inconclusive_attempt(), $instance, $context, false, 42, 7, 3);

        $this->assertSame('lost', $verdict['outcome']);
        $this->assertSame(0, $verdict['playergold']);
    }

    /**
     * Tests that a Demo match, never verified by design, keeps its claim and fires nothing.
     *
     * @return void
     */
    public function test_verdict_keeps_a_demo_claim(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);
        $sink = $this->redirectEvents();
        $attempt = $this->make_inconclusive_attempt();
        $attempt->isdemo = 1;

        $verdict = replay_credit::verdict($attempt, $instance, $context, true, 42, 7, 3);

        $this->assertSame(['outcome' => 'won', 'damage' => 42, 'playergold' => 7, 'bossgold' => 3], $verdict);
        $this->assertSame([], $sink->get_events());
    }

    /**
     * Tests that a verified win agreeing with the claim is credited with no event fired.
     *
     * @return void
     */
    public function test_verdict_credits_a_verified_win_with_no_event_when_it_agrees(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);
        $sink = $this->redirectEvents();

        $verdict = replay_credit::verdict($this->make_conclusive_attempt(), $instance, $context, true, 10, 15, 0);

        $this->assertSame(['outcome' => 'won', 'damage' => 10, 'playergold' => 15, 'bossgold' => 0], $verdict);
        $this->assertSame([], $sink->get_events());
    }

    /**
     * Tests that a verified win disagreeing with an inflated claim credits the derived value
     * (never the claim) and fires a replay_diverged event carrying both.
     *
     * @return void
     */
    public function test_verdict_credits_the_derived_value_and_fires_an_event_on_divergence(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);
        $sink = $this->redirectEvents();

        $verdict = replay_credit::verdict($this->make_conclusive_attempt(), $instance, $context, true, 9999, 9999, 9999);

        $this->assertSame(['outcome' => 'won', 'damage' => 10, 'playergold' => 15, 'bossgold' => 0], $verdict);
        $diverged = $this->events_of($sink, replay_diverged::class);
        $this->assertCount(1, $diverged);
        $this->assertSame(2, $diverged[0]->objectid);
        $this->assertSame([
            'claimeddamage' => 9999,
            'deriveddamage' => 10,
            'claimedplayergold' => 9999,
            'derivedplayergold' => 15,
            'claimedbossgold' => 9999,
            'derivedbossgold' => 0,
        ], $diverged[0]->other);
    }

    /**
     * Tests that a claimed victory the replay verifies as a defeat counts as a defeat: on
     * seed 2, a coin match then the boss's turn finishes a 1 HP student.
     *
     * @return void
     */
    public function test_verdict_counts_a_verified_defeat_as_lost_whatever_the_claim(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0, 'basestudenthp' => 1]);
        $attempt = $this->make_conclusive_attempt();
        $attempt->frozenbasebosshp = 1000;
        $attempt->movelog = move_log::encode([['type' => 'move', 'r1' => 0, 'c1' => 4, 'r2' => 1, 'c2' => 4]]);

        $verdict = replay_credit::verdict($attempt, $instance, $context, true, 1000, 0, 0);

        $this->assertSame(['outcome' => 'lost', 'damage' => 0, 'playergold' => 10, 'bossgold' => 0], $verdict);
    }

    /**
     * Tests that restarting a phase starts it over within the same attempt: new seed, empty
     * log and outcomes, no fight to resume, the phase's coins and consumable uses cleared,
     * and one more restart counted.
     *
     * @return void
     */
    public function test_restart_phase_starts_the_phase_over(): void {
        global $DB;

        [$instance] = $this->make_instance_and_context(['minquestions' => 0]);
        $attempt = $this->make_conclusive_attempt();
        $attempt->id = 77;
        $attempt->combatstate = '{"boardgrid":[1]}';
        $attempt->currentquestionid = 5;
        $attempt->questionresults = '[{"side":"boss","correct":true,"counted":false}]';
        $attempt->coins_earned = 30;
        $attempt->boss_coins_earned = 10;
        attempt_consumables::record_use(77, 'potion');

        replay_credit::restart_phase($attempt, $instance);

        $this->assertNull($attempt->movelog);
        $this->assertNull($attempt->questionresults);
        $this->assertNull($attempt->combatstate);
        $this->assertSame(0, $attempt->moveseq);
        $this->assertSame(0, $attempt->currentquestionid);
        $this->assertSame(0, $attempt->coins_earned);
        $this->assertSame(0, $attempt->boss_coins_earned);
        $this->assertSame(1, $attempt->phaserestarts);
        $this->assertSame(0, attempt_consumables::get_uses(77, 'potion'));
        $this->assertFalse($DB->record_exists('playerpuzzle_attempt_consumables', ['attemptid' => 77]));
    }
}
