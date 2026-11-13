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
 * Tests for what the opponent's card says.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for opponent_card.
 *
 * @covers \mod_playerpuzzle\local\opponent_card
 */
final class opponent_card_test extends \advanced_testcase {
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
     * The side of an opponent with nothing that sets it apart.
     *
     * @param array $changes What to change in it.
     * @return array
     */
    private function side(array $changes = []): array {
        return $changes + [
            'bossattack' => 1.0,
            'bossarmor' => 0.0,
            'bosspowers' => [],
            'bossbreathevery' => 0,
            'bosskit' => null,
        ];
    }

    /**
     * The texts of a card's lines.
     *
     * @param array $card A card.
     * @return string[]
     */
    private function texts(array $card): array {
        return array_column($card['traits'], 'text');
    }

    /**
     * Tests that each thing that sets an opponent apart gets a line, worked out from the numbers it fights with.
     *
     * @return void
     */
    public function test_a_line_for_each_difference(): void {
        $card = opponent_card::build('goblin', true, 2, 3, $this->side([
            'bossattack' => 1.15,
            'bossarmor' => 0.15,
            'bosspowers' => ['ambush'],
        ]), false, 'next');

        $this->assertSame([
            get_string('opponent_trait_attack', 'mod_playerpuzzle', 15),
            get_string('opponent_trait_armor', 'mod_playerpuzzle', 15),
            get_string('power_ambush', 'mod_playerpuzzle', 10),
        ], $this->texts($card));
        $this->assertSame(get_string('bossavatar_goblin', 'mod_playerpuzzle'), $card['name']);
        $this->assertSame(get_string('opponent_card_next', 'mod_playerpuzzle'), $card['heading']);
        $this->assertSame(
            get_string('opponent_card_phase', 'mod_playerpuzzle', (object) ['level' => 2, 'phase' => 3]),
            $card['phasetext']
        );
        $this->assertStringContainsString('/pix/opponents/goblin.webp', $card['spriteurl']);
    }

    /**
     * Tests the extra HP of the tough profile, which a Demo does not have, and the line for an opponent with
     * nothing to say.
     *
     * @return void
     */
    public function test_extra_hp_and_nothing_to_say(): void {
        $this->assertSame(
            [get_string('opponent_trait_hp', 'mod_playerpuzzle', 15)],
            $this->texts(opponent_card::build('spider', true, 1, 9, $this->side(), false, 'next'))
        );
        $this->assertSame(
            [get_string('opponent_trait_none', 'mod_playerpuzzle')],
            $this->texts(opponent_card::build('spider', true, 1, 9, $this->side(), true, 'now'))
        );
    }

    /**
     * Tests that the breath says its own rhythm: the dragon's default and the final boss's.
     *
     * @return void
     */
    public function test_the_breath_says_its_rhythm(): void {
        $dragon = opponent_card::build('dragon', true, 1, 10, $this->side(['bosspowers' => ['breath']]), false, 'next');
        $vampire = opponent_card::build(
            'vampire',
            true,
            1,
            10,
            $this->side(['bosspowers' => ['drain', 'breath'], 'bossbreathevery' => 12]),
            false,
            'next'
        );

        $this->assertSame([get_string('power_breath', 'mod_playerpuzzle', 10)], $this->texts($dragon));
        $this->assertContains(get_string('power_breath', 'mod_playerpuzzle', 12), $this->texts($vampire));
        $this->assertContains(get_string('opponent_trait_adapts', 'mod_playerpuzzle'), $this->texts($vampire));
    }

    /**
     * Tests that the items are listed only when there are some and the phase is known.
     *
     * @return void
     */
    public function test_the_items(): void {
        $kit = get_string('opponentkit', 'mod_playerpuzzle', (object) ['potions' => 2, 'swords' => 1]);

        $with = opponent_card::build('golem', true, 4, 1, $this->side(['bosskit' => ['potion' => 2, 'sword' => 1]]), false, 'now');
        $this->assertContains($kit, $this->texts($with));

        $empty = opponent_card::build('golem', true, 1, 1, $this->side(['bosskit' => ['potion' => 0, 'sword' => 0]]), false, 'now');
        $this->assertNotContains($kit, $this->texts($empty));
    }

