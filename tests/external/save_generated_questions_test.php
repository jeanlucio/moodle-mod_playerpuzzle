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
 * External function tests for save_generated_questions.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use mod_playerpuzzle\local\questions_repository;

/**
 * Tests for the mod_playerpuzzle_save_generated_questions web service.
 *
 * Never touches an AI provider — the input here is exactly the shape a teacher's
 * browser resubmits after reviewing a preview, so every case is reachable without any
 * network access.
 *
 * @covers \mod_playerpuzzle\external\save_generated_questions
 */
final class save_generated_questions_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Activity instance. */
    private \stdClass $instance;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);
    }

    /**
     * Tests that a user without mod/playerpuzzle:managequestions is denied.
     *
     * @return void
     */
    public function test_requires_managequestions_capability(): void {
        $cm = get_coursemodule_from_instance('playerpuzzle', $this->instance->id);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        save_generated_questions::execute($cm->id, [
            [
                'qtype' => 'truefalse',
                'questiontext' => 'Q?',
                'hint' => '',
                'answers' => [
                    ['text' => 'True', 'iscorrect' => true],
                    ['text' => 'False', 'iscorrect' => false],
                ],
            ],
        ]);
    }

    /**
     * Tests that a valid batch is saved as source=ai, approved=0, owned by the current
     * instance and user.
     *
     * @return void
     */
    public function test_saves_valid_questions_as_pending_ai(): void {
        global $USER;

        $cm = get_coursemodule_from_instance('playerpuzzle', $this->instance->id);

        $result = save_generated_questions::execute($cm->id, [
            [
                'qtype' => 'multichoice',
                'questiontext' => 'Capital of France?',
                'hint' => 'Eiffel Tower',
                'answers' => [
                    ['text' => 'Paris', 'iscorrect' => true],
                    ['text' => 'Lyon', 'iscorrect' => false],
                ],
            ],
            [
                'qtype' => 'truefalse',
                'questiontext' => 'The sky is blue.',
                'hint' => '',
                'answers' => [
                    ['text' => 'True', 'iscorrect' => true],
                    ['text' => 'False', 'iscorrect' => false],
                ],
            ],
        ]);

        $this->assertSame(2, $result['saved']);
        $this->assertSame(0, $result['skipped']);

        $questions = questions_repository::get_questions_for_instance((int) $this->instance->id);
        $this->assertCount(2, $questions);
        foreach ($questions as $question) {
            $this->assertSame('ai', $question->source);
            $this->assertSame(0, (int) $question->approved);
            $this->assertSame((int) $USER->id, (int) $question->addedby);
        }
    }

    /**
     * Tests that a malformed entry in the batch (here, only one correct answer marked
     * out of zero) is counted as skipped and never persisted, while a valid sibling in
     * the same batch is still saved.
     *
     * @return void
     */
    public function test_skips_malformed_entries_without_failing_the_whole_batch(): void {
        $cm = get_coursemodule_from_instance('playerpuzzle', $this->instance->id);

        $result = save_generated_questions::execute($cm->id, [
            [
                'qtype' => 'multichoice',
                'questiontext' => 'No correct answer',
                'hint' => '',
                'answers' => [
                    ['text' => 'A', 'iscorrect' => false],
                    ['text' => 'B', 'iscorrect' => false],
                ],
            ],
            [
                'qtype' => 'truefalse',
                'questiontext' => 'Valid one',
                'hint' => '',
                'answers' => [
                    ['text' => 'True', 'iscorrect' => true],
                    ['text' => 'False', 'iscorrect' => false],
                ],
            ],
        ]);

        $this->assertSame(1, $result['saved']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, questions_repository::get_questions_for_instance((int) $this->instance->id));
    }

    /**
     * Tests that saving against one instance never leaks into a different instance's
     * own question bank.
     *
     * @return void
     */
    public function test_saves_scoped_to_the_right_instance(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $otherinstance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $this->instance->id);

        save_generated_questions::execute($cm->id, [
            [
                'qtype' => 'truefalse',
                'questiontext' => 'Q?',
                'hint' => '',
                'answers' => [
                    ['text' => 'True', 'iscorrect' => true],
                    ['text' => 'False', 'iscorrect' => false],
                ],
            ],
        ]);

        $this->assertCount(1, questions_repository::get_questions_for_instance((int) $this->instance->id));
        $this->assertSame([], questions_repository::get_questions_for_instance((int) $otherinstance->id));
    }
}
