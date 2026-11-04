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
 * Tests for what the hero upgrade shop shows.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for hero_shop::context().
 *
 * @covers \mod_playerpuzzle\local\hero_shop
 */
final class hero_shop_test extends \advanced_testcase {
    /** @var \stdClass Activity instance, a one-level Campaign. */
    private \stdClass $instance;

    /** @var \stdClass Student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $course->id, 'maxlevels' => 1, 'minquestions' => 0]);
    }

    /**
     * Tests that a student with no hero, and a Single Match, have no upgrades to show.
     *
     * @return void
     */
    public function test_nothing_to_show_without_a_campaign_hero(): void {
        $userid = (int) $this->student->id;
        $this->assertSame([], hero_shop::context($this->instance, $userid));

        $single = clone $this->instance;
        $single->gamemode = constants::GAMEMODE_SINGLE;
        $this->assertSame([], hero_shop::context($single, $userid));
    }

    /**
     * Tests the hero's card and one row for each attribute: the counts of the stage, what one buy adds in
     * words, the price, and the row of an attribute at the stage's cap sold out.
     *
     * @return void
     */
    public function test_the_card_and_the_rows(): void {
        global $DB;

        $userid = (int) $this->student->id;
        $row = game_page_service::choose_campaign_hero($this->instance, $userid, 'magef');
        $DB->set_field('playerpuzzle_heroes', 'attackbuys', 1, ['id' => $row->id]);

        $data = hero_shop::context($this->instance, $userid);

        $this->assertTrue($data['hasupgrades']);
        $this->assertSame(get_string('hero_name_magef', 'mod_playerpuzzle'), $data['herocard']['name']);
        $this->assertStringContainsString('/pix/heroes/magef1.webp', $data['herocard']['spriteurl']);
        $this->assertSame(
            get_string('shop_hero_stage', 'mod_playerpuzzle', (object) ['stage' => 1, 'total' => 4]),
            $data['herocard']['stagetext']
        );
        $rows = array_column($data['upgrades'], null, 'attribute');
        $this->assertSame(['hp', 'attack', 'armor'], array_keys($rows));
        $this->assertSame(heroes::PRICE, $rows['hp']['price']);
        $this->assertFalse($rows['hp']['soldout']);
        $this->assertTrue($rows['attack']['soldout'], 'one attack buy fills the one-level first stage');
        $this->assertSame(
            get_string('shop_upgrade_bought', 'mod_playerpuzzle', (object) ['bought' => 1, 'cap' => 1]),
            $rows['attack']['boughttext']
        );
        $this->assertSame(
            get_string('shop_upgrade_effect_hp', 'mod_playerpuzzle', format_float(35.0, 1)),
            $rows['hp']['effecttext']
        );
    }

    /**
     * Tests what the evolution hint says: when the milestone is still ahead, when it has passed and only the
     * specialty is missing, and at the last stage.
     *
     * @return void
     */
    public function test_the_evolution_hint(): void {
        global $DB;

        $userid = (int) $this->student->id;
        $row = game_page_service::choose_campaign_hero($this->instance, $userid, 'magef');
        $needs = (object) [
            'phase' => 3,
            'count' => 1,
            'attribute' => get_string('hero_attribute_hp', 'mod_playerpuzzle'),
        ];

        $ahead = hero_shop::context($this->instance, $userid)['evolvehint'];
        $this->assertSame(get_string('shop_evolve_hint', 'mod_playerpuzzle', $needs), $ahead);

        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $this->instance->id, 'userid' => $userid, 'token' => bin2hex(random_bytes(32)),
            'status' => 'inprogress', 'currentphase' => 4, 'campaign' => 1, 'timecreated' => time(),
        ]);
        $passed = hero_shop::context($this->instance, $userid)['evolvehint'];
        $this->assertSame(get_string('shop_evolve_waiting', 'mod_playerpuzzle', $needs), $passed);

        $DB->set_field('playerpuzzle_heroes', 'stage', 4, ['id' => $row->id]);
        $this->assertSame(
            get_string('shop_evolve_max', 'mod_playerpuzzle'),
            hero_shop::context($this->instance, $userid)['evolvehint']
        );
    }

    /**
     * Tests that the shop warns only when a phase is under way, one that what is bought cannot reach.
     *
     * @return void
     */
    public function test_the_phase_note_only_when_a_phase_is_under_way(): void {
        global $DB;

        $userid = (int) $this->student->id;
        game_page_service::choose_campaign_hero($this->instance, $userid, 'magef');
        $attempt = engine\security::resume_or_create_attempt_token((int) $this->instance->id, $userid, 'normal', 1);
        $this->assertSame('', hero_shop::context($this->instance, $userid)['phasenote'], 'a fresh phase takes the purchase');

        $DB->update_record('playerpuzzle_attempts', (object) [
            'id' => $attempt->attemptid, 'moveseq' => 2, 'movelog' => '[{"type":"move"}]',
        ]);
        $this->assertSame(
            get_string('shop_phase_note', 'mod_playerpuzzle'),
            hero_shop::context($this->instance, $userid)['phasenote']
        );
    }

    /**
     * Tests that the Lobby shop carries the upgrades for a student with a hero, rendered through the shared
     * templates with a button for each attribute that can still be bought and none for one sold out.
     *
     * @return void
     */
    public function test_the_shop_panel_renders_the_upgrades(): void {
        global $OUTPUT;

        $userid = (int) $this->student->id;
        game_page_service::choose_campaign_hero($this->instance, $userid, 'archerm');
        $data = ['cmid' => 7] + lobby_page_service::build_shop_context(
            (int) $this->instance->course,
            $this->instance,
            $userid
        ) + hero_shop::context($this->instance, $userid);

        $html = $OUTPUT->render_from_template('mod_playerpuzzle/shop_panel', $data);

        $this->assertSame(3, substr_count($html, 'class="btn btn-sm btn-outline-primary pp-hero-buy"'));
        $this->assertSame(5, substr_count($html, 'pp-lobby-buy"'));
        $this->assertStringContainsString('data-cmid="7"', $html);
        $this->assertStringContainsString(get_string('shop_upgrades_title', 'mod_playerpuzzle'), $html);
    }
}