    /**
     * Tests that the card names only the items the opponent brings, never one with a count of zero.
     *
     * @return void
     */
    public function test_the_items_name_only_what_the_opponent_brings(): void {
        $potion = get_string('opponentkit_potion', 'mod_playerpuzzle', (object) ['potions' => 1, 'swords' => 0]);
        $sword = get_string('opponentkit_sword', 'mod_playerpuzzle', (object) ['potions' => 0, 'swords' => 2]);

        $kitpotion = $this->side(['bosskit' => ['potion' => 1, 'sword' => 0]]);
        $kitsword = $this->side(['bosskit' => ['potion' => 0, 'sword' => 2]]);
        $onlypotion = opponent_card::build('golem', true, 1, 1, $kitpotion, false, 'now');
        $onlysword = opponent_card::build('golem', true, 1, 1, $kitsword, false, 'now');

        $this->assertContains($potion, $this->texts($onlypotion));
        $this->assertContains($sword, $this->texts($onlysword));
        foreach ([$onlypotion, $onlysword] as $card) {
            foreach ($this->texts($card) as $text) {
                $this->assertStringNotContainsString('×0', $text);
            }
        }
    }

    /**
     * Tests the card of a Campaign with no phase under way: the phase the next attempt starts on, with no
     * items since the difficulty is not chosen yet, and nothing for a Campaign whose hero is not chosen.
     *
     * @return void
     */
    public function test_the_next_phase_without_an_attempt(): void {
        $userid = (int) $this->student->id;
        $this->assertSame([], opponent_card::for_player($this->instance, $userid));

        game_page_service::choose_campaign_hero($this->instance, $userid, 'magef');
        $card = opponent_card::for_player($this->instance, $userid)['opponentcard'];

        $this->assertSame(get_string('bossavatar_slime', 'mod_playerpuzzle'), $card['name']);
        $this->assertSame(
            get_string('opponent_card_phase', 'mod_playerpuzzle', (object) ['level' => 1, 'phase' => 1]),
            $card['phasetext']
        );
        $this->assertContains(get_string('power_regen', 'mod_playerpuzzle', 10), $this->texts($card));
    }

    /**
     * Tests that a phase under way is read from the attempt: its opponent, its difficulty's items, and the
     * final boss's answer to the hero the phase froze.
     *
     * @return void
     */
    public function test_a_phase_under_way_is_read_from_the_attempt(): void {
        global $DB;

        $userid = (int) $this->student->id;
        game_page_service::choose_campaign_hero($this->instance, $userid, 'warriorf');
        $attempt = engine\security::resume_or_create_attempt_token((int) $this->instance->id, $userid, 'hard', 1);

        $items = opponent_card::for_player($this->instance, $userid)['opponentcard'];
        $this->assertContains(
            get_string('opponentkit_potion', 'mod_playerpuzzle', (object) ['potions' => 1, 'swords' => 0]),
            $this->texts($items),
            'a Hard phase brings an item, and names only that one'
        );

        $DB->update_record('playerpuzzle_attempts', (object) [
            'id' => $attempt->attemptid,
            'currentphase' => 10,
            'frozenopponent' => opponents::FINAL_BOSS,
            'frozenhero' => 'warriorf1',
        ]);
        $boss = opponent_card::for_player($this->instance, $userid)['opponentcard'];

        $this->assertSame(get_string('bossavatar_vampire', 'mod_playerpuzzle'), $boss['name']);
        // The 15% the final boss answers a hero strong in attack with, and the quarter its armor has grown by at the
        // last phase of this one-level Campaign.
        $this->assertContains(get_string('opponent_trait_armor', 'mod_playerpuzzle', 40), $this->texts($boss));
        $this->assertContains(get_string('power_drain', 'mod_playerpuzzle', 10), $this->texts($boss));
    }

    /**
     * Tests that an attempt from before the opponent was frozen (none stored) shows the opponent its phase has.
     *
     * @return void
     */
    public function test_an_attempt_without_a_frozen_opponent(): void {
        global $DB;

        $userid = (int) $this->student->id;
        game_page_service::choose_campaign_hero($this->instance, $userid, 'magef');
        $attempt = engine\security::resume_or_create_attempt_token((int) $this->instance->id, $userid, 'normal', 1);
        $DB->set_field('playerpuzzle_attempts', 'frozenopponent', null, ['id' => $attempt->attemptid]);

        $card = opponent_card::for_player($this->instance, $userid)['opponentcard'];

        $this->assertSame(get_string('bossavatar_slime', 'mod_playerpuzzle'), $card['name']);
    }

