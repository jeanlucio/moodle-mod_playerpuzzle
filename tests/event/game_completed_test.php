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
 * Tests for the game_completed event.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\event;

use mod_playerpuzzle\external\save_progress;
use mod_playerpuzzle\local\engine\security;

/**
 * Tests for game_completed.
 *
 * @covers \mod_playerpuzzle\event\game_completed
 * @covers \mod_playerpuzzle\external\save_progress
 */
final class game_completed_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates an instance with sane defaults for a victory/defeat resolution.
     *
     * @param array $overrides Fields to override on top of the defaults.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $record = array_merge(
            ['course' => $this->course->id, 'basebosshp' => 1000, 'minquestions' => 0],
            $overrides
        );
        return $generator->create_instance($record);
    }

    /**
     * Tests that a victory triggers game_completed with the winning status and gamemode.
     *
     * @return void
     */
    public function test_victory_triggers_game_completed(): void {
        $instance = $this->make_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $sink = $this->redirectEvents();
        save_progress::execute($instance->cmid, $token, 1, 500, 0, 0);
        $events = $sink->get_events();
        $sink->close();

        $completed = array_values(array_filter($events, fn ($event) => $event instanceof game_completed));
        $this->assertCount(1, $completed);

        $event = $completed[0];
        $this->assertSame('won', $event->other['status']);
        $this->assertSame(PLAYERPUZZLE_GAMEMODE_CAMPAIGN, $event->other['gamemode']);
        $this->assertSame('playerpuzzle_attempts', $event->objecttable);
    }

    /**
     * Tests that a defeat triggers game_completed with the losing status.
     *
     * @return void
     */
    public function test_defeat_triggers_game_completed(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $sink = $this->redirectEvents();
        save_progress::execute($instance->cmid, $token, 0, 0, 0, 0);
        $events = $sink->get_events();
        $sink->close();

        $completed = array_values(array_filter($events, fn ($event) => $event instanceof game_completed));
        $this->assertCount(1, $completed);
        $this->assertSame('lost', $completed[0]->other['status']);
    }

    /**
     * Tests that a rejected replay (an already-consumed token) does not trigger a second
     * game_completed event — only a genuine state transition does.
     *
     * @return void
     */
    public function test_replayed_token_does_not_trigger_a_second_event(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        save_progress::execute($instance->cmid, $token, 1, 500, 0, 0);

        $sink = $this->redirectEvents();
        try {
            save_progress::execute($instance->cmid, $token, 1, 500, 0, 0);
        } catch (\moodle_exception $e) {
            // Expected: the token was already consumed by the first call above.
            unset($e);
        }
        $events = $sink->get_events();
        $sink->close();

        $completed = array_filter($events, fn ($event) => $event instanceof game_completed);
        $this->assertCount(0, $completed);
    }
}
