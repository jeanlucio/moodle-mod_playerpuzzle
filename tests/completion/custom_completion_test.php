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
 * Unit tests for the custom completion rules.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\completion;

use advanced_testcase;

/**
 * Tests for \mod_playerpuzzle\completion\custom_completion.
 *
 * @covers \mod_playerpuzzle\completion\custom_completion
 */
final class custom_completion_test extends advanced_testcase {
    /**
     * Creates a course and a playerpuzzle activity requiring the given attempt/win counts.
     *
     * @param int $requiredattempts Number of finished attempts required, 0 to disable the rule.
     * @param int $requiredwins Number of won attempts/matches required, 0 to disable the rule.
     * @return array{0: \stdClass, 1: \stdClass} [course, cm]
     */
    private function create_fixture(int $requiredattempts, int $requiredwins = 0): array {
        global $CFG;
        $CFG->enablecompletion = true;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $cm = $this->getDataGenerator()->create_module('playerpuzzle', [
            'course'                    => $course->id,
            'completion'                => COMPLETION_TRACKING_AUTOMATIC,
            'completionattemptsenabled' => $requiredattempts > 0,
            'completionattempts'        => $requiredattempts,
            'completionwinsenabled'     => $requiredwins > 0,
            'completionwins'            => $requiredwins,
        ]);

        return [$course, $cm];
    }

    /**
     * Inserts an attempt row for the given instance/user with the given final status.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @param string $status One of security::FINAL_STATUSES, or 'inprogress'.
     * @param bool $isdemo Whether the row is a disposable Demo attempt (§4.12 Fase 9).
     * @return void
     */
    private function make_attempt(int $instanceid, int $userid, string $status, bool $isdemo = false): void {
        global $DB;
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instanceid,
            'userid'         => $userid,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => $status,
            'isdemo'         => $isdemo ? 1 : 0,
            'timecreated'    => time(),
            'timefinished'   => $status === 'inprogress' ? 0 : time(),
        ]);
    }

    /**
     * completionattempts is incomplete while the student has fewer finished attempts than
     * required.
     *
     * @return void
     */
    public function test_get_state_attempts_incomplete_below_threshold(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(2);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($cm->id, $user->id, 'lost');

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int) $user->id);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionattempts'));
    }

    /**
     * completionattempts is complete once the student reaches the required count, counting
     * any final status (won, lost, timeout or abandoned), not just wins.
     *
     * @return void
     */
    public function test_get_state_attempts_complete_at_threshold_regardless_of_outcome(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(2);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($cm->id, $user->id, 'lost');
        $this->make_attempt($cm->id, $user->id, 'timeout');

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int) $user->id);

        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_state('completionattempts'));
    }

    /**
     * A still-in-progress attempt never counts towards completionattempts.
     *
     * @return void
     */
    public function test_get_state_attempts_ignores_inprogress_attempt(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(1);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($cm->id, $user->id, 'inprogress');

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int) $user->id);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionattempts'));
    }

    /**
     * A finished Demo attempt (§4.12 Fase 9) never counts towards completionattempts — an
     * unlimited, repeatable, zero-stakes practice fight cannot satisfy this rule on its own.
     *
     * @return void
     */
    public function test_get_state_attempts_ignores_demo_attempts(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(1);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($cm->id, $user->id, 'lost', true);
        $this->make_attempt($cm->id, $user->id, 'won', true);

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int) $user->id);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionattempts'));
    }

    /**
     * completionwins is incomplete while the student has fewer wins than required, even with
     * plenty of finished (but lost) attempts.
     *
     * @return void
     */
    public function test_get_state_wins_incomplete_with_only_losses(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(0, 1);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($cm->id, $user->id, 'lost');
        $this->make_attempt($cm->id, $user->id, 'timeout');

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int) $user->id);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionwins'));
    }

    /**
     * completionwins is complete once the student reaches the required won-attempt count.
     *
     * @return void
     */
    public function test_get_state_wins_complete_at_threshold(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(0, 2);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($cm->id, $user->id, 'lost');
        $this->make_attempt($cm->id, $user->id, 'won');
        $this->make_attempt($cm->id, $user->id, 'won');

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int) $user->id);

        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_state('completionwins'));
    }

    /**
     * A Demo attempt's win (§4.12 Fase 9) never counts towards completionwins — a Demo win
     * is not a real win.
     *
     * @return void
     */
    public function test_get_state_wins_ignores_demo_attempts(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(0, 1);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($cm->id, $user->id, 'won', true);

        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, (int) $user->id);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionwins'));
    }

    /**
     * Each rule is reported as available independently — only the rules the teacher actually
     * enabled show up, not both together just because one is on.
     *
     * @return void
     */
    public function test_rule_availability_is_independent_per_rule(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(2, 0);
        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, 0);

        $this->assertSame(['completionattempts'], $completion->get_available_custom_rules());
    }

    /**
     * The module declares both custom completion rules.
     *
     * @return void
     */
    public function test_get_defined_custom_rules_returns_both_rules(): void {
        $this->assertSame(
            ['completionattempts', 'completionwins'],
            custom_completion::get_defined_custom_rules()
        );
    }

    /**
     * Each rule's human-readable description includes its own configured count.
     *
     * @return void
     */
    public function test_get_custom_rule_descriptions_includes_required_counts(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(3, 2);
        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, 0);

        $descriptions = $completion->get_custom_rule_descriptions();

        $this->assertArrayHasKey('completionattempts', $descriptions);
        $this->assertStringContainsString('3', $descriptions['completionattempts']);
        $this->assertArrayHasKey('completionwins', $descriptions);
        $this->assertStringContainsString('2', $descriptions['completionwins']);
    }

    /**
     * The display order places both custom rules after the two core rules.
     *
     * @return void
     */
    public function test_get_sort_order_places_custom_rules_last(): void {
        $this->resetAfterTest();

        [$course, $cm] = $this->create_fixture(2, 1);
        $cminfo = get_fast_modinfo($course)->get_cm($cm->cmid);
        $completion = new custom_completion($cminfo, 0);

        $this->assertSame(
            ['completionview', 'completionusegrade', 'completionattempts', 'completionwins'],
            $completion->get_sort_order()
        );
    }
}
