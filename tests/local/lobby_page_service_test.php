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
 * Unit tests for lobby_page_service.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for lobby_page_service.
 *
 * @covers \mod_playerpuzzle\local\lobby_page_service
 */
final class lobby_page_service_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Student used by every test. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
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
     * Inserts a block_playerhud block instance in the course, with one enabled item.
     *
     * @param string $name Item display name.
     * @return array{0: int, 1: int} [$blockinstanceid, $itemid]
     */
    private function make_hud_item(string $name = 'Gold Coin'): array {
        global $DB;

        $ctx = \context_course::instance($this->course->id);
        $biid = $DB->insert_record('block_instances', (object) [
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
        $itemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $biid,
            'name'            => $name,
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        return [$biid, $itemid];
    }

    /**
     * Creates a course module record for a playerpuzzle instance.
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
     * Tests that the base Lobby fields (welcome message, Play URL/text, sesskey) are
     * always present, and hasstats is false without any PlayerHUD item configured.
     *
     * @return void
     */
    public function test_build_page_data_base_fields(): void {
        [$cm, $instance] = $this->make_cm_and_instance();

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $this->assertSame(get_string('lobbywelcome', 'mod_playerpuzzle'), $data['welcomemsg']);
        $this->assertSame(get_string('playgame', 'mod_playerpuzzle'), $data['playtext']);
        $this->assertStringContainsString('play.php', $data['playurl']);
        $this->assertFalse($data['hasstats']);
        $this->assertArrayNotHasKey('coinstext', $data);
    }

    /**
     * Tests that only the PlayerHUD items the teacher actually configured produce a
     * stat line, and hasstats turns true once at least one does.
     *
     * @return void
     */
    public function test_build_page_data_shows_only_configured_hud_items(): void {
        $this->skip_if_no_playerhud();
        [$biid, $coinitemid] = $this->make_hud_item('Gold Coin');

        [$cm, $instance] = $this->make_cm_and_instance(['hud_coin_item' => $coinitemid]);

        \block_playerhud\local\external_items::grant($biid, $coinitemid, (int) $this->student->id, 42, 'test', false);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $this->assertTrue($data['hasstats']);
        $this->assertSame(get_string('lobby_coinbalance', 'mod_playerpuzzle', 42), $data['coinstext']);
        $this->assertArrayNotHasKey('swordtext', $data);
        $this->assertArrayNotHasKey('shieldtext', $data);
        $this->assertArrayNotHasKey('potiontext', $data);
    }

    /**
     * Tests that a configured consumable-stock item (Espada) shows the units the student
     * currently holds, using the reframed "stock" label rather than the old "level" one.
     *
     * @return void
     */
    public function test_build_page_data_shows_consumable_stock(): void {
        $this->skip_if_no_playerhud();
        [$biid, $sworditemid] = $this->make_hud_item('Sword Token');

        [$cm, $instance] = $this->make_cm_and_instance(['hud_sword_item' => $sworditemid]);

        \block_playerhud\local\external_items::grant($biid, $sworditemid, (int) $this->student->id, 3, 'test', false);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $this->assertTrue($data['hasstats']);
        $this->assertSame(get_string('lobby_swordstock', 'mod_playerpuzzle', 3), $data['swordtext']);
    }

    /**
     * Tests that Single Match mode never shows Campaign progress, even with an
     * in-progress attempt on record (currentlevel/currentphase are meaningless there).
     *
     * @return void
     */
    public function test_build_page_data_no_progress_in_single_match_mode(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_SINGLE]);

        \mod_playerpuzzle\local\engine\security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $this->assertArrayNotHasKey('progresstext', $data);
    }

    /**
     * Tests that Campaign mode shows no progress line when there is no in-progress
     * attempt for this user.
     *
     * @return void
     */
    public function test_build_page_data_no_progress_without_inprogress_attempt(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $this->assertArrayNotHasKey('progresstext', $data);
    }

    /**
     * Tests that Campaign mode shows the most recently started in-progress attempt's
     * level/phase, ignoring an older in-progress row left behind by an abandoned
     * session.
     *
     * @return void
     */
    public function test_build_page_data_shows_most_recent_inprogress_attempt(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);

        $oldtoken = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 1, ['token' => $oldtoken]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 2, ['token' => $oldtoken]);
        $DB->set_field('playerpuzzle_attempts', 'timecreated', time() - 100, ['token' => $oldtoken]);

        $newtoken = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 2, ['token' => $newtoken]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 5, ['token' => $newtoken]);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $expected = get_string('lobby_currentprogress', 'mod_playerpuzzle', (object) ['level' => 2, 'phase' => 5]);
        $this->assertSame($expected, $data['progresstext']);
    }

    /**
     * Tests that the Minimum Questions notice is absent when minquestions is 0.
     *
     * @return void
     */
    public function test_build_page_data_no_minquestions_notice_when_zero(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['minquestions' => 0]);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $this->assertArrayNotHasKey('minquestionstext', $data);
    }

    /**
     * Tests that the Minimum Questions notice appears with the configured value when
     * minquestions is positive.
     *
     * @return void
     */
    public function test_build_page_data_shows_minquestions_notice(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['minquestions' => 5]);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $expected = get_string('lobby_minquestions_notice', 'mod_playerpuzzle', 5);
        $this->assertSame($expected, $data['minquestionstext']);
    }

    /**
     * Tests that the difficulty picker is offered (three choices, Normal pre-selected)
     * when no attempt is in progress.
     *
     * @return void
     */
    public function test_build_page_data_offers_difficulty_choices(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $this->assertArrayNotHasKey('difficultylocked', $data);
        $this->assertCount(3, $data['difficultychoices']);

        $checked = array_values(array_filter($data['difficultychoices'], fn($c) => $c['checked']));
        $this->assertCount(1, $checked);
        $this->assertSame(PLAYERPUZZLE_DIFFICULTY_NORMAL, $checked[0]['value']);
    }

    /**
     * Tests that an in-progress attempt locks the difficulty: the picker is replaced by a
     * read-only label naming the run's own difficulty, and no choices are offered.
     *
     * @return void
     */
    public function test_build_page_data_locks_difficulty_while_attempt_in_progress(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);

        \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'hard'
        );

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id);

        $this->assertArrayNotHasKey('difficultychoices', $data);
        $expected = get_string(
            'lobby_difficulty_locked',
            'mod_playerpuzzle',
            get_string('difficulty_hard', 'mod_playerpuzzle')
        );
        $this->assertSame($expected, $data['difficultylocked']);
    }
}
