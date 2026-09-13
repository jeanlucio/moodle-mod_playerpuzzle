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
 * Tests for the game_started event.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\event;

use mod_playerpuzzle\local\game_page_service;

/**
 * Tests for game_started.
 *
 * @covers \mod_playerpuzzle\event\game_started
 * @covers \mod_playerpuzzle\local\game_page_service
 */
final class game_started_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Tests that starting a brand new attempt triggers game_started with the expected data.
     *
     * @return void
     */
    public function test_build_game_config_triggers_game_started_for_a_new_attempt(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course'   => $course->id,
            'gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
        ]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $context = \context_module::instance($cm->id);

        $sink = $this->redirectEvents();
        game_page_service::build_game_config($cm, $instance, $context, (int) $student->id, false, 'hard');
        $events = $sink->get_events();
        $sink->close();

        $gamestarted = array_values(array_filter($events, fn ($event) => $event instanceof game_started));
        $this->assertCount(1, $gamestarted);

        $event = $gamestarted[0];
        $this->assertSame($context->id, $event->contextid);
        $this->assertSame(PLAYERPUZZLE_GAMEMODE_CAMPAIGN, $event->other['gamemode']);
        $this->assertSame('hard', $event->other['difficulty']);
        $this->assertSame('playerpuzzle_attempts', $event->objecttable);
    }

    /**
     * Tests that resuming an already in-progress attempt does not fire a second
     * game_started event — only a genuinely new attempt row does.
     *
     * @return void
     */
    public function test_build_game_config_does_not_trigger_game_started_on_resume(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $context = \context_module::instance($cm->id);

        game_page_service::build_game_config($cm, $instance, $context, (int) $student->id, false);

        $sink = $this->redirectEvents();
        game_page_service::build_game_config($cm, $instance, $context, (int) $student->id, false);
        $events = $sink->get_events();
        $sink->close();

        $gamestarted = array_filter($events, fn ($event) => $event instanceof game_started);
        $this->assertCount(0, $gamestarted);
    }
}
