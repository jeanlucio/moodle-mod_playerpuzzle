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
 * Tests for the hero choice screen.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for hero_page_service.
 *
 * @covers \mod_playerpuzzle\local\hero_page_service
 */
final class hero_page_service_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Creates an activity.
     *
     * @param string $gamemode constants::GAMEMODE_*.
     * @return \stdClass[] The course module and the instance.
     */
    private function make(string $gamemode): array {
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $this->course->id, 'gamemode' => $gamemode, 'minquestions' => 0]);

        return [get_coursemodule_from_instance('playerpuzzle', $instance->id), $instance];
    }

    /**
     * Tests which modes a student may choose a hero in: the Campaign's only while it still needs one, the
     * Single Match's only in a Single Match activity, and the Demo's anywhere.
     *
     * @return void
     */
    public function test_which_modes_are_available(): void {
        [, $campaign] = $this->make(constants::GAMEMODE_CAMPAIGN);
        [, $single] = $this->make(constants::GAMEMODE_SINGLE);
        $userid = (int) $this->student->id;

        $this->assertTrue(hero_page_service::is_available($campaign, $userid, hero_page_service::MODE_CAMPAIGN));
        $this->assertFalse(hero_page_service::is_available($campaign, $userid, hero_page_service::MODE_SINGLE));
        $this->assertTrue(hero_page_service::is_available($campaign, $userid, hero_page_service::MODE_DEMO));
        $this->assertFalse(hero_page_service::is_available($single, $userid, hero_page_service::MODE_CAMPAIGN));
        $this->assertTrue(hero_page_service::is_available($single, $userid, hero_page_service::MODE_SINGLE));
        $this->assertFalse(hero_page_service::is_available($campaign, $userid, 'unknown'));

        game_page_service::choose_campaign_hero($campaign, $userid, 'magef');
        $this->assertFalse(hero_page_service::is_available($campaign, $userid, hero_page_service::MODE_CAMPAIGN));
    }

    /**
     * Tests the Campaign's screen: the six heroes at their first look with none marked, a form that saves
     * the choice on hero.php, and the warning of a new Campaign only when one is being started.
     *
     * @return void
     */
    public function test_the_campaign_screen(): void {
        global $DB, $OUTPUT;

        [$cm, $instance] = $this->make(constants::GAMEMODE_CAMPAIGN);
        $userid = (int) $this->student->id;

        $data = hero_page_service::build_page_data($cm, $instance, $userid, hero_page_service::MODE_CAMPAIGN, [], '');

        $this->assertSame(array_keys(heroes::CATALOG), array_column($data['heroes'], 'key'));
        $this->assertSame([false], array_unique(array_column($data['heroes'], 'checked')));
        $this->assertStringContainsString('/pix/heroes/archerf1.webp', $data['heroes'][5]['imageurl']);
        $this->assertSame(get_string('hero_name_warriorf', 'mod_playerpuzzle'), $data['heroes'][0]['name']);
        $this->assertSame(
            get_string('hero_specialty', 'mod_playerpuzzle', get_string('hero_attribute_attack', 'mod_playerpuzzle')),
            $data['heroes'][0]['specialty']
        );
        $this->assertStringContainsString('/mod/playerpuzzle/hero.php', $data['formurl']);
        $this->assertSame(get_string('hero_intro_campaign', 'mod_playerpuzzle'), $data['intro']);
        $this->assertSame(get_string('hero_choose_button', 'mod_playerpuzzle'), $data['submitlabel']);
        $this->assertSame('', $data['error']);

        // The screen wears the Lobby's own art.
        $this->assertStringContainsString('/pix/lobby_frame.webp', $data['frameurl']);
        $this->assertStringContainsString('/pix/lobby_texture.webp', $data['textureurl']);
        $this->assertStringContainsString('/pix/scroll_banner.webp', $data['scrollbannerurl']);

        // The first Campaign sends the student here until they have a hero, so there is no Lobby to go back to.
        $this->assertSame('', $data['backurl']);
        $this->assertStringNotContainsString(
            get_string('hero_back', 'mod_playerpuzzle'),
            $OUTPUT->render_from_template('mod_playerpuzzle/hero_select', $data)
        );

        // A Campaign that has ended: the next one empties the stock, and the screen says so.
        game_page_service::choose_campaign_hero($instance, $userid, 'magef');
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id, 'userid' => $userid, 'token' => bin2hex(random_bytes(32)),
            'status' => 'won', 'campaign' => 1, 'timecreated' => time(), 'timefinished' => time(),
        ]);
        $new = hero_page_service::build_page_data($cm, $instance, $userid, hero_page_service::MODE_CAMPAIGN, [], '');
        $this->assertSame(get_string('hero_intro_newcampaign', 'mod_playerpuzzle'), $new['intro']);
        $this->assertSame(
            ['magef'],
            array_column(array_filter($new['heroes'], fn($hero) => $hero['checked']), 'key'),
            'a new Campaign comes marked with the hero of the one that ended'
        );
        $this->assertStringContainsString('/mod/playerpuzzle/view.php', $new['backurl'], 'a new Campaign starts at the Lobby');
    }

    /**
     * Tests the screen of a Single Match and of a Demo: the form goes on to play.php with the hero, the last
     * hero chosen for a Single Match comes marked, and what the Lobby sent on is carried along.
     *
     * @return void
     */
    public function test_the_match_and_demo_screens(): void {
        [$cm, $instance] = $this->make(constants::GAMEMODE_SINGLE);
        $userid = (int) $this->student->id;
        last_hero::set($userid, 'magem');

        $match = hero_page_service::build_page_data(
            $cm,
            $instance,
            $userid,
            hero_page_service::MODE_SINGLE,
            ['difficulty' => 'hard', 'mobile' => 1],
            ''
        );
        $fields = array_column($match['fields'], 'value', 'name');

        $this->assertStringContainsString('/mod/playerpuzzle/play.php', $match['formurl']);
        $this->assertSame(['hard', '1', 'single', '0'], [$fields['difficulty'], $fields['mobile'], $fields['mode'],
            $fields['isdemo']]);
        $this->assertSame(['magem'], array_column(array_filter($match['heroes'], fn($h) => $h['checked']), 'key'));
        $this->assertSame(get_string('hero_intro_match', 'mod_playerpuzzle'), $match['intro']);
        $this->assertSame(get_string('hero_play_button', 'mod_playerpuzzle'), $match['submitlabel']);
        $this->assertStringContainsString('/mod/playerpuzzle/view.php', $match['backurl']);

        $demo = hero_page_service::build_page_data($cm, $instance, $userid, hero_page_service::MODE_DEMO, [], '');
        $this->assertSame('1', array_column($demo['fields'], 'value', 'name')['isdemo']);
    }

    /**
     * Tests that an error is shown as its lang string, and that the screen renders: every hero as a labelled
     * radio button, required, with the alt text of the picture empty.
     *
     * @return void
     */
    public function test_the_screen_renders_labelled_radio_buttons(): void {
        global $OUTPUT;

        [$cm, $instance] = $this->make(constants::GAMEMODE_CAMPAIGN);
        $data = hero_page_service::build_page_data(
            $cm,
            $instance,
            (int) $this->student->id,
            hero_page_service::MODE_CAMPAIGN,
            [],
            'hero_choose_error'
        );
        $this->assertSame(get_string('hero_choose_error', 'mod_playerpuzzle'), $data['error']);

        $html = $OUTPUT->render_from_template('mod_playerpuzzle/hero_select', $data);

        $this->assertSame(6, substr_count($html, 'type="radio"'));
        $this->assertSame(6, substr_count($html, ' required '));
        $this->assertSame(6, substr_count($html, 'alt=""'));
        $this->assertStringContainsString('for="pp-hero-magef"', $html);
        $this->assertStringContainsString(get_string('hero_choose_error', 'mod_playerpuzzle'), $html);
    }
}
