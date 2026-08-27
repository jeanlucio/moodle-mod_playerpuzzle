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
 * Tests for the playerpuzzle_add_instance/update_instance/delete_instance callbacks.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for playerpuzzle_add_instance(), playerpuzzle_update_instance() and
 * playerpuzzle_delete_instance().
 *
 * @covers ::playerpuzzle_add_instance
 * @covers ::playerpuzzle_update_instance
 * @covers ::playerpuzzle_delete_instance
 */
final class lib_crud_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Tests that adding an instance persists the submitted fields and stamps timecreated.
     *
     * @return void
     */
    public function test_add_instance_persists_fields(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $data = (object) [
            'course'           => $course->id,
            'name'             => 'Dragon Fight',
            'intro'            => '',
            'introformat'      => FORMAT_HTML,
            'maxlevels'        => 3,
            'basestudenthp'    => 150,
            'bossavatar'       => 'dragon.png',
            'basebosshp'       => 2000,
            'bossdamage'       => 15,
            'questioncategory' => 0,
            'timelimit'        => 0,
            'maxattempts'      => 0,
            'hud_coin_item'    => 0,
            'hud_sword_item'   => 0,
            'hud_shield_item'  => 0,
        ];

        $id = playerpuzzle_add_instance($data);

        $record = $DB->get_record('playerpuzzle', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Dragon Fight', $record->name);
        $this->assertSame(3, (int) $record->maxlevels);
        $this->assertSame(2000, (int) $record->basebosshp);
        $this->assertGreaterThan(0, (int) $record->timecreated);
    }

    /**
     * Tests that updating an instance persists the new field values.
     *
     * @return void
     */
    public function test_update_instance_persists_fields(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'basebosshp' => 1000]);

        $update = (object) $DB->get_record('playerpuzzle', ['id' => $instance->id], '*', MUST_EXIST);
        $update->instance = $instance->id;
        $update->basebosshp = 5000;

        $result = playerpuzzle_update_instance($update);

        $this->assertTrue($result);
        $this->assertSame(5000, (int) $DB->get_field('playerpuzzle', 'basebosshp', ['id' => $instance->id]));
    }

    /**
     * Tests that deleting an instance also deletes its attempts — every plugin table
     * keyed by the instance's own ID must be cleared when the instance is deleted, not
     * just the instance's own row.
     *
     * @return void
     */
    public function test_delete_instance_also_deletes_attempts(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);

        $attemptid = $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid'         => 2,
            'token'          => str_repeat('a', 64),
            'status'         => 'won',
            'timecreated'    => time(),
        ]);
        $DB->insert_record('playerpuzzle_attempt_questions', (object) [
            'attemptid'   => $attemptid,
            'questionid'  => 7,
            'iscorrect'   => 1,
            'timecreated' => time(),
        ]);

        $result = playerpuzzle_delete_instance($instance->id);

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('playerpuzzle', ['id' => $instance->id]));
        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('playerpuzzle_attempt_questions', ['attemptid' => $attemptid]));
    }

    /**
     * Tests that deleting a non-existent instance returns false without erroring.
     *
     * @return void
     */
    public function test_delete_instance_unknown_id_returns_false(): void {
        $this->assertFalse(playerpuzzle_delete_instance(999999));
    }
}
