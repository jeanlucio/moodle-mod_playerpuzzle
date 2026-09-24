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

use mod_playerpuzzle\local\questions_repository;
use mod_playerpuzzle\local\user_stock;

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
            'maxattempts'      => 0,
            'hud_coin_item'    => 0,
            'grade'            => 100,
            'gradepass'        => 0,
        ];

        $id = playerpuzzle_add_instance($data);

        $record = $DB->get_record('playerpuzzle', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Dragon Fight', $record->name);
        $this->assertSame(3, (int) $record->maxlevels);
        $this->assertSame(2000, (int) $record->basebosshp);
        $this->assertGreaterThan(0, (int) $record->timecreated);
    }

    /**
     * Regression test: the gradepass element added by standard_grading_coursemodule_elements()
     * submits null (not an empty string) when the teacher leaves "Passing grade" blank — the
     * exact shape produced by a real add_moduleinfo()/mod_form.php submission, reproduced here
     * via a hand-built object since the generator's own defaults always set a value explicitly.
     * Before the fix, this failed with "Column 'gradepass' cannot be null" (caught by CI Behat,
     * never by the PHPUnit suite, since every other test goes through the generator).
     *
     * @return void
     */
    public function test_add_instance_normalizes_a_null_gradepass_to_zero(): void {
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
            'maxattempts'      => 0,
            'hud_coin_item'    => 0,
            'grade'            => 100,
            'gradepass'        => null,
        ];

        $id = playerpuzzle_add_instance($data);

        $this->assertSame(0.0, (float) $DB->get_field('playerpuzzle', 'gradepass', ['id' => $id], MUST_EXIST));
    }

    /**
     * Tests that the submitted cooldown_amount/cooldown_unit pair (mod_form.php's
     * cooldowngroup) is converted to cooldown_seconds, the real column, and the two
     * transient fields are never persisted as-is.
     *
     * @return void
     */
    public function test_add_instance_converts_cooldown_amount_and_unit_to_seconds(): void {
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
            'maxattempts'      => 0,
            'hud_coin_item'    => 0,
            'grade'            => 100,
            'gradepass'        => 0,
            'cooldown_amount'  => 2,
            'cooldown_unit'    => 'hours',
        ];

        $id = playerpuzzle_add_instance($data);

        $this->assertSame(7200, (int) $DB->get_field('playerpuzzle', 'cooldown_seconds', ['id' => $id], MUST_EXIST));
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
     * Regression test: same null-to-zero normalization as
     * test_add_instance_normalizes_a_null_gradepass_to_zero(), but on the update path.
     *
     * @return void
     */
    public function test_update_instance_normalizes_a_null_gradepass_to_zero(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'gradepass' => 40]);

        $update = (object) $DB->get_record('playerpuzzle', ['id' => $instance->id], '*', MUST_EXIST);
        $update->instance = $instance->id;
        $update->gradepass = null;

        $result = playerpuzzle_update_instance($update);

        $this->assertTrue($result);
        $this->assertSame(0.0, (float) $DB->get_field('playerpuzzle', 'gradepass', ['id' => $instance->id]));
    }

    /**
     * Regression test: same cooldown_amount/cooldown_unit -> cooldown_seconds conversion as
     * test_add_instance_converts_cooldown_amount_and_unit_to_seconds(), but on the update
     * path.
     *
     * @return void
     */
    public function test_update_instance_converts_cooldown_amount_and_unit_to_seconds(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);

        $update = (object) $DB->get_record('playerpuzzle', ['id' => $instance->id], '*', MUST_EXIST);
        $update->instance = $instance->id;
        $update->cooldown_amount = 3;
        $update->cooldown_unit = 'days';

        $result = playerpuzzle_update_instance($update);

        $this->assertTrue($result);
        $this->assertSame(
            259200,
            (int) $DB->get_field('playerpuzzle', 'cooldown_seconds', ['id' => $instance->id])
        );
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
     * Tests that deleting an instance also deletes its own question bank — a table keyed
     * by playerpuzzleid, just as prone to being silently forgotten as the attempts tables
     * already covered above.
     *
     * @return void
     */
    public function test_delete_instance_also_deletes_own_questions(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);

        $questionid = questions_repository::add_question(
            (int) $instance->id,
            'multichoice',
            'Q?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        $result = playerpuzzle_delete_instance($instance->id);

        $this->assertTrue($result);
        $this->assertSame(0, $DB->count_records('playerpuzzle_questions', ['playerpuzzleid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('playerpuzzle_question_answers', ['questionid' => $questionid]));
    }

    /**
     * Tests that deleting an instance also deletes its loadout stock — a table keyed by
     * playerpuzzleid+userid, not tied to any attempt, so not covered by either test above.
     *
     * @return void
     */
    public function test_delete_instance_also_deletes_loadout_stock(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);

        user_stock::credit(2, (int) $instance->id, 'potion', 3);

        $result = playerpuzzle_delete_instance($instance->id);

        $this->assertTrue($result);
        $this->assertSame(0, user_stock::get_quantity(2, (int) $instance->id, 'potion'));
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
