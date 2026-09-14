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
 * Backup and restore tests for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

use core_courseformat\local\cmactions;

/**
 * Tests that backing up and restoring a playerpuzzle activity — either duplicating it or
 * carrying it across a full course backup — completes without error and correctly remaps
 * every cross-table reference.
 *
 * @covers \backup_playerpuzzle_activity_structure_step
 * @covers \restore_playerpuzzle_activity_structure_step
 */
final class backup_restore_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Duplicates a course module, using the Moodle 5.2+ API when available and falling
     * back to the deprecated one otherwise.
     *
     * @param \stdClass $course Course the module belongs to.
     * @param \stdClass $cm Course module to duplicate.
     * @return \stdClass|\core_course\cm_info The new course module.
     */
    private function duplicate_cm(\stdClass $course, \stdClass $cm): \stdClass|\core_course\cm_info {
        // Core's duplicate_module() is deprecated since Moodle 5.2 (MDL-86858), replaced by
        // cmactions::duplicate() — but that method doesn't exist before 5.2, so this must
        // stay guarded rather than switched outright while the plugin supports 4.5+5.2.
        if (method_exists(cmactions::class, 'duplicate')) {
            return (new cmactions($course))->duplicate($cm->id);
        }
        return duplicate_module($course, $cm);
    }

    /**
     * Inserts a logged question row for the given attempt.
     *
     * @param int $attemptid Attempt ID.
     * @param int $questionid Source question bank id.
     * @return int New row id.
     */
    private function make_question_log(int $attemptid, int $questionid = 0): int {
        global $DB;
        return $DB->insert_record('playerpuzzle_attempt_questions', (object) [
            'attemptid'     => $attemptid,
            'questionid'    => $questionid,
            'attemptlevel'  => 1,
            'attemptphase'  => 3,
            'questiontext'  => 'Quanto é 2 + 2?',
            'chosenanswer'  => '4',
            'correctanswer' => '4',
            'iscorrect'     => 1,
            'timecreated'   => time(),
        ]);
    }

    /**
     * Inserts a consumable-use counter row for the given attempt.
     *
     * @param int $attemptid Attempt ID.
     * @return int New row id.
     */
    private function make_consumable_use(int $attemptid): int {
        global $DB;
        return $DB->insert_record('playerpuzzle_attempt_consumables', (object) [
            'attemptid'      => $attemptid,
            'consumabletype' => 'potion',
            'timesused'      => 2,
        ]);
    }

    /**
     * Backs up the given course and restores it into a brand new course, returning that
     * course. Mirrors block_playerhud's/mod_playerwords' own full-course backup/restore
     * test pattern.
     *
     * @param \stdClass $course Source course.
     * @return \stdClass The new course the backup was restored into.
     */
    private function backup_and_restore_into_new_course(\stdClass $course): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $admin = get_admin();

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id
        );
        $bc->execute_plan();
        $backupfile = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $newcourse = $this->getDataGenerator()->create_course();
        $tempdir = \restore_controller::get_tempdir_name($newcourse->id, $admin->id);
        $fp = get_file_packer('application/vnd.moodle.backup');
        $backupfile->extract_to_pathname($fp, make_backup_temp_directory($tempdir));

        $rc = new \restore_controller(
            $tempdir,
            $newcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $admin->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return $newcourse;
    }

    /**
     * Tests that duplicating an activity copies its attempts and nested rows, renames the
     * copy, and is immediately visible — a regression test for a missing
     * prepare_activity_structure() call in the restore step, which would leave the
     * restore's old-to-new context mapping unset. That mapping is what the generic
     * post-restore duplicate flow (renaming to "(copy)", moving the module, rebuilding the
     * course cache) and the generic calendar-events restore step both depend on.
     *
     * @return void
     */
    public function test_duplicate_activity(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid'         => $user->id,
            'token'          => bin2hex(random_bytes(32)),
            'status'         => 'won',
            'score'          => 100,
            'timecreated'    => time(),
            'timefinished'   => time(),
        ]);

        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id, $course->id, false, MUST_EXIST);
        $newcm = $this->duplicate_cm($course, $cm);

        $this->assertNotNull($newcm);
        $this->assertNotSame($cm->id, $newcm->id);
        $this->assertStringContainsString('(copy)', $newcm->name);

        // Duplicating an activity never includes user data (userinfo is off) — only the
        // full course backup/restore path does, covered separately below by
        // test_backup_restore_preserves_settings_and_attempt_data(). A duplicate correctly
        // produces a fresh instance with none of the source student's attempts.
        $newinstance = $DB->get_record('playerpuzzle', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => $newinstance->id]));

        // No explicit cache purge here: this proves the context mapping (and therefore
        // the whole post-restore cleanup) actually ran, since a stale course cache is
        // exactly the symptom the missing mapping used to cause.
        $modinfo = get_fast_modinfo($course->id);
        $this->assertNotNull($modinfo->get_cm($newcm->id));

        // Regression guard: the restore step must not also call
        // playerpuzzle_grade_item_update() in after_execute() — that would race against
        // the generic grades-restore step and leave two grade_items for the same instance.
        $this->assertSame(1, $DB->count_records('grade_items', [
            'courseid'     => $course->id,
            'itemtype'     => 'mod',
            'itemmodule'   => 'playerpuzzle',
            'iteminstance' => $newinstance->id,
        ]));
    }

    /**
     * The restored attempt gets a freshly regenerated token, never the original bytes —
     * the column carries a site-wide UNIQUE index, so preserving the original would collide
     * whenever a backup is restored back into a site that still has the source attempt
     * (duplicating a whole course, or an Import).
     *
     * @return void
     */
    public function test_backup_restore_regenerates_the_attempt_token(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);
        $originaltoken = bin2hex(random_bytes(32));
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid'         => $user->id,
            'token'          => $originaltoken,
            'status'         => 'won',
            'timecreated'    => time(),
            'timefinished'   => time(),
        ]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playerpuzzle', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newtoken = $DB->get_field('playerpuzzle_attempts', 'token', ['playerpuzzleid' => $newinstance->id]);
        $this->assertNotSame($originaltoken, $newtoken);
        $this->assertSame(64, strlen($newtoken));
        // The original row is untouched — proves this is a fresh row, not a token rewrite.
        $this->assertTrue($DB->record_exists('playerpuzzle_attempts', ['token' => $originaltoken]));
    }

    /**
     * A full course backup/restore must carry every configuration column and each
     * attempt's own data — a regression test for the backup/restore checklist rule that
     * every install.xml column must be mirrored into the matching backup_nested_element()
     * attribute list; a column added after the initial backup implementation silently
     * reverts to its DB default on restore otherwise, with nothing in
     * PHPCS/moodlecheck/PHPStan catching the omission.
     *
     * @return void
     */
    public function test_backup_restore_preserves_settings_and_attempt_data(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course'             => $course->id,
            'gamemode'           => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod'        => PLAYERPUZZLE_GRADE_AVERAGE,
            'grade'              => 55,
            'gradepass'          => 20,
            'completionattempts' => 3,
            'completionwins'     => 1,
            'maxconsumables'     => 4,
        ]);
        $attemptid = $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid'    => $instance->id,
            'userid'            => $user->id,
            'token'             => bin2hex(random_bytes(32)),
            'currentlevel'      => 2,
            'currentphase'      => 7,
            'difficulty'        => 'hard',
            'questions_correct' => 4,
            'questions_total'   => 5,
            'coins_earned'      => 30,
            'status'            => 'won',
            'score'             => 91.5,
            'timecreated'       => time(),
            'timefinished'      => time(),
        ]);
        $this->make_question_log($attemptid);
        $this->make_consumable_use($attemptid);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playerpuzzle', ['course' => $newcourse->id], '*', MUST_EXIST);
        $this->assertSame(PLAYERPUZZLE_GAMEMODE_SINGLE, $newinstance->gamemode);
        $this->assertSame(PLAYERPUZZLE_GRADE_AVERAGE, (int) $newinstance->grademethod);
        $this->assertEqualsWithDelta(55.0, (float) $newinstance->grade, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $newinstance->gradepass, 0.001);
        $this->assertSame(3, (int) $newinstance->completionattempts);
        $this->assertSame(1, (int) $newinstance->completionwins);
        $this->assertSame(4, (int) $newinstance->maxconsumables);

        $newattempt = $DB->get_record(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $newinstance->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(2, (int) $newattempt->currentlevel);
        $this->assertSame(7, (int) $newattempt->currentphase);
        $this->assertSame('hard', $newattempt->difficulty);
        $this->assertSame('won', $newattempt->status);
        $this->assertEqualsWithDelta(91.5, (float) $newattempt->score, 0.001);

        $newquestion = $DB->get_record(
            'playerpuzzle_attempt_questions',
            ['attemptid' => $newattempt->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame('Quanto é 2 + 2?', $newquestion->questiontext);
        $this->assertSame(1, (int) $newquestion->iscorrect);

        $newconsumable = $DB->get_record(
            'playerpuzzle_attempt_consumables',
            ['attemptid' => $newattempt->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame('potion', $newconsumable->consumabletype);
        $this->assertSame(2, (int) $newconsumable->timesused);
    }

    /**
     * Skips the current test when block_playerhud is not installed.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a block_instances record for block_playerhud in the given course context.
     *
     * @param \stdClass $course Course object.
     * @return int Block instance ID.
     */
    private function make_block_instance(\stdClass $course): int {
        global $DB;
        $ctx = \context_course::instance($course->id);
        return $DB->insert_record('block_instances', (object) [
            'blockname'         => 'playerhud',
            'parentcontextid'   => $ctx->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'   => 'course-view-*',
            'subpagepattern'    => null,
            'defaultregion'     => 'side-pre',
            'defaultweight'     => 0,
            'configdata'        => base64_encode(serialize(new \stdClass())),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
    }

    /**
     * Inserts a block_playerhud_items record for the given block instance.
     *
     * @param int $blockinstanceid Block instance ID.
     * @return int Item ID.
     */
    private function make_item(int $blockinstanceid): int {
        global $DB;
        return $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $blockinstanceid,
            'name'            => 'Troféu',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
    }

    /**
     * A full course backup/restore into a new course carries the question category along
     * as a genuine copy — the restored activity's questioncategory must point at a real,
     * freshly created category, distinct from the original. Same remap pattern already
     * shipped by mod_game for its own questioncategoryid column
     * (backup/moodle2/restore_game_stepslib.php): get_mappingid('question_category', ...),
     * relying on core's own generic question-bank restore step
     * (backup/moodle2/restore_stepslib.php::process_question_category()) to register the
     * mapping whenever the category was actually part of the backup.
     *
     * @return void
     */
    public function test_backup_restore_remaps_questioncategory(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $coursecontext->id]);
        $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $category->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $generator->create_instance(['course' => $course->id, 'questioncategory' => $category->id]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playerpuzzle', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newcategoryid = (int) $newinstance->questioncategory;
        $this->assertNotSame((int) $category->id, $newcategoryid);
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $newcategoryid]));
    }

    /**
     * Duplicating an activity within the same course never touches the block, so its
     * hud_win_grant_item — still a genuinely valid item in that same course — must survive
     * unchanged, even though no playerhud_item restore mapping was ever registered.
     *
     * @return void
     */
    public function test_duplicate_activity_preserves_hud_item_from_same_course(): void {
        global $CFG, $DB;
        $this->skip_if_no_playerhud();
        require_once($CFG->dirroot . '/course/lib.php');
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course' => $course->id, 'hud_win_grant_item' => $itemid,
        ]);

        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id, $course->id, false, MUST_EXIST);
        $newcm = $this->duplicate_cm($course, $cm);

        $newinstance = $DB->get_record('playerpuzzle', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame($itemid, (int) $newinstance->hud_win_grant_item);
    }

    /**
     * A full course backup/restore into a new course carries the PlayerHUD block along, so
     * the restored activity's hud_win_grant_item must point at the item's NEW id, via the
     * playerhud_item mapping block_playerhud's own restore step registers.
     *
     * @return void
     */
    public function test_backup_restore_full_course_remaps_hud_item(): void {
        global $CFG, $DB;
        $this->skip_if_no_playerhud();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $biid = $this->make_block_instance($course);
        $itemid = $this->make_item($biid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $generator->create_instance(['course' => $course->id, 'hud_win_grant_item' => $itemid]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playerpuzzle', ['course' => $newcourse->id], '*', MUST_EXIST);
        $newblock = $DB->get_record('block_instances', [
            'blockname' => 'playerhud',
            'parentcontextid' => \context_course::instance($newcourse->id)->id,
        ], '*', MUST_EXIST);
        $newitemid = $DB->get_field('block_playerhud_items', 'id', [
            'blockinstanceid' => $newblock->id, 'name' => 'Troféu',
        ], MUST_EXIST);

        $this->assertNotSame($itemid, (int) $newitemid);
        $this->assertSame((int) $newitemid, (int) $newinstance->hud_win_grant_item);
    }

    /**
     * An activity whose hud_win_grant_item points at another course's PlayerHUD item — a
     * stale or misconfigured reference that predates this activity's own backup — must have
     * that field dropped on restore, never silently kept pointing at a foreign course's item.
     * The foreign course's own PlayerHUD block is deliberately not part of this backup, so
     * no playerhud_item mapping exists for it either.
     *
     * @return void
     */
    public function test_backup_restore_drops_hud_item_from_foreign_course(): void {
        global $CFG, $DB;
        $this->skip_if_no_playerhud();
        $this->setAdminUser();

        $foreigncourse = $this->getDataGenerator()->create_course();
        $foreignbiid = $this->make_block_instance($foreigncourse);
        $foreignitemid = $this->make_item($foreignbiid);

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $generator->create_instance(['course' => $course->id, 'hud_win_grant_item' => $foreignitemid]);

        $newcourse = $this->backup_and_restore_into_new_course($course);

        $newinstance = $DB->get_record('playerpuzzle', ['course' => $newcourse->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $newinstance->hud_win_grant_item);
    }
}
