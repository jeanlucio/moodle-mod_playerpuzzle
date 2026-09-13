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
 * External function tests for save_combat_state.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\combat_state;
use mod_playerpuzzle\local\engine\security;

/**
 * Tests for the mod_playerpuzzle_save_combat_state web service.
 *
 * @covers \mod_playerpuzzle\external\save_combat_state
 */
final class save_combat_state_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a playerpuzzle instance.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $record = array_merge(['course' => $this->course->id], $overrides);

        return $generator->create_instance($record);
    }

    /**
     * Calls the mod_playerpuzzle_save_combat_state web service through the real dispatch
     * path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_save_combat_state(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_save_combat_state', $args);
    }

    /**
     * Default args for a valid checkpoint, merged with per-test overrides.
     *
     * @param \stdClass $instance Instance record.
     * @param string $token Attempt token.
     * @param array $overrides Argument overrides.
     * @return array
     */
    private function valid_args(\stdClass $instance, string $token, array $overrides = []): array {
        return array_merge([
            'cmid'               => $instance->cmid,
            'token'              => $token,
            'boardgrid'          => array_fill(0, combat_state::BOARD_CELLS, 3),
            'currentplayerhp'    => 250,
            'currentbosshp'      => 180,
            'playershieldmeter'  => 40,
            'playershieldready'  => false,
            'playerpoisonmeter'  => 0,
            'playerpoisonrounds' => 0,
            'playermana'         => 60,
            'playermultiplier'   => 1.5,
            'bossshieldmeter'    => 0,
            'bossshieldready'    => true,
            'bosspoisonmeter'    => 30,
            'bosspoisonrounds'   => 2,
            'bossmana'           => 10,
            'bossmultiplier'     => 1.0,
            'currentturn'        => 'player',
        ], $overrides);
    }

    /**
     * Tests that a valid checkpoint is persisted and decodes back to exactly what was sent.
     *
     * @return void
     */
    public function test_saves_a_valid_checkpoint(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $result = $this->call_save_combat_state($this->valid_args($instance, $token));

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);

        $stored = combat_state::decode($DB->get_field('playerpuzzle_attempts', 'combatstate', ['id' => $attemptid]));
        $this->assertCount(combat_state::BOARD_CELLS, $stored['boardgrid']);
        $this->assertSame(3, $stored['boardgrid'][0]);
        $this->assertSame(250, $stored['currentplayerhp']);
        $this->assertSame(180, $stored['currentbosshp']);
        $this->assertSame(40, $stored['playershieldmeter']);
        $this->assertFalse($stored['playershieldready']);
        $this->assertTrue($stored['bossshieldready']);
        $this->assertSame(30, $stored['bosspoisonmeter']);
        $this->assertSame(2, $stored['bosspoisonrounds']);
        $this->assertSame('player', $stored['currentturn']);
    }

    /**
     * Tests that a later checkpoint overwrites the earlier one on the same attempt, rather
     * than accumulating rows — the same attempt row is updated in place.
     *
     * @return void
     */
    public function test_a_later_checkpoint_overwrites_the_earlier_one(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $this->call_save_combat_state($this->valid_args($instance, $token, ['currentplayerhp' => 250]));
        $this->call_save_combat_state($this->valid_args($instance, $token, ['currentplayerhp' => 90]));

        $stored = combat_state::decode($DB->get_field('playerpuzzle_attempts', 'combatstate', ['id' => $attemptid]));
        $this->assertSame(90, $stored['currentplayerhp']);
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['id' => $attemptid]));
    }

    /**
     * Tests that a board grid of the wrong length is rejected.
     *
     * @return void
     */
    public function test_rejects_a_board_grid_of_the_wrong_length(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_save_combat_state($this->valid_args($instance, $token, [
            'boardgrid' => array_fill(0, combat_state::BOARD_CELLS - 1, 0),
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('invalidcombatstate', $result['exception']->errorcode);
    }

    /**
     * Tests that a turn value outside player/boss is rejected.
     *
     * @return void
     */
    public function test_rejects_an_invalid_turn_value(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_save_combat_state($this->valid_args($instance, $token, [
            'currentturn' => 'referee',
        ]));

        $this->assertTrue($result['error']);
        $this->assertSame('invalidcombatstate', $result['exception']->errorcode);
    }

    /**
     * Tests that an unknown/forged token is rejected with the dedicated exception.
     *
     * @return void
     */
    public function test_rejects_unknown_token(): void {
        $instance = $this->make_instance();
        $this->setUser($this->student);

        $result = $this->call_save_combat_state($this->valid_args($instance, str_repeat('a', 64)));

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is enforced.
     *
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance();
        $modcontext = context_module::instance($instance->cmid);

        $prohibitedrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/playerpuzzle:view', CAP_PROHIBIT, $prohibitedrole, $modcontext);
        role_assign($prohibitedrole, $this->student->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $args = $this->valid_args($instance, $token);

        $this->expectException(\core\exception\require_login_exception::class);
        save_combat_state::execute(
            $args['cmid'],
            $args['token'],
            $args['boardgrid'],
            $args['currentplayerhp'],
            $args['currentbosshp'],
            $args['playershieldmeter'],
            $args['playershieldready'],
            $args['playerpoisonmeter'],
            $args['playerpoisonrounds'],
            $args['playermana'],
            $args['playermultiplier'],
            $args['bossshieldmeter'],
            $args['bossshieldready'],
            $args['bosspoisonmeter'],
            $args['bosspoisonrounds'],
            $args['bossmana'],
            $args['bossmultiplier'],
            $args['currentturn']
        );
    }
}
