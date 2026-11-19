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

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\security;

/**
 * Tests for abandoning a match from the Lobby.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_playerpuzzle\local\match_abandon
 */
final class match_abandon_test extends \advanced_testcase {
    /** @var \stdClass The activity. */
    private \stdClass $instance;

    /** @var \stdClass Its course module. */
    private \stdClass $cm;

    /** @var \stdClass An enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $course->id, 'maxattempts' => 3]);
        $this->cm = get_coursemodule_from_instance('playerpuzzle', $this->instance->id);
    }

    /**
     * Starts an attempt for the student, with one move already recorded when asked, so its phase is under way.
     *
     * @param bool $started Whether something has happened in the phase.
     * @param bool $isdemo Whether the attempt is a Demo.
     * @return string The attempt's token.
     */
    private function start_attempt(bool $started, bool $isdemo = false): string {
        global $DB;

        $token = security::generate_attempt_token(
            (int) $this->instance->id,
            (int) $this->student->id,
            'hard',
            1,
            1,
            $isdemo
        );
        if ($started) {
            $DB->set_field('playerpuzzle_attempts', 'moveseq', 1, ['token' => $token]);
            $DB->set_field(
                'playerpuzzle_attempts',
                'movelog',
                json_encode([['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]]),
                ['token' => $token]
            );
        }
        return $token;
    }

    /**
     * Tests that a phase under way is abandoned as a defeat: the attempt ends as lost and is announced like the end
     * of any match, after which there is nothing left to abandon.
     *
     * @return void
     */
    public function test_a_phase_under_way_is_abandoned_as_a_defeat(): void {
        global $DB;
        $token = $this->start_attempt(true);
        $context = \context_module::instance($this->cm->id);
        $userid = (int) $this->student->id;

        $this->assertTrue(match_abandon::is_available($this->instance, $userid));
        $sink = $this->redirectEvents();
        $this->assertTrue(match_abandon::abandon($this->instance, $this->cm, $context, $userid));
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_playerpuzzle\event\game_completed);
        $sink->close();

        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame('lost', $attempt->status);
        $this->assertGreaterThan(0, (int) $attempt->timefinished);
        $this->assertNull($attempt->combatstate);
        $this->assertCount(1, $events);
        $this->assertSame('lost', reset($events)->other['status']);
        $this->assertFalse(match_abandon::is_available($this->instance, $userid));
        $this->assertFalse(match_abandon::abandon($this->instance, $this->cm, $context, $userid), 'nothing left');
    }

    /**
     * Tests that there is nothing to abandon without an attempt, in a phase nothing has happened in yet (its
     * difficulty can still be changed) and in a Demo.
     *
     * @return void
     */
    public function test_nothing_to_abandon_without_a_phase_under_way(): void {
        global $DB;
        $userid = (int) $this->student->id;
        $context = \context_module::instance($this->cm->id);

        $this->assertFalse(match_abandon::is_available($this->instance, $userid), 'no attempt');

        $token = $this->start_attempt(false);
        $this->assertFalse(match_abandon::is_available($this->instance, $userid), 'an untouched phase');
        $this->assertFalse(match_abandon::abandon($this->instance, $this->cm, $context, $userid));
        $this->assertSame('inprogress', $DB->get_field('playerpuzzle_attempts', 'status', ['token' => $token]));

        $DB->delete_records('playerpuzzle_attempts', ['token' => $token]);
        $this->start_attempt(true, true);
        $this->assertFalse(match_abandon::is_available($this->instance, $userid), 'a Demo');
    }
}