    /**
     * Tests the card of a Single Match: the opponent the teacher picked, with no level or phase to name and no
     * answer from the final boss, since the hero is chosen after the Lobby.
     *
     * @return void
     */
    public function test_a_single_match(): void {
        $single = clone $this->instance;
        $single->gamemode = constants::GAMEMODE_SINGLE;
        $single->bossavatar = opponents::FINAL_BOSS;

        $card = opponent_card::for_player($single, (int) $this->student->id)['opponentcard'];

        $this->assertSame(get_string('bossavatar_vampire', 'mod_playerpuzzle'), $card['name']);
        $this->assertSame('', $card['phasetext']);
        $this->assertSame(
            [
                get_string('opponent_trait_hp', 'mod_playerpuzzle', 20),
                get_string('power_drain', 'mod_playerpuzzle', 10),
                get_string('opponent_trait_adapts', 'mod_playerpuzzle'),
            ],
            $this->texts($card)
        );
    }

    /**
     * Tests the card built from the game page's config, for the dialog during the fight, and that it renders.
     *
     * @return void
     */
    public function test_the_card_of_the_fight_renders(): void {
        global $OUTPUT;

        $config = [
            'bossavatar' => 'mummy',
            'gamemode' => constants::GAMEMODE_CAMPAIGN,
            'currentlevel' => 1,
            'currentphase' => 4,
            'isdemo' => false,
            'bossattack' => 1.15,
            'bossarmor' => 0.0,
            'bosspowers' => ['longpoison'],
            'bossbreathevery' => 0,
            'bosskit' => ['potion' => 0, 'sword' => 0],
            'studenthp' => 618,
            'bosshp' => 570,
            'playerattack' => 1.9,
            'playerarmor' => 0.25,
        ];
        $card = opponent_card::for_fight($config);

        $this->assertSame(get_string('opponent_card_now', 'mod_playerpuzzle'), $card['heading']);
        $this->assertSame(
            [get_string('opponent_trait_attack', 'mod_playerpuzzle', 15), get_string('power_longpoison', 'mod_playerpuzzle', 10)],
            $this->texts($card)
        );

        $rows = array_column($card['stats']['rows'], null, 'label');
        $hp = get_string('hero_attribute_hp', 'mod_playerpuzzle');
        $attack = get_string('hero_attribute_attack', 'mod_playerpuzzle');
        $armor = get_string('hero_attribute_armor', 'mod_playerpuzzle');
        $this->assertSame([618, 570], [$rows[$hp]['you'], $rows[$hp]['opponent']]);
        $this->assertSame(
            ['×' . format_float(1.9, 2), '×' . format_float(1.15, 2)],
            [$rows[$attack]['you'], $rows[$attack]['opponent']]
        );
        $this->assertSame(
            [format_float(25, 1) . '%', format_float(0, 1) . '%'],
            [$rows[$armor]['you'], $rows[$armor]['opponent']]
        );

        $html = $OUTPUT->render_from_template(
            'mod_playerpuzzle/opponent_help',
            ['closelabel' => 'Close'] + $card
        );
        $this->assertStringContainsString('id="playerpuzzle-opponent-help"', $html);
        $this->assertStringContainsString(get_string('bossavatar_mummy', 'mod_playerpuzzle'), $html);
        $this->assertStringContainsString(get_string('power_longpoison', 'mod_playerpuzzle', 10), $html);
        $this->assertStringContainsString('<th scope="col">' . get_string('opponent_stats_you', 'mod_playerpuzzle'), $html);
        $this->assertStringContainsString('<td>618</td>', $html);

        $demo = opponent_card::for_fight(['bossavatar' => 'spider', 'isdemo' => true]);
        $this->assertSame([get_string('opponent_trait_none', 'mod_playerpuzzle')], $this->texts($demo));
        $this->assertSame('', $demo['phasetext']);
    }

    /**
     * Tests that the shop carries the card of the next opponent, as a folded line.
     *
     * @return void
     */
    public function test_the_shop_panel_carries_the_card(): void {
        global $OUTPUT;

        $userid = (int) $this->student->id;
        game_page_service::choose_campaign_hero($this->instance, $userid, 'archerm');
        $data = ['cmid' => 7] + lobby_page_service::build_shop_context(
            (int) $this->instance->course,
            $this->instance,
            $userid
        );

        $html = $OUTPUT->render_from_template('mod_playerpuzzle/shop_panel', $data);

        $this->assertStringContainsString('<details class="pp-opponent-card">', $html);
        $this->assertStringContainsString(get_string('opponent_card_next', 'mod_playerpuzzle') . ': ', $html);
        $this->assertStringContainsString(get_string('bossavatar_slime', 'mod_playerpuzzle'), $html);
    }
}
