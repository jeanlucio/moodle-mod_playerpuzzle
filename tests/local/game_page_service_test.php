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
 * Unit tests for game_page_service.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for game_page_service.
 *
 * @covers \mod_playerpuzzle\local\game_page_service
 */
final class game_page_service_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Student used by every test. */
    private \stdClass $student;

    /** @var \moodle_url Dummy return URL used by every test. */
    private \moodle_url $returnurl;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->returnurl = new \moodle_url('/mod/playerpuzzle/view.php', ['id' => 1]);
    }

    /**
     * Creates a course module and instance for the given overrides.
     *
     * @param array $overrides Instance field overrides.
     * @return array{0: \stdClass, 1: \stdClass} [$cm, $instance]
     */
    private function make_cm_and_instance(array $overrides = []): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $record = array_merge(['course' => $this->course->id], $overrides);
        $instance = $generator->create_instance($record);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        return [$cm, $instance];
    }

    /**
     * Inserts a finished attempt row for the given instance/user.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @return void
     */
    private function make_finished_attempt(int $instanceid, int $userid): void {
        global $DB;
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instanceid,
            'userid'         => $userid,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'lost',
            'timecreated'    => time(),
            'timefinished'   => time(),
        ]);
    }

    /**
     * Tests that a limit of 0 (unlimited) never blocks, for either game mode.
     *
     * @return void
     */
    public function test_check_attempt_limit_zero_is_unlimited(): void {
        [, $campaign] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN, 'maxattempts' => 0]);
        [, $single] = $this->make_cm_and_instance([
            'gamemode' => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'max_single_matches' => 0,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->make_finished_attempt($campaign->id, (int) $this->student->id);
            $this->make_finished_attempt($single->id, (int) $this->student->id);
        }

        game_page_service::check_attempt_limit($campaign, (int) $this->student->id, $this->returnurl);
        game_page_service::check_attempt_limit($single, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that Campaign mode counts finished attempts against maxattempts, and blocks
     * once the limit is reached.
     *
     * @return void
     */
    public function test_check_attempt_limit_campaign_uses_maxattempts(): void {
        [, $instance] = $this->make_cm_and_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'maxattempts' => 2,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        $this->expectException(\moodle_exception::class);
        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
    }

    /**
     * Tests that Single Match mode counts finished attempts against
     * max_single_matches, entirely independent of maxattempts (which is
     * Campaign-only and hidden from the form in this mode).
     *
     * @return void
     */
    public function test_check_attempt_limit_single_match_uses_max_single_matches(): void {
        [, $instance] = $this->make_cm_and_instance([
            'gamemode'           => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'max_single_matches' => 1,
            'maxattempts'        => 0,
        ]);
        $this->make_finished_attempt($instance->id, (int) $this->student->id);

        $this->expectException(\moodle_exception::class);
        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
    }

    /**
     * Tests that an in-progress (unfinished) attempt never counts against the limit —
     * only attempts already in a final status do, since an abandoned in-progress row is
     * meant to be resumed, not to silently consume a slot.
     *
     * @return void
     */
    public function test_check_attempt_limit_ignores_inprogress_attempts(): void {
        global $DB;

        [, $instance] = $this->make_cm_and_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'maxattempts' => 1,
        ]);
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid'         => $this->student->id,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'inprogress',
            'timecreated'    => time(),
        ]);

        game_page_service::check_attempt_limit($instance, (int) $this->student->id, $this->returnurl);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that build_game_config() resolves the scaled boss/student HP for the
     * attempt's own level/phase, not the raw configured base.
     *
     * @return void
     */
    public function test_build_game_config_scales_hp_for_current_phase(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'      => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'basebosshp'    => 100,
            'basestudenthp' => 100,
            'bossdamage'    => 10,
        ]);
        $context = \context_module::instance($cm->id);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 5, ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 1, ['token' => $token]);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        // Level 5, Phase 1 with base 100: boss 300, student 220 (§4.6 worked example).
        $this->assertSame(300, $config['bosshp']);
        $this->assertSame(220, $config['studenthp']);
        // Combat damage reuses the same boss HP growth curve (§17): base 10 -> 30 at Level 5/Phase 1.
        $this->assertSame(30, $config['bossdamage']);
        $this->assertSame(5, $config['currentlevel']);
        $this->assertSame(1, $config['currentphase']);
        $this->assertNotSame($token, $config['token']);
    }

    /**
     * Tests that Single Match mode always resolves to the base HP unchanged, since its
     * attempts stay at Level 1, Phase 1 — no special-casing needed in the service.
     *
     * @return void
     */
    public function test_build_game_config_single_match_uses_base_hp(): void {
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode'      => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'basebosshp'    => 250,
            'basestudenthp' => 80,
            'bossdamage'    => 15,
        ]);
        $context = \context_module::instance($cm->id);

        $config = game_page_service::build_game_config($cm, $instance, $context, (int) $this->student->id, false);

        $this->assertSame(250, $config['bosshp']);
        $this->assertSame(80, $config['studenthp']);
        $this->assertSame(15, $config['bossdamage']);
    }
}
