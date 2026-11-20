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

namespace mod_playerpuzzle\external;

use core_external\external_api;
use mod_playerpuzzle\local\constants;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\user_hero;

/**
 * Tests for starting the next match from inside the game.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_playerpuzzle\external\start_match
 * @covers     \mod_playerpuzzle\local\match_start
 */
final class start_match_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass An enrolled student. */
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
        $this->setUser($this->student);
    }

    /**
     * Creates an activity that needs no questions to be played.
     *
     * @param array $overrides Fields to set.
     * @return \stdClass The instance, with its cmid.
     */
    private function make_instance(array $overrides = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')->create_instance(array_merge([
            'course' => $this->course->id,
            'gamemode' => constants::GAMEMODE_CAMPAIGN,
            'maxlevels' => 2,
            'minquestions' => 0,
        ], $overrides));
    }

    /**
     * Calls the function as the game does.
     *
     * @param array $args The arguments.
     * @return array The response, {error, data}.
     */
    private function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_start_match', $args);
    }

    /**
     * Tests that a Campaign whose hero is chosen starts in the page: the config of the match and what goes in the
     * game's box come back, and calling again resumes the same attempt under a new token.
     *
     * @return void
     */
    public function test_a_campaign_with_its_hero_starts_in_the_page(): void {
        global $DB;
        $instance = $this->make_instance();
        user_hero::choose((int) $instance->id, (int) $this->student->id, 1, 'warriorf');

        $result = $this->call(['cmid' => $instance->cmid, 'difficulty' => 'hard']);

        $this->assertFalse($result['error']);
        $this->assertSame('', $result['data']['redirect']);
        $config = json_decode($result['data']['config'], true);
        $this->assertSame((int) $instance->cmid, (int) $config['cmid']);
        $this->assertSame('hard', $config['difficulty']);
        $parts = ['playerpuzzle-modal', 'playerpuzzle-giveup', 'playerpuzzle-howto', 'playerpuzzle-opponent-help',
            'pp-board-grid', 'pp-bootstrap-loader'];
        foreach ($parts as $id) {
            $this->assertStringContainsString("id=\"{$id}\"", $result['data']['html']);
        }
        $attempt = $DB->get_record('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame($config['token'], $attempt->token);

        $again = $this->call(['cmid' => $instance->cmid, 'difficulty' => 'hard']);
        $token = json_decode($again['data']['config'], true)['token'];
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id]));
        $this->assertNotSame($config['token'], $token, 'resuming rotates the token');
        $this->assertSame($token, $DB->get_field('playerpuzzle_attempts', 'token', ['id' => $attempt->id]));
    }

    /**
     * Tests that a Campaign still to be chosen a hero for goes to the Lobby, where the choice begins, and starts
     * nothing.
     *
     * @return void
     */
    public function test_a_campaign_without_a_hero_goes_to_the_lobby(): void {
        global $DB;
        $instance = $this->make_instance();

        $result = $this->call(['cmid' => $instance->cmid]);

        $this->assertFalse($result['error']);
        $lobby = new \moodle_url('/mod/playerpuzzle/view.php', ['id' => $instance->cmid]);
        $this->assertSame($lobby->out(false), $result['data']['redirect']);
        $this->assertSame('', $result['data']['config']);
        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id]));
    }

    /**
     * Tests that a start the rules refuse goes to the Lobby with the reason, and starts nothing.
     *
     * @return void
     */
    public function test_a_refused_start_goes_to_the_lobby_with_its_reason(): void {
        global $DB;
        $instance = $this->make_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'max_single_matches' => 1]);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'status', 'lost', ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'timefinished', time(), ['token' => $token]);

        $result = $this->call(['cmid' => $instance->cmid]);

        $this->assertFalse($result['error']);
        $this->assertStringContainsString('refused=', $result['data']['redirect']);
        $this->assertSame('', $result['data']['html']);
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id]));
    }

    /**
     * Tests that another Demo starts as a Demo.
     *
     * @return void
     */
    public function test_another_demo_starts_as_a_demo(): void {
        $instance = $this->make_instance();

        $result = $this->call(['cmid' => $instance->cmid, 'isdemo' => true]);

        $this->assertFalse($result['error']);
        $this->assertSame('', $result['data']['redirect']);
        $this->assertNotEmpty(json_decode($result['data']['config'], true)['isdemo']);
    }

    /**
     * Tests that a student who cannot view the activity cannot start a match.
     *
     * @return void
     */
    public function test_a_user_outside_the_course_cannot_start_a_match(): void {
        $instance = $this->make_instance();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = $this->call(['cmid' => $instance->cmid]);

        $this->assertTrue($result['error']);
    }
}
