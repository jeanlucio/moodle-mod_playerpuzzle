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
 * Unit tests for the plugin's test data generator.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for mod_playerpuzzle_generator — the Behat scenarios rely on it, and Behat only runs in
 * CI, so a broken generator is caught here first.
 *
 * @covers \mod_playerpuzzle_generator
 */
final class generator_test extends \advanced_testcase {
    /**
     * Tests that create_attempt() gives a real in-progress attempt its pinned seed, leaving the
     * rest as a real Play would, and optionally an older engine version, a phase and a status.
     *
     * @return void
     */
    public function test_create_attempt_pins_the_seed_and_optional_fields(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id, 'basebosshp' => 10]);

        $generator->create_attempt(['playerpuzzleid' => $instance->id, 'userid' => $user->id, 'rngseed' => 2]);
        $plain = $DB->get_record('playerpuzzle_attempts', ['userid' => $user->id], '*', MUST_EXIST);

        $this->assertSame(2, (int) $plain->rngseed);
        $this->assertSame('inprogress', $plain->status);
        $this->assertSame((int) get_config('mod_playerpuzzle', 'version'), (int) $plain->engineversion);
        $this->assertSame(10, (int) $plain->frozenbasebosshp);

        $other = $this->getDataGenerator()->create_user();
        $generator->create_attempt([
            'playerpuzzleid' => $instance->id,
            'userid' => $other->id,
            'rngseed' => 5,
            'engineversion' => 1,
            'currentphase' => 7,
            'status' => 'lost',
        ]);
        $pinned = $DB->get_record('playerpuzzle_attempts', ['userid' => $other->id], '*', MUST_EXIST);

        $this->assertSame(1, (int) $pinned->engineversion);
        $this->assertSame(7, (int) $pinned->currentphase);
        $this->assertSame('lost', $pinned->status);
        $this->assertGreaterThan(0, (int) $pinned->timefinished);
    }

    /**
     * Tests that create_question() adds an approved multiple choice question whose first answer is the right one and
     * whose others, given as a comma separated list, are the wrong ones.
     *
     * @return void
     */
    public function test_create_question_adds_an_approved_question_with_its_answers(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $course->id]);

        $generator->create_question([
            'playerpuzzleid' => $instance->id,
            'questiontext' => 'What is the capital of Italy?',
            'correct' => 'Rome',
            'wrong' => 'Pisa, Bari',
        ]);

        $question = $DB->get_record('playerpuzzle_questions', ['playerpuzzleid' => $instance->id], '*', MUST_EXIST);
        $answers = $DB->get_records_menu(
            'playerpuzzle_question_answers',
            ['questionid' => $question->id],
            'id',
            'answertext, iscorrect'
        );

        $this->assertSame(1, (int) $question->approved);
        $this->assertEquals(['Rome' => 1, 'Pisa' => 0, 'Bari' => 0], $answers);
        $this->assertTrue(\mod_playerpuzzle\local\questions_repository::has_approved_question((int) $instance->id));
    }
}
