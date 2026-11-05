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

        $side = heroes::attributes('magef', ['hp' => 0, 'attack' => 1, 'armor' => 0], 1, (int) $this->instance->basestudenthp);
        $this->assertSame((string) $side['hp'], $rows['hp']['valuetext']);
        $this->assertSame('×' . format_float($side['attack'], 2), $rows['attack']['valuetext']);
        $this->assertSame(format_float($side['armor'] * 100, 1) . '%', $rows['armor']['valuetext']);
        $this->assertSame([['on' => false]], $rows['hp']['pips']);
        $this->assertSame([['on' => true]], $rows['attack']['pips']);
        $this->assertSame('fa-heart', $rows['hp']['icon']);
        $this->assertSame(get_string('shop_upgrade_full', 'mod_playerpuzzle'), $rows['attack']['fulltext']);
        $this->assertSame(
            get_string('hero_specialty', 'mod_playerpuzzle', get_string('hero_attribute_hp', 'mod_playerpuzzle')),
            $data['herocard']['specialtytext']
        );
        $this->assertSame([true, false, false, false], array_column($data['herocard']['stagepips'], 'on'));
    }

    /**
     * Tests the evolution trail: the two conditions with where each stands, the phase ahead, the next phase
     * and the phase won, the specialty once it is full, and nothing left to ask for at the last stage.
     *
     * @return void
     */
    public function test_the_evolution_trail(): void {
        global $DB;

        $userid = (int) $this->student->id;
        $row = game_page_service::choose_campaign_hero($this->instance, $userid, 'magef');
        $attribute = get_string('hero_attribute_hp', 'mod_playerpuzzle');

        $trail = hero_shop::context($this->instance, $userid)['evolve'];
        $this->assertSame(get_string('shop_evolve_title', 'mod_playerpuzzle', 2), $trail['title']);
        $this->assertSame([false, false], array_column($trail['steps'], 'done'));
        $this->assertSame(get_string('shop_evolve_step_specialty', 'mod_playerpuzzle', $attribute), $trail['steps'][0]['text']);
        $this->assertSame(
            get_string('shop_evolve_count', 'mod_playerpuzzle', (object) ['bought' => 0, 'cap' => 1]),
            $trail['steps'][0]['note']
        );
        $this->assertSame(get_string('shop_evolve_step_phase', 'mod_playerpuzzle', 3), $trail['steps'][1]['text']);
        $this->assertSame(get_string('shop_evolve_phase_left', 'mod_playerpuzzle', 3), $trail['steps'][1]['note']);

        $attemptid = $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $this->instance->id, 'userid' => $userid, 'token' => bin2hex(random_bytes(32)),
            'status' => 'inprogress', 'currentphase' => 3, 'campaign' => 1, 'timecreated' => time(),
        ]);
        $this->assertSame(
            get_string('shop_evolve_phase_next', 'mod_playerpuzzle'),
            hero_shop::context($this->instance, $userid)['evolve']['steps'][1]['note']
        );

        $DB->set_field('playerpuzzle_attempts', 'currentphase', 4, ['id' => $attemptid]);
        $DB->set_field('playerpuzzle_heroes', 'hpbuys', 1, ['id' => $row->id]);
        $trail = hero_shop::context($this->instance, $userid)['evolve'];
        $this->assertSame([true, true], array_column($trail['steps'], 'done'), 'only the evolution itself is missing');
        $this->assertSame(get_string('shop_evolve_phase_done', 'mod_playerpuzzle'), $trail['steps'][1]['note']);

        $DB->set_field('playerpuzzle_heroes', 'stage', 4, ['id' => $row->id]);
        $last = hero_shop::context($this->instance, $userid)['evolve'];
        $this->assertSame(get_string('shop_evolve_max', 'mod_playerpuzzle'), $last['title']);
        $this->assertSame([], $last['steps']);
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

        $this->assertSame(3, substr_count($html, 'class="btn btn-sm btn-primary pp-hero-buy"'));
        $this->assertSame(5, substr_count($html, 'pp-lobby-buy"'));
        $this->assertStringContainsString('data-cmid="7"', $html);
        $this->assertStringContainsString(get_string('shop_upgrades_title', 'mod_playerpuzzle'), $html);
    }
}
