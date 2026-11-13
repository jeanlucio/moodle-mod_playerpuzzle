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
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
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
        $this->assertStringContainsString('hero.php', $data['demourl']);
        $this->assertSame(get_string('lobby_puzzlecoinbalance', 'mod_playerpuzzle', 0), $data['coinstext']);
        $this->assertSame(0, $data['coinvalue']);
        $this->assertCount(5, $data['shopitems']);
        $this->assertFalse($data['hastransfer']);
        $this->assertSame(get_string('lobby_ready', 'mod_playerpuzzle'), $data['readytext']);
        $this->assertStringContainsString('/mod/playerpuzzle/pix/heroes/warriorf1.webp', $data['heroimageurl']);
        $this->assertStringContainsString('lobby_frame.webp', $data['frameurl']);
        $this->assertStringContainsString('lobby_texture.webp', $data['textureurl']);
        $this->assertStringContainsString('scroll_banner.webp', $data['scrollbannerurl']);
        $this->assertSame(get_string('lobby_menu_shop', 'mod_playerpuzzle'), $data['shopmenulabel']);
        $this->assertSame(get_string('lobby_menu_settings', 'mod_playerpuzzle'), $data['settingsmenulabel']);
        $this->assertSame(get_string('lobby_back', 'mod_playerpuzzle'), $data['backlabel']);
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
     * Tests that Music and Sound Effects reflect the student's own preference too, mirroring
     * the same in-game toggles already offered inside the Phaser HUD — both default on.
     *
     * @return void
     */
    public function test_build_page_data_shows_music_and_sfx_preferences(): void {
        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            $context
        );
        // A student who never chose has the music off and the effects on.
        $this->assertFalse($data['musicenabled']);
        $this->assertSame(get_string('lobby_music_label', 'mod_playerpuzzle'), $data['musiclabel']);
        $this->assertTrue($data['sfxenabled']);
        $this->assertSame(get_string('lobby_sfx_label', 'mod_playerpuzzle'), $data['sfxlabel']);

        sound_preferences::set_enabled('music', true, (int) $this->student->id);
        sound_preferences::set_enabled('sfx', false, (int) $this->student->id);
        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            $context
        );
        $this->assertTrue($data['musicenabled']);
        $this->assertFalse($data['sfxenabled']);
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
     * Tests that the Hint entry is left out of the shop entirely when hints_enabled is
     * off, so a student is never offered stock they can never spend.
     *
     * @return void
     */
    public function test_build_page_data_excludes_hint_when_hints_disabled(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['hints_enabled' => 0]);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertCount(4, $data['shopitems']);
        foreach ($data['shopitems'] as $item) {
            $this->assertNotSame('hint', $item['type']);
        }
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
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE]);

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
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

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
        $this->assertStringContainsString('hero.php', $data['demourl']);
    }

    /**
     * Tests that a lingering in-progress Demo attempt is never mistaken for a real one to
     * resume/report on the Lobby — no progress line, no locked difficulty, and the real
     * Play form still offers the difficulty picker as if no attempt existed.
     *
     * @return void
     */
    public function test_build_page_data_ignores_an_inprogress_demo_attempt(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

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
        $this->assertArrayNotHasKey('difficultylockednote', $data);
        $this->assertArrayHasKey('difficultychoicesjson', $data);
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
            'gamemode' => constants::GAMEMODE_CAMPAIGN,
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
     * Ends the student's Campaign with a win.
     *
     * @param \stdClass $instance Activity instance.
     * @return void
     */
    private function win_campaign(\stdClass $instance): void {
        global $DB;

        $won = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 10, ['token' => $won]);
        \mod_playerpuzzle\local\engine\security::validate_and_consume_token(
            $won,
            (int) $instance->id,
            (int) $this->student->id,
            'won'
        );
    }

    /**
     * Tests that once a Campaign is won the Lobby says so and turns Play into "Start a new Campaign",
     * warning that the PuzzleCoin and consumables go back to zero and sending the confirmation play.php
     * requires.
     *
     * @return void
     */
    public function test_build_page_data_offers_a_new_campaign_after_winning(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'minquestions' => 0]);
        $this->win_campaign($instance);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertSame(get_string('lobby_campaign_won', 'mod_playerpuzzle', 1), $data['progresstext']);
        $this->assertTrue($data['newcampaign']);
        $this->assertSame(get_string('lobby_newcampaign_button', 'mod_playerpuzzle'), $data['playtext']);
        $this->assertSame(get_string('lobby_newcampaign_notice', 'mod_playerpuzzle'), $data['newcampaigntext']);
        $this->assertArrayNotHasKey('campaignlimittext', $data);

        $html = $this->render_lobby($data);
        $this->assertStringContainsString('name="confirmnewcampaign" value="1"', $html);
        $this->assertStringContainsString('data-confirm=', $html);
    }

    /**
     * Tests that with every Campaign played the Lobby says so and offers no Play button.
     *
     * @return void
     */
    public function test_build_page_data_hides_play_when_no_campaign_is_left(): void {
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode' => constants::GAMEMODE_CAMPAIGN,
            'maxcampaigns' => 1,
            'minquestions' => 0,
        ]);
        $this->win_campaign($instance);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertSame(get_string('maxcampaignsreached', 'mod_playerpuzzle'), $data['campaignlimittext']);
        $this->assertArrayNotHasKey('newcampaign', $data);
        $this->assertStringNotContainsString('id="pp-lobby-playform"', $this->render_lobby($data));
    }

    /**
     * Tests that a Campaign after the first is named in the Lobby, so the student knows which one
     * they are playing.
     *
     * @return void
     */
    public function test_build_page_data_names_a_later_campaign(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);
        $this->win_campaign($instance);
        \mod_playerpuzzle\local\engine\security::resume_or_create_attempt_token((int) $instance->id, (int) $this->student->id);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertSame(get_string('lobby_campaignname', 'mod_playerpuzzle', 2), $data['campaignnametext']);
        $this->assertSame(get_string('playgame', 'mod_playerpuzzle'), $data['playtext']);
    }

    /**
     * Tests where the Play and Demo buttons go: a Single Match with nothing to resume and the Demo choose
     * their hero first, on hero.php, naming their mode; a Single Match with an attempt to resume goes
     * straight on to play.php.
     *
     * @return void
     */
    public function test_play_goes_through_the_hero_screen_when_a_hero_is_to_be_chosen(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'minquestions' => 0]);
        $context = \context_module::instance($cm->id);
        $userid = (int) $this->student->id;

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, $userid, $context);
        $html = $this->render_lobby($data);
        $this->assertSame('single', $data['playmode']);
        $this->assertMatchesRegularExpression('~<form[^>]*action="[^"]*hero\.php[^"]*"[^>]*id="pp-lobby-playform"~', $html);
        $this->assertStringContainsString('name="mode" value="single"', $html);
        $this->assertStringContainsString('name="mode" value="demo"', $html);

        \mod_playerpuzzle\local\engine\security::generate_attempt_token((int) $instance->id, $userid);
        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, $userid, $context);
        $html = $this->render_lobby($data);
        $this->assertArrayNotHasKey('playmode', $data);
        $this->assertMatchesRegularExpression('~<form[^>]*action="[^"]*play\.php[^"]*"[^>]*id="pp-lobby-playform"~', $html);
    }

    /**
     * Tests that a Campaign student's hero stands in the Lobby at its current stage, and that Play goes
     * straight on to play.php.
     *
     * @return void
     */
    public function test_the_lobby_shows_the_campaign_hero(): void {
        global $DB;

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'minquestions' => 0]);
        $userid = (int) $this->student->id;
        $row = \mod_playerpuzzle\local\game_page_service::choose_campaign_hero($instance, $userid, 'archerf');
        $DB->set_field('playerpuzzle_heroes', 'stage', 2, ['id' => $row->id]);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            $userid,
            \context_module::instance($cm->id)
        );

        $this->assertStringContainsString('/mod/playerpuzzle/pix/heroes/archerf2.webp', $data['heroimageurl']);
        $this->assertArrayNotHasKey('playmode', $data);
        $this->assertStringContainsString('play.php', $data['playurl']);
    }

    /**
     * Tests that starting a new Campaign goes through the hero screen, carrying the confirmation, in the
     * Campaign mode.
     *
     * @return void
     */
    public function test_a_new_campaign_goes_through_the_hero_screen(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'minquestions' => 0]);
        \mod_playerpuzzle\local\game_page_service::choose_campaign_hero($instance, (int) $this->student->id, 'magem');
        $this->win_campaign($instance);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );
        $html = $this->render_lobby($data);

        $this->assertSame('campaign', $data['playmode']);
        $this->assertMatchesRegularExpression('~<form[^>]*action="[^"]*hero\.php[^"]*"[^>]*id="pp-lobby-playform"~', $html);
        $this->assertStringContainsString('name="mode" value="campaign"', $html);
        $this->assertStringContainsString('name="confirmnewcampaign" value="1"', $html);
    }

    /**
     * Tests that once the hero of the next Campaign is chosen the Lobby no longer offers to start it:
     * that Campaign is under way, named, with a plain Play button.
     *
     * @return void
     */
    public function test_a_chosen_hero_starts_the_next_campaign(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'minquestions' => 0]);
        $userid = (int) $this->student->id;
        \mod_playerpuzzle\local\game_page_service::choose_campaign_hero($instance, $userid, 'magem');
        $this->win_campaign($instance);
        \mod_playerpuzzle\local\game_page_service::choose_campaign_hero($instance, $userid, 'archerm');

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            $userid,
            \context_module::instance($cm->id)
        );

        $this->assertArrayNotHasKey('newcampaign', $data);
        $this->assertSame(get_string('lobby_campaignname', 'mod_playerpuzzle', 2), $data['campaignnametext']);
        $this->assertSame(get_string('playgame', 'mod_playerpuzzle'), $data['playtext']);
        $this->assertArrayNotHasKey('playmode', $data);
        $this->assertStringContainsString('/pix/heroes/archerm1.webp', $data['heroimageurl']);
    }

    /**
     * Tests that between Campaigns the Lobby keeps showing the hero of the one that ended, not the picture the
     * game had before heroes.
     *
     * @return void
     */
    public function test_the_lobby_keeps_the_last_hero_between_campaigns(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'minquestions' => 0]);
        $userid = (int) $this->student->id;
        \mod_playerpuzzle\local\game_page_service::choose_campaign_hero($instance, $userid, 'magem');
        $this->win_campaign($instance);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            $userid,
            \context_module::instance($cm->id)
        );

        $this->assertArrayHasKey('newcampaign', $data);
        $this->assertStringContainsString('/pix/heroes/magem1.webp', $data['heroimageurl']);
    }

    /**
     * Tests that a Campaign that has ended hides the PuzzleCoin balance and the shop, whether or not the activity
     * allows another Campaign, since starting the next one empties both; a Campaign under way keeps them.
     *
     * @return void
     */
    public function test_an_ended_campaign_hides_the_balance_and_the_shop(): void {
        [$cm, $instance] = $this->make_cm_and_instance([
            'gamemode' => constants::GAMEMODE_CAMPAIGN,
            'minquestions' => 0,
            'maxcampaigns' => 1,
        ]);
        $userid = (int) $this->student->id;
        $context = \context_module::instance($cm->id);
        \mod_playerpuzzle\local\game_page_service::choose_campaign_hero($instance, $userid, 'magem');

        $playing = $this->render_lobby(lobby_page_service::build_page_data($cm, $this->course, $instance, $userid, $context));
        $this->assertStringContainsString('data-role="coinvalue"', $playing);
        $this->assertStringContainsString('id="pp-lobby-shop"', $playing);

        $this->win_campaign($instance);
        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, $userid, $context);
        $this->assertArrayHasKey('campaignlimittext', $data, 'the only Campaign allowed has ended');
        $this->assertTrue($data['campaignended']);
        $capped = $this->render_lobby($data);
        $this->assertStringNotContainsString('data-role="coinvalue"', $capped);
        $this->assertStringNotContainsString('id="pp-lobby-shop"', $capped);
        $this->assertStringNotContainsString('data-panel="pp-lobby-shop"', $capped);

        $instance->maxcampaigns = 0;
        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, $userid, $context);
        $this->assertArrayHasKey('newcampaign', $data);
        $this->assertTrue($data['campaignended']);
        $open = $this->render_lobby($data);
        $this->assertStringNotContainsString('data-role="coinvalue"', $open);
        $this->assertStringNotContainsString('id="pp-lobby-shop"', $open);
    }

    /**
     * Tests that each item of the shop says how many times it can be used: up to the fixed limit per phase in a
     * Campaign and per match in a Single Match, and no fixed limit for the hint.
     *
     * @return void
     */
    public function test_the_shop_items_say_their_use_limit(): void {
        $userid = (int) $this->student->id;
        $modes = [
            constants::GAMEMODE_CAMPAIGN => 'shop_limit_perphase',
            constants::GAMEMODE_SINGLE => 'shop_limit_permatch',
        ];
        foreach ($modes as $mode => $key) {
            [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => $mode, 'minquestions' => 0]);
            $data = lobby_page_service::build_page_data(
                $cm,
                $this->course,
                $instance,
                $userid,
                \context_module::instance($cm->id)
            );
            $limits = array_column($data['shopitems'], 'limittext', 'type');

            $this->assertSame(get_string($key, 'mod_playerpuzzle', 3), $limits['potion']);
            $this->assertSame(get_string($key, 'mod_playerpuzzle', 3), $limits['sword']);
            $this->assertSame(get_string($key, 'mod_playerpuzzle', 1), $limits['shield']);
            $this->assertSame(get_string($key, 'mod_playerpuzzle', 1), $limits['magic']);
            $this->assertSame(get_string('shop_limit_none', 'mod_playerpuzzle'), $limits['hint']);
        }
    }

    /**
     * Tests that a Lobby with no Campaign hero shows the last hero the student chose for a match, and the first
     * hero of the catalog when they have chosen none.
     *
     * @return void
     */
    public function test_the_lobby_stands_in_the_last_match_hero(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'minquestions' => 0]);
        $userid = (int) $this->student->id;
        $context = \context_module::instance($cm->id);

        $none = lobby_page_service::build_page_data($cm, $this->course, $instance, $userid, $context);
        $this->assertStringContainsString('/pix/heroes/warriorf1.webp', $none['heroimageurl']);

        last_hero::set($userid, 'magem');
        $chosen = lobby_page_service::build_page_data($cm, $this->course, $instance, $userid, $context);
        $this->assertStringContainsString('/pix/heroes/magem1.webp', $chosen['heroimageurl']);
    }

    /**
     * Renders the Lobby template with page data.
     *
     * @param array $data lobby_page_service::build_page_data() result.
     * @return string
     */
    private function render_lobby(array $data): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('mod_playerpuzzle/view_lobby', $data);
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

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

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
     * Tests that the difficulty button is offered (three difficulties, Normal first) when no
     * attempt is in progress.
     *
     * @return void
     */
    public function test_build_page_data_offers_difficulty_choices(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertArrayNotHasKey('difficultylockednote', $data);
        $this->assertArrayNotHasKey('difficultylockedtext', $data);

        // What the button steps through: each difficulty with the text the button then shows and what it changes.
        $choices = json_decode($data['difficultychoicesjson'], true);
        $this->assertSame(['easy', 'normal', 'hard'], array_column($choices, 'value'));
        foreach ($choices as $choice) {
            $label = get_string('difficulty_' . $choice['value'], 'mod_playerpuzzle');
            $this->assertSame(get_string('lobby_difficulty_selected', 'mod_playerpuzzle', $label), $choice['text']);
            $this->assertSame(
                get_string('lobby_difficulty_desc_' . $choice['value'], 'mod_playerpuzzle'),
                $choice['description']
            );
        }

        // It starts on Normal, which is also the value that goes with the Play form.
        $this->assertSame(constants::DIFFICULTY_NORMAL, $data['difficultyvalue']);
        $this->assertSame(get_string('lobby_difficulty_desc_normal', 'mod_playerpuzzle'), $data['difficultydescription']);
        $this->assertSame(get_string('lobby_difficulty_help', 'mod_playerpuzzle'), $data['difficultyhelp']);
    }

    /**
     * Tests that the button's first text is the pre-selected difficulty — Normal, until the
     * student steps it client-side.
     *
     * @return void
     */
    public function test_build_page_data_offers_difficulty_readout_on_the_button(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $expected = get_string(
            'lobby_difficulty_selected',
            'mod_playerpuzzle',
            get_string('difficulty_normal', 'mod_playerpuzzle')
        );
        $this->assertSame($expected, $data['difficultyselectedtext']);
    }

    /**
     * Tests the Lobby's markup for the difficulty and the balance: the difficulty is a button that steps through
     * them with its value in a hidden field of the Play form, not radios, and the balance sits inside the Shop
     * button instead of a chip of its own.
     *
     * @return void
     */
    public function test_the_lobby_renders_the_difficulty_button_and_the_balance_in_the_shop_button(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

        $html = $this->render_lobby(lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        ));

        $this->assertStringContainsString('data-action="cycle-difficulty"', $html);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="hidden"[^>]*name="difficulty"[^>]*form="pp-lobby-playform"[^>]*value="normal"/s',
            $html
        );
        $this->assertStringNotContainsString('type="radio"', $html);

        // The balance is a child of the button that opens the Shop, in the main view.
        $shopbutton = substr($html, strpos($html, 'data-panel="pp-lobby-shop"'));
        $shopbutton = substr($shopbutton, 0, strpos($shopbutton, '</button>'));
        $this->assertStringContainsString('data-role="coinvalue"', $shopbutton);
    }

    /**
     * Tests that a Campaign student opens the history from the Campaign chip, and that the panel lists the Campaigns.
     *
     * @return void
     */
    public function test_the_campaign_chip_opens_the_history_panel(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);
        $context = \context_module::instance($cm->id);
        \mod_playerpuzzle\local\engine\security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'normal');

        $html = $this->render_lobby(lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            $context
        ));

        // The first Campaign has no name, so the button that opens the history carries the history's own.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*data-panel="pp-lobby-history"[^>]*>\s*<i class="fa fa-flag"[^>]*><\/i>\s*'
                . '<span>' . preg_quote(get_string('history_title', 'mod_playerpuzzle'), '/') . '<\/span>/s',
            $html
        );
        $this->assertStringContainsString('id="pp-lobby-history"', $html);
        $this->assertStringContainsString('class="pp-history-item pp-history-current"', $html);

        // From the second Campaign on, the button is the Campaign's name.
        $this->finish_campaign_one_and_start_two($instance);
        $html = $this->render_lobby(lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            $context
        ));
        $this->assertMatchesRegularExpression(
            '/<button[^>]*data-panel="pp-lobby-history"[^>]*>\s*<i class="fa fa-flag"[^>]*><\/i>\s*'
                . '<span>' . preg_quote(get_string('lobby_campaignname', 'mod_playerpuzzle', 2), '/') . '<\/span>/s',
            $html
        );
    }

    /**
     * Ends the student's first Campaign with a win and puts an attempt of the second under way.
     *
     * @param \stdClass $instance Activity instance.
     * @return void
     */
    private function finish_campaign_one_and_start_two(\stdClass $instance): void {
        global $DB;

        $DB->set_field(
            'playerpuzzle_attempts',
            'status',
            'won',
            ['playerpuzzleid' => $instance->id, 'userid' => $this->student->id]
        );
        $DB->set_field(
            'playerpuzzle_attempts',
            'currentlevel',
            (int) $instance->maxlevels,
            ['playerpuzzleid' => $instance->id, 'userid' => $this->student->id]
        );
        $DB->set_field(
            'playerpuzzle_attempts',
            'currentphase',
            10,
            ['playerpuzzleid' => $instance->id, 'userid' => $this->student->id]
        );
        $attempt = $DB->get_record('playerpuzzle_attempts', ['playerpuzzleid' => $instance->id, 'userid' => $this->student->id]);
        unset($attempt->id);
        $attempt->token = bin2hex(random_bytes(32));
        $attempt->status = 'inprogress';
        $attempt->campaign = 2;
        $attempt->currentlevel = 1;
        $attempt->currentphase = 3;
        $DB->insert_record('playerpuzzle_attempts', $attempt);
    }

    /**
     * Tests that a Single Match and a visitor get no history: there is no Campaign to list.
     *
     * @return void
     */
    public function test_a_single_match_and_a_visitor_have_no_history(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_SINGLE, 'minquestions' => 0]);
        $context = \context_module::instance($cm->id);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id, $context);
        $this->assertArrayNotHasKey('canviewhistory', $data);
        $this->assertStringNotContainsString('pp-lobby-history', $this->render_lobby($data));

        [$campaigncm, $campaign] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);
        $guest = guest_user();
        $data = lobby_page_service::build_page_data(
            $campaigncm,
            $this->course,
            $campaign,
            (int) $guest->id,
            \context_module::instance($campaigncm->id)
        );
        $this->assertArrayNotHasKey('canviewhistory', $data);
    }

    /**
     * Tests that once the phase of an attempt in progress has started, the difficulty shows as a locked label, not a
     * button.
     *
     * @return void
     */
    public function test_the_lobby_locks_the_difficulty_once_the_phase_has_started(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);
        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'hard'
        );
        $this->mark_phase_started($token);

        $html = $this->render_lobby(lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        ));

        $this->assertStringNotContainsString('data-action="cycle-difficulty"', $html);
        $this->assertStringContainsString('fa-lock', $html);
        $this->assertStringContainsString(
            s(get_string('lobby_difficulty_selected', 'mod_playerpuzzle', get_string('difficulty_hard', 'mod_playerpuzzle'))),
            $html
        );
    }

    /**
     * Tests that the page of a phase nothing has happened in yet carries the difficulty button and the field that
     * marks the value as a choice: without the mark the server keeps the difficulty the phase was entered at.
     *
     * @return void
     */
    public function test_the_lobby_renders_the_difficulty_button_and_the_choice_mark_for_an_untouched_phase(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);
        \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'easy'
        );

        $html = $this->render_lobby(lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        ));

        $this->assertStringContainsString('data-action="cycle-difficulty"', $html);
        $this->assertStringContainsString('name="difficultychoice"', $html);
        $this->assertStringNotContainsString('fa-lock', $html);
        $this->assertStringContainsString(s(get_string('lobby_difficulty_open', 'mod_playerpuzzle')), $html);
    }

    /**
     * Records a move in the attempt behind a token, so its phase counts as started (a fresh attempt is untouched, and
     * the Lobby still lets the student choose the difficulty of an untouched phase).
     *
     * @param string $token The attempt's token.
     * @return void
     */
    private function mark_phase_started(string $token): void {
        global $DB;

        $DB->set_field('playerpuzzle_attempts', 'moveseq', 1, ['token' => $token]);
        $DB->set_field(
            'playerpuzzle_attempts',
            'movelog',
            json_encode([['type' => 'move', 'r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 1]]),
            ['token' => $token]
        );
    }

    /**
     * Tests that an attempt whose phase nothing has happened in yet (it sits between phases, or at the start of its
     * first) still gets the difficulty choice, with the attempt's own difficulty marked and a line saying why.
     *
     * @return void
     */
    public function test_build_page_data_keeps_the_difficulty_choice_open_for_an_untouched_phase(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

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

        $this->assertArrayNotHasKey('difficultylockedtext', $data);
        $this->assertArrayNotHasKey('difficultylockednote', $data);
        $this->assertArrayHasKey('difficultychoicesjson', $data);
        $this->assertSame('hard', $data['difficultyvalue']);
        $this->assertSame(
            get_string('lobby_difficulty_selected', 'mod_playerpuzzle', get_string('difficulty_hard', 'mod_playerpuzzle')),
            $data['difficultyselectedtext']
        );
        $this->assertSame(get_string('lobby_difficulty_desc_hard', 'mod_playerpuzzle'), $data['difficultydescription']);
        $this->assertSame(get_string('lobby_difficulty_open', 'mod_playerpuzzle'), $data['difficultyopennote']);
    }

    /**
     * Tests that with an attempt in progress the Lobby shows a read-only line naming the
     * attempt's current difficulty (no picker) — the next choice is made on the
     * phase-complete screen, not here.
     *
     * @return void
     */
    public function test_build_page_data_shows_current_difficulty_while_attempt_in_progress(): void {
        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id,
            'hard'
        );
        $this->mark_phase_started($token);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertArrayNotHasKey('difficultychoicesjson', $data);
        $this->assertArrayNotHasKey('difficultyselectedtext', $data);
        // The note gives the rule only: the locked button already says which difficulty it is.
        $this->assertSame(get_string('lobby_difficulty_locked_note', 'mod_playerpuzzle'), $data['difficultylockednote']);
        $this->assertStringNotContainsString(
            get_string('difficulty_hard', 'mod_playerpuzzle'),
            $data['difficultylockednote']
        );
        // The button is there but locked, and says the same.
        $this->assertSame(
            get_string('lobby_difficulty_selected', 'mod_playerpuzzle', get_string('difficulty_hard', 'mod_playerpuzzle')),
            $data['difficultylockedtext']
        );
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

        [$cm, $instance] = $this->make_cm_and_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN]);

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $instance->id,
            (int) $this->student->id
        );
        $DB->set_field('playerpuzzle_attempts', 'difficulty', 'garbage', ['token' => $token]);
        $this->mark_phase_started($token);

        $data = lobby_page_service::build_page_data(
            $cm,
            $this->course,
            $instance,
            (int) $this->student->id,
            \context_module::instance($cm->id)
        );

        $this->assertSame(get_string('lobby_difficulty_locked_note', 'mod_playerpuzzle'), $data['difficultylockednote']);
        $this->assertSame(
            get_string('lobby_difficulty_selected', 'mod_playerpuzzle', get_string('difficulty_normal', 'mod_playerpuzzle')),
            $data['difficultylockedtext']
        );
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

    /**
     * Tests that the guest account gets the Demo-only Lobby flag and its note, and no ranking
     * (it would name the class's students to any visitor), while a student gets neither
     * restriction.
     *
     * @return void
     */
    public function test_build_page_data_flags_the_guest_account(): void {
        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);

        $student = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id, $context);
        $guest = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) guest_user()->id, $context);

        $this->assertArrayNotHasKey('isguest', $student);
        $this->assertTrue($student['showranking']);
        $this->assertTrue($guest['isguest']);
        $this->assertArrayNotHasKey('showranking', $guest);
        $this->assertSame(get_string('guestdemoonly', 'mod_playerpuzzle'), $guest['guestdemoonlytext']);
    }

    /**
     * Regression test: a logged-in user who is in the course only through guest access is a
     * visitor, even though isguestuser() is false for them. The ranking names the class's
     * students, which core keeps from them, so it must not be built for them.
     *
     * @return void
     */
    public function test_build_page_data_treats_a_logged_in_visitor_like_the_guest_account(): void {
        [$cm, $instance] = $this->make_cm_and_instance();
        $context = \context_module::instance($cm->id);
        $visitor = $this->getDataGenerator()->create_user();

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $visitor->id, $context);

        $this->assertTrue($data['isguest']);
        $this->assertArrayNotHasKey('showranking', $data);
        $this->assertArrayNotHasKey('rankingrows', $data);
    }

    /**
     * Tests that the Lobby offers no match, and says why, when the activity requires questions and has
     * none approved; and that it offers it again once a question is approved.
     *
     * @return void
     */
    public function test_the_lobby_offers_no_match_without_questions(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id, 'minquestions' => 3]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $context = \context_module::instance($cm->id);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id, $context);
        $this->assertTrue($data['noquestions']);
        $this->assertSame(get_string('lobby_noquestions_notice', 'mod_playerpuzzle'), $data['noquestionstext']);

        questions_repository::add_question(
            (int) $instance->id,
            'multichoice',
            'Q?',
            '',
            [['text' => 'A', 'iscorrect' => true], ['text' => 'B', 'iscorrect' => false]],
            2
        );
        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id, $context);
        $this->assertArrayNotHasKey('noquestions', $data);
    }

    /**
     * Tests that whoever manages the questions is told to add some, not to ask the teacher.
     *
     * @return void
     */
    public function test_the_lobby_tells_a_teacher_to_add_questions(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id, 'minquestions' => 3]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $context = \context_module::instance($cm->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $teacher->id, $context);

        $this->assertTrue($data['noquestions']);
        $this->assertSame(get_string('lobby_noquestions_teacher', 'mod_playerpuzzle'), $data['noquestionstext']);
        $this->assertTrue($data['canmanagequestions']);
    }

    /**
     * Tests that the teacher is told how many AI-generated questions wait for approval, and that a
     * student still gets the plain notice while they wait.
     *
     * @return void
     */
    public function test_the_lobby_tells_a_teacher_how_many_ai_questions_wait_for_approval(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id, 'minquestions' => 3]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $context = \context_module::instance($cm->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $answers = [['text' => 'A', 'iscorrect' => true], ['text' => 'B', 'iscorrect' => false]];
        foreach (['ai', 'ai'] as $source) {
            questions_repository::add_question((int) $instance->id, 'multichoice', 'Q?', '', $answers, 2, $source, false);
        }
        // Neither a disabled bank question nor an unapproved manual one is in the AI review queue.
        questions_repository::add_question((int) $instance->id, 'multichoice', 'Q?', '', $answers, 2, 'bank', false);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $teacher->id, $context);
        $this->assertSame(get_string('lobby_noquestions_teacherpending', 'mod_playerpuzzle', 2), $data['noquestionstext']);

        $data = lobby_page_service::build_page_data($cm, $this->course, $instance, (int) $this->student->id, $context);
        $this->assertSame(get_string('lobby_noquestions_notice', 'mod_playerpuzzle'), $data['noquestionstext']);
    }
}
