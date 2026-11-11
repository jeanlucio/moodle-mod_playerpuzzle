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
 * Unit tests for the How to play help.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for howtoplay — requires database.
 *
 * @covers \mod_playerpuzzle\local\howtoplay
 */
final class howtoplay_test extends \advanced_testcase {
    /** @var \stdClass The course the activities are made in. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Makes an activity.
     *
     * @param array $overrides Fields of the activity to set.
     * @return \stdClass The activity instance.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        return $generator->create_instance(array_merge(['course' => $this->course->id], $overrides));
    }

    /**
     * Tests that the seven pieces are listed, each with its sprite and the sentence the Demo's balloon says, and that the
     * three difficulties and the stage's six buttons are listed too.
     *
     * @return void
     */
    public function test_it_lists_the_seven_pieces_the_difficulties_and_the_buttons(): void {
        $context = howtoplay::context($this->make_instance(), false);

        $this->assertCount(7, $context['pieces']);
        $names = ['star', 'grimoire', 'orb', 'sword', 'shield', 'potion', 'coin'];
        foreach ($context['pieces'] as $type => $piece) {
            $this->assertStringContainsString('sprites/item' . $type, $piece['imageurl']);
            $this->assertSame(get_string('tutorialballoon_' . $names[$type], 'mod_playerpuzzle'), $piece['text']);
        }
        $this->assertSame(
            array_values(playerpuzzle_get_difficulty_options()),
            array_column($context['difficulties'], 'label')
        );
        $this->assertCount(6, $context['buttons']);
        foreach ($context['buttons'] as $button) {
            $this->assertNotSame('', $button['label']);
            $this->assertNotSame('', $button['text']);
            $this->assertStringStartsWith('fa-', $button['icon']);
        }
    }

    /**
     * Tests that a Campaign says how many levels it has and that the difficulty is chosen for each phase, and that a
     * Single Match says neither.
     *
     * @return void
     */
    public function test_the_game_mode_text_follows_the_activity(): void {
        $campaign = howtoplay::context(
            $this->make_instance(['gamemode' => constants::GAMEMODE_CAMPAIGN, 'maxlevels' => 3]),
            false
        );
        $this->assertSame(get_string('howto_campaign_text', 'mod_playerpuzzle', 3), $campaign['modetext']);
        $this->assertStringContainsString('3', $campaign['modetext']);
        $this->assertTrue($campaign['showcampaigndifficulty']);

        $single = howtoplay::context($this->make_instance(['gamemode' => constants::GAMEMODE_SINGLE]), false);
        $this->assertSame(get_string('howto_single_text', 'mod_playerpuzzle'), $single['modetext']);
        $this->assertFalse($single['showcampaigndifficulty']);
    }

    /**
     * Tests that the question sections appear only when the activity has an approved question to draw.
     *
     * @return void
     */
    public function test_the_question_sections_need_a_question_in_the_bank(): void {
        $instance = $this->make_instance();
        $this->assertFalse(howtoplay::context($instance, false)['showquestions']);

        questions_repository::add_question(
            (int) $instance->id,
            'multichoice',
            'Q?',
            '',
            [['text' => 'A', 'iscorrect' => true], ['text' => 'B', 'iscorrect' => false]],
            2
        );
        $this->assertTrue(howtoplay::context($instance, false)['showquestions']);
    }

    /**
     * Tests that the minimum of questions is said only when the activity requires one, with the number the teacher set.
     *
     * @return void
     */
    public function test_the_minimum_of_questions_is_said_only_when_required(): void {
        $this->assertFalse(howtoplay::context($this->make_instance(['minquestions' => 0]), false)['showminquestions']);

        $context = howtoplay::context($this->make_instance(['minquestions' => 4]), false);
        $this->assertTrue($context['showminquestions']);
        $this->assertSame(get_string('howto_minquestions_text', 'mod_playerpuzzle', 4), $context['minquestionstext']);
    }

    /**
     * Tests that a visitor, who has only the Demo, is not told about the shop.
     *
     * @return void
     */
    public function test_a_visitor_is_not_told_about_the_shop(): void {
        $instance = $this->make_instance();

        $this->assertTrue(howtoplay::context($instance, false)['showshop']);
        $this->assertFalse(howtoplay::context($instance, true)['showshop']);
    }
}
