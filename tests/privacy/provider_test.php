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
 * Privacy provider tests for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for the Privacy API provider.
 *
 * @covers \mod_playerpuzzle\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Creates a playerpuzzle course module and returns the cm record.
     *
     * @param \stdClass $course Course object.
     * @return \stdClass Course module record.
     */
    private function make_cm(\stdClass $course): \stdClass {
        return $this->getDataGenerator()->create_module('playerpuzzle', ['course' => $course->id]);
    }

    /**
     * Inserts one attempt record for the given user and activity.
     *
     * @param int $userid User ID.
     * @param int $playerpuzzleid Activity instance ID.
     * @param string $status Attempt status.
     * @return int Inserted attempt ID.
     */
    private function make_attempt(int $userid, int $playerpuzzleid, string $status = 'won'): int {
        global $DB;
        return $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid'    => $playerpuzzleid,
            'userid'            => $userid,
            'token'             => bin2hex(random_bytes(32)),
            'currentlevel'      => 1,
            'currentphase'      => 1,
            'bosshp_remaining'  => 0,
            'questions_correct' => 4,
            'questions_total'   => 5,
            'score'             => 80.0,
            'status'            => $status,
            'timecreated'       => time(),
            'timefinished'      => time(),
        ]);
    }

    /**
     * Tests that get_metadata declares the playerpuzzle_attempts table.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new collection('mod_playerpuzzle');
        $collection = provider::get_metadata($collection);
        $keys = array_map(fn($item) => $item->get_name(), $collection->get_collection());
        $this->assertContains('playerpuzzle_attempts', $keys);
    }

    /**
     * Tests that every real column of playerpuzzle_attempts (minus id) is either
     * declared in get_metadata() or listed here as a documented, justified exclusion.
     * Kept in sync by hand with the doc comment on provider.php::get_metadata():
     * playerpuzzleid is a structural foreign key never itself exported; token is an
     * opaque anti-replay value with no personal information; timemodified always
     * mirrors timecreated or timefinished, both already declared. Asserted against
     * the real schema via $DB->get_columns() rather than a fixed key list, so a
     * future column silently added to install.xml without a privacy decision fails
     * this test instead of just going undeclared by omission.
     *
     * @return void
     */
    public function test_get_metadata_every_column_is_declared_or_documented(): void {
        global $DB;

        $documentedexclusions = ['playerpuzzleid', 'token', 'timemodified'];

        $collection = provider::get_metadata(new collection('mod_playerpuzzle'));

        $tableitem = null;
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === 'playerpuzzle_attempts') {
                $tableitem = $item;
                break;
            }
        }
        $this->assertNotNull($tableitem);
        $declaredfields = array_keys($tableitem->get_privacy_fields());

        $realcolumns = array_values(array_diff(array_keys($DB->get_columns('playerpuzzle_attempts')), ['id']));

        $accountedfor = array_merge($declaredfields, $documentedexclusions);
        foreach ($realcolumns as $column) {
            $this->assertContains(
                $column,
                $accountedfor,
                "Column '$column' is neither declared in get_metadata() nor listed as a documented exclusion."
            );
        }

        // Also guards the other direction: an exclusion left in the list after the
        // column itself was renamed or dropped would otherwise go unnoticed.
        foreach ($documentedexclusions as $excluded) {
            $this->assertContains($excluded, $realcolumns);
            $this->assertNotContains($excluded, $declaredfields);
        }
    }

    /**
     * Tests that get_contexts_for_userid finds the context via an attempt.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($user->id, (int) $cm->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $contextids = $contextlist->get_contextids();

        $expected = \context_module::instance($cm->cmid)->id;
        $this->assertContains((string) $expected, $contextids);
    }

    /**
     * Tests that get_users_in_context returns every user with an attempt in it.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->make_attempt($usera->id, (int) $cm->id);
        $this->make_attempt($userb->id, (int) $cm->id);

        $context = \context_module::instance($cm->cmid);
        $userlist = new userlist($context, 'mod_playerpuzzle');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();

        $this->assertContains((int) $usera->id, $userids);
        $this->assertContains((int) $userb->id, $userids);
    }

    /**
     * Tests that get_users_in_context is a silent no-op for a non-module context.
     *
     * @return void
     */
    public function test_get_users_in_context_ignores_non_module_context(): void {
        $userlist = new userlist(\context_system::instance(), 'mod_playerpuzzle');

        provider::get_users_in_context($userlist);

        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * Tests that export_user_data writes the attempt data for the user's context.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($user->id, (int) $cm->id);

        $context = \context_module::instance($cm->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playerpuzzle', [$context->id]);
        provider::export_user_data($contextlist);

        $data = writer::with_context($context)->get_data([
            get_string('privacy:metadata:playerpuzzle_attempts', 'mod_playerpuzzle'),
        ]);
        $this->assertNotEmpty($data->attempts);
        $this->assertSame(80.0, (float) $data->attempts[0]->score);
    }

    /**
     * Tests that export_user_data keeps each context's attempts separate when the
     * approved list spans several activities.
     *
     * @return void
     */
    public function test_export_user_data_across_multiple_contexts(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm1 = $this->make_cm($course);
        $cm2 = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();

        $this->make_attempt($user->id, (int) $cm1->id);
        $this->make_attempt($user->id, (int) $cm1->id);
        $this->make_attempt($user->id, (int) $cm2->id);

        $context1 = \context_module::instance($cm1->cmid);
        $context2 = \context_module::instance($cm2->cmid);
        $contextlist = new approved_contextlist($user, 'mod_playerpuzzle', [$context1->id, $context2->id]);
        provider::export_user_data($contextlist);

        $data1 = writer::with_context($context1)->get_data([
            get_string('privacy:metadata:playerpuzzle_attempts', 'mod_playerpuzzle'),
        ]);
        $data2 = writer::with_context($context2)->get_data([
            get_string('privacy:metadata:playerpuzzle_attempts', 'mod_playerpuzzle'),
        ]);

        $this->assertCount(2, $data1->attempts);
        $this->assertCount(1, $data2->attempts);
    }

    /**
     * Tests that delete_data_for_user removes only that user's attempts from the
     * approved contexts.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->make_attempt($usera->id, (int) $cm->id);
        $this->make_attempt($userb->id, (int) $cm->id);

        $context = \context_module::instance($cm->cmid);
        $contextlist = new approved_contextlist($usera, 'mod_playerpuzzle', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts', ['userid' => $usera->id]));
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['userid' => $userb->id]));
    }

    /**
     * Tests the answered-question log: it is declared in get_metadata, exported alongside
     * the attempts, and removed when the owning attempt's data is deleted.
     *
     * @return void
     */
    public function test_attempt_questions_are_declared_exported_and_deleted(): void {
        global $DB;

        $collection = provider::get_metadata(new collection('mod_playerpuzzle'));
        $item = null;
        foreach ($collection->get_collection() as $entry) {
            if ($entry->get_name() === 'playerpuzzle_attempt_questions') {
                $item = $entry;
            }
        }
        $this->assertNotNull($item);
        // Every real column except the structural id/attemptid/questionid must be declared.
        $declared = array_keys($item->get_privacy_fields());
        $real = array_diff(array_keys($DB->get_columns('playerpuzzle_attempt_questions')), ['id', 'attemptid', 'questionid']);
        $this->assertEmpty(array_diff($real, $declared), 'Undeclared column in playerpuzzle_attempt_questions.');

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $attemptid = $this->make_attempt($user->id, (int) $cm->id);
        \mod_playerpuzzle\local\attempt_questions::record($attemptid, 3, 1, 1, '<p>Q</p>', 'wrong', 'right', false);

        $context = \context_module::instance($cm->cmid);
        provider::export_user_data(new approved_contextlist($user, 'mod_playerpuzzle', [$context->id]));
        $data = writer::with_context($context)->get_data([
            get_string('privacy:metadata:playerpuzzle_attempt_questions', 'mod_playerpuzzle'),
        ]);
        $this->assertCount(1, $data->questions);
        $this->assertSame('right', $data->questions[0]->correctanswer);

        provider::delete_data_for_user(new approved_contextlist($user, 'mod_playerpuzzle', [$context->id]));
        $this->assertSame(0, $DB->count_records('playerpuzzle_attempt_questions', ['attemptid' => $attemptid]));
    }

    /**
     * Tests that delete_data_for_users removes data only for the listed users.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->make_attempt($usera->id, (int) $cm->id);
        $this->make_attempt($userb->id, (int) $cm->id);

        $context = \context_module::instance($cm->cmid);
        $approvedlist = new approved_userlist($context, 'mod_playerpuzzle', [$usera->id]);
        provider::delete_data_for_users($approvedlist);

        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts', ['userid' => $usera->id]));
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['userid' => $userb->id]));
    }

    /**
     * Tests that delete_data_for_all_users_in_context clears every attempt in that
     * context only, leaving another activity's attempts untouched.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cmtarget = $this->make_cm($course);
        $cmother = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($user->id, (int) $cmtarget->id);
        $this->make_attempt($user->id, (int) $cmother->id);

        provider::delete_data_for_all_users_in_context(\context_module::instance($cmtarget->cmid));

        $this->assertSame(0, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => (int) $cmtarget->id]));
        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => (int) $cmother->id]));
    }

    /**
     * Tests that delete_data_for_all_users_in_context is a silent no-op for a
     * non-module context.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context_ignores_non_module_context(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $user = $this->getDataGenerator()->create_user();
        $this->make_attempt($user->id, (int) $cm->id);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => (int) $cm->id]));
    }

    /**
     * Regression guard mirroring the pattern documented for mod_playerwords: a page
     * module whose course_modules row was made to carry the same numeric instance id
     * as a real playerpuzzle activity must never be mistaken for it — every query in
     * provider.php joins through {modules}.name = 'playerpuzzle', not a bare instance
     * lookup, precisely to prevent this.
     *
     * @return void
     */
    public function test_get_users_in_context_ignores_colliding_instance_id_from_other_module_type(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->make_cm($course);
        $student = $this->getDataGenerator()->create_user();
        $this->make_attempt($student->id, (int) $cm->id);

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'instance', $cm->id, ['id' => $page->cmid]);

        $context = \context_module::instance($page->cmid);
        $userlist = new userlist($context, 'mod_playerpuzzle');
        provider::get_users_in_context($userlist);

        $this->assertSame([], $userlist->get_userids());
    }
}
