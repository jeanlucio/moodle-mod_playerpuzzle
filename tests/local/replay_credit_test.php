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
 * Unit tests for the replay-vs-claim reconciliation helper.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\event\replay_diverged;

/**
 * The seed-2/single-move-kill scenario reused here (rngseed 2, one recorded move at
 * (3,1)-(3,2), a 10 HP boss) is the same one tests/local/engine/replay_test.php locks in as
 * always deriving damage 10, playergold 15, bossgold 0 — see that test's own docblock for
 * where those numbers come from.
 *
 * @covers \mod_playerpuzzle\local\replay_credit
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
     * Tests that an inconclusive replay leaves the claimed values completely unchanged, and
     * fires no divergence event.
     *
     * @return void
     */
    public function test_resolve_returns_claimed_values_when_replay_is_inconclusive(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);
        $sink = $this->redirectEvents();

        $result = replay_credit::resolve($this->make_inconclusive_attempt(), $instance, $context, 42, 7, 3);

        $this->assertSame(['damage' => 42, 'playergold' => 7, 'bossgold' => 3], $result);
        $this->assertCount(0, array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof replay_diverged
        ));
    }

    /**
     * Tests that a conclusive replay matching the claim is credited with no event fired —
     * a genuine match needs no observability noise.
     *
     * @return void
     */
    public function test_resolve_fires_no_event_when_replay_agrees_with_the_claim(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);
        $sink = $this->redirectEvents();

        $result = replay_credit::resolve($this->make_conclusive_attempt(), $instance, $context, 10, 15, 0);

        $this->assertSame(['damage' => 10, 'playergold' => 15, 'bossgold' => 0], $result);
        $this->assertCount(0, array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof replay_diverged
        ));
    }

    /**
     * Tests that a conclusive replay disagreeing with an inflated claim credits its own
     * derived value (never the claim) and fires a replay_diverged event carrying both.
     *
     * @return void
     */
    public function test_resolve_credits_the_derived_value_and_fires_an_event_on_divergence(): void {
        [$instance, $context] = $this->make_instance_and_context(['minquestions' => 0]);
        $sink = $this->redirectEvents();

        // A forged claim of much more damage/gold than the recorded seed+moves actually
        // produced — the derived truth (10/15/0) must win regardless.
        $result = replay_credit::resolve($this->make_conclusive_attempt(), $instance, $context, 9999, 9999, 9999);

        $this->assertSame(['damage' => 10, 'playergold' => 15, 'bossgold' => 0], $result);

        $diverged = array_values(array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof replay_diverged
        ));
        $this->assertCount(1, $diverged);
        $this->assertSame(2, $diverged[0]->objectid);
        $this->assertSame(9999, $diverged[0]->other['claimeddamage']);
        $this->assertSame(10, $diverged[0]->other['deriveddamage']);
        $this->assertSame(9999, $diverged[0]->other['claimedplayergold']);
        $this->assertSame(15, $diverged[0]->other['derivedplayergold']);
        $this->assertSame(9999, $diverged[0]->other['claimedbossgold']);
        $this->assertSame(0, $diverged[0]->other['derivedbossgold']);
    }
}
