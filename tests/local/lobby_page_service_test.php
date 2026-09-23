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
     * Tests that the base Lobby fields (Play URL/text, sesskey) are always present, and the
     * loadout shop always appears — PuzzleCoin is PlayerPuzzle's own balance, so the shop
     * needs no PlayerHUD configuration at all. The transfer widget, however, needs one.
     *
     * @return void
     */
    public function test_build_page_data_base_fields(): void {
        [$cm, $instance] = $this->make_cm_and_instance();

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertSame(get_string('playgame', 'mod_playerpuzzle'), $data['playtext']);
        $this->assertStringContainsString('play.php', $data['playurl']);
        $this->assertSame(get_string('playdemo', 'mod_playerpuzzle'), $data['playdemotext']);
        $this->assertStringContainsString('play.php', $data['demourl']);
        $this->assertSame(get_string('lobby_puzzlecoinbalance', 'mod_playerpuzzle', 0), $data['coinstext']);
        $this->assertSame(0, $data['coinvalue']);
        $this->assertCount(5, $data['shopitems']);
        $this->assertFalse($data['hastransfer']);
        $this->assertSame(get_string('lobby_ready', 'mod_playerpuzzle'), $data['readytext']);
        $this->assertStringContainsString('player', $data['heroimageurl']);
        $this->assertStringContainsString('panel_stone.webp', $data['panelstoneurl']);
        $this->assertStringContainsString('scroll_banner.webp', $data['scrollbannerurl']);
    }

    /**
     * Tests that the narration checkbox reflects the student's own preference (never a
     * site setting), defaulting to off and flipping once the student turns it on.
     *
     * @return void
     */
    public function test_build_page_data_shows_speech_preference(): void {
        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            $context
        );
        $this->assertFalse($data['speechenabled']);
        $this->assertSame(get_string('lobby_speech_label', 'mod_playerpuzzle'), $data['speechlabel']);

        sound_preferences::set_enabled('speech', true, (int) $this->student->id);
        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            $context
        );
        $this->assertTrue($data['speechenabled']);
    }

    /**
     * Tests that the PuzzleCoin balance reflects what the student actually holds — credited
     * directly here, with no PlayerHUD involved, since PuzzleCoin is PlayerPuzzle's own.
     *
     * @return void
     */
    public function test_build_page_data_shows_puzzlecoin_balance(): void {
        [$cm, $instance] = $this->make_cm_and_instance();
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 42);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertSame(get_string('lobby_puzzlecoinbalance', 'mod_playerpuzzle', 42), $data['coinstext']);
        $this->assertSame(42, $data['coinvalue']);
    }

    /**
     * Tests that each shop item reports the student's real owned quantity (from
     * user_stock) and its fixed coin price.
     *
     * @return void
     */
    public function test_build_page_data_shows_consumable_stock(): void {
        [$cm, $instance] = $this->make_cm_and_instance();

        user_stock::credit((int) $this->student->id, (int) $instance->id, 'sword', 3);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $sworditem = null;
        foreach ($data['shopitems'] as $item) {
            if ($item['type'] === 'sword') {
                $sworditem = $item;
            }
        }
        $this->assertNotNull($sworditem);
        $this->assertSame(3, $sworditem['quantity']);
        $this->assertSame(get_string('lobby_stockowned', 'mod_playerpuzzle', 3), $sworditem['ownedtext']);
        $this->assertSame(10, $sworditem['price']);
    }

    /**
     * Tests that the transfer widget appears once hud_coin_item is configured, showing the
     * student's real PlayerHUD balance.
     *
     * @return void
     */
    public function test_build_page_data_shows_transfer_when_hud_coin_item_configured(): void {
        $this->skip_if_no_playerhud();
        [$biid, $coinitemid] = $this->make_hud_item('PlayerCoin');

        [$cm, $instance] = $this->make_cm_and_instance(['hud_coin_item' => $coinitemid]);

        \block_playerhud\local\external_items::grant($biid, $coinitemid, (int) $this->student->id, 15, 'test', false);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertTrue($data['hastransfer']);
        $this->assertSame(15, $data['hudcoinvalue']);
        $this->assertSame(get_string('lobby_hudcoinbalance', 'mod_playerpuzzle', 15), $data['hudcoinstext']);
    }

    /**
     * Tests that the transfer widget never appears without a coin item configured, even
     * when PlayerHUD itself is installed and available — there would be nothing to
     * transfer from. The shop itself is unaffected.
     *
     * @return void
     */
    public function test_build_page_data_no_transfer_without_coin_item(): void {
        $this->skip_if_no_playerhud();
        $this->make_hud_item('PlayerCoin');

        [$cm, $instance] = $this->make_cm_and_instance();

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertFalse($data['hastransfer']);
        $this->assertArrayNotHasKey('hudcoinvalue', $data);
        $this->assertCount(5, $data['shopitems']);
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

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

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

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertArrayNotHasKey('progresstext', $data);
    }

    /**
     * Tests that the "Play Demo" button is always offered, regardless of prior attempt
     * history — unlike the old one-shot tutorial checkbox, it is an on-demand action the
     * student may use as many times as they like.
     *
     * @return void
     */
    public function test_build_page_data_always_offers_play_demo(): void {
        [$cm, $instance] = $this->make_cm_and_instance();

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        \mod_playerpuzzle\local\engine\security::validate_and_consume_token(
            $token,
            (int) $instance->id,
            (int) $this->student->id,
            'lost'
        );

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertSame(get_string('playdemo', 'mod_playerpuzzle'), $data['playdemotext']);
        $this->assertStringContainsString('play.php', $data['demourl']);
    }

    /**
     * Tests that a lingering in-progress Demo attempt is never mistaken for a real one to
     * resume/report on the Lobby — no progress line, no locked difficulty, and the real
     * Play form still offers the difficulty picker as if no attempt existed.
     *
     * @return void
     */
    public function test_build_page_data_ignores_an_inprogress_demo_attempt(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);

        \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'normal',
            1,
            1,
            true
        );

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertArrayNotHasKey('progresstext', $data);
        $this->assertArrayNotHasKey('difficultycurrent', $data);
        $this->assertArrayHasKey('difficultychoices', $data);
    }

    /**
     * Tests that, after a defeat, the Lobby shows where the next attempt will actually
     * resume (see security::determine_start_level()) rather than implying a fresh Level 1
     * start when it is not one — the whole point of the fix being tested here.
     *
     * @return void
     */
    public function test_build_page_data_shows_resume_position_after_a_loss(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'maxlevels' => 10,
        ]);

        $lost = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 3, ['token' => $lost]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 7, ['token' => $lost]);
        \mod_playerpuzzle\local\engine\security::validate_and_consume_token(
            $lost,
            (int) $instance->id,
            (int) $this->student->id,
            'lost'
        );

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertSame(
            get_string('lobby_resumeafterloss', 'mod_playerpuzzle', (object) ['level' => 3, 'phase' => 7]),
            $data['progresstext']
        );
    }

    /**
     * Tests that winning the whole campaign shows no resume line — a 'won' attempt starts
     * the next one fresh at Level 1, Phase 1, same as never having played before.
     *
     * @return void
     */
    public function test_build_page_data_no_resume_position_after_winning(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);

        $won = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 10, ['token' => $won]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 10, ['token' => $won]);
        \mod_playerpuzzle\local\engine\security::validate_and_consume_token(
            $won,
            (int) $instance->id,
            (int) $this->student->id,
            'won'
        );

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

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

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

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

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

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

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

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

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertArrayNotHasKey('difficultycurrent', $data);
        $this->assertCount(3, $data['difficultychoices']);

        $checked = array_values(array_filter($data['difficultychoices'], fn($c) => $c['checked']));
        $this->assertCount(1, $checked);
        $this->assertSame(PLAYERPUZZLE_DIFFICULTY_NORMAL, $checked[0]['value']);

        // The help text is a standard Moodle help icon (rendered HTML), not a permanently
        // visible paragraph — regression check for the icon actually being wired up, and
        // that its popover carries the real help string, not an empty/missing one. The
        // "popover" marker (not a "help-icon" CSS class, absent from this component's markup
        // on Moodle 4.5/5.0) is the part of core/help_icon's output stable across every
        // Moodle version this plugin supports.
        $this->assertStringContainsString('popover', $data['difficultyhelpicon']);
        $this->assertStringContainsString(
            s(get_string('lobby_difficulty_help', 'mod_playerpuzzle')),
            $data['difficultyhelpicon']
        );
    }

    /**
     * Tests that with an attempt in progress the Lobby shows a read-only line naming the
     * attempt's current difficulty (no picker) — the next choice is made on the
     * phase-complete screen, not here.
     *
     * @return void
     */
    public function test_build_page_data_shows_current_difficulty_while_attempt_in_progress(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);

        \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'hard'
        );

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertArrayNotHasKey('difficultychoices', $data);
        $expected = get_string(
            'lobby_difficulty_current',
            'mod_playerpuzzle',
            get_string('difficulty_hard', 'mod_playerpuzzle')
        );
        $this->assertSame($expected, $data['difficultycurrent']);
    }

    /**
     * Tests that an attempt somehow carrying an unrecognised difficulty value (never
     * written by the app itself, but not impossible on legacy/corrupted data) falls back
     * to Normal instead of indexing the options array with a missing key.
     *
     * @return void
     */
    public function test_build_page_data_falls_back_to_normal_for_invalid_stored_difficulty(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_CAMPAIGN]);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'difficulty', 'garbage', ['token' => $token]);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $expected = get_string(
            'lobby_difficulty_current',
            'mod_playerpuzzle',
            get_string('difficulty_normal', 'mod_playerpuzzle')
        );
        $this->assertSame($expected, $data['difficultycurrent']);
    }

    /**
     * Tests that an iOS/webkit-Android user agent adds mobile=1 to the Play URL's query
     * string, restored to the CLI-runner default afterwards so it never leaks into other
     * tests.
     *
     * @return void
     */
    public function test_build_page_data_adds_mobile_flag_for_ios_useragent(): void {
        [$cm, $instance] = $this->make_cm_and_instance();

        \core_useragent::instance(
            true,
            'Mozilla/5.0 (iPhone; CPU iPhone OS 10_10 like Mac OS X) AppleWebKit/600.1.4 '
                . '(KHTML, like Gecko) Version/8.0 Mobile/12B411 Safari/600.1.4'
        );
        try {
            $data = lobby_page_service::build_page_data(
                $cm,
                $this->course,
                $instance,
                (int) $this->student->id,
                \context_module::instance($cm->id)
            );
        } finally {
            \core_useragent::instance(true);
        }

        $this->assertStringContainsString('mobile=1', $data['playurl']);
    }

    /**
     * Tests that the ranking panel is built with the page when the teacher has it on: the
     * student's own row among the rows, and the note for the activity's game mode.
     *
     * @return void
     */
    public function test_build_page_data_includes_the_ranking_when_on(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['maxlevels' => 1]);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id,
            'userid' => $this->student->id,
            'token' => bin2hex(random_bytes(32)),
            'currentphase' => 3,
            'status' => 'inprogress',
            'timecreated' => time(),
        ]);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $DB->get_record('playerpuzzle', ['id' => $instance->id], '*', MUST_EXIST),
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertTrue($data['showranking']);
        $this->assertSame(get_string('ranking_note_campaign', 'mod_playerpuzzle'), $data['rankingnote']);
        $this->assertCount(1, $data['rankingrows']);
        $this->assertTrue($data['rankingrows'][0]['iscurrentuser']);
        $this->assertSame('20', $data['rankingrows'][0]['points']);
    }

    /**
     * Tests that turning the ranking off leaves it out of the page entirely.
     *
     * @return void
     */
    public function test_build_page_data_leaves_the_ranking_out_when_off(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['show_ranking' => 0]);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $DB->get_record('playerpuzzle', ['id' => $instance->id], '*', MUST_EXIST),
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertArrayNotHasKey('showranking', $data);
        $this->assertArrayNotHasKey('rankingrows', $data);
    }
}
