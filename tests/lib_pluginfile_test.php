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
 * Unit tests for playerpuzzle_pluginfile().
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

use mod_playerpuzzle\local\questions_repository;

/**
 * Tests for playerpuzzle_pluginfile().
 *
 * @covers ::playerpuzzle_pluginfile
 */
final class lib_pluginfile_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Course module for the playerpuzzle instance. */
    private \stdClass $cm;

    /** @var \stdClass Activity instance. */
    private \stdClass $instance;

    /** @var \context_module Module context. */
    private \context_module $context;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('playerpuzzle', $this->instance->id);
        $this->context = \context_module::instance($this->cm->id);

        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->setUser($this->student);
    }

    /**
     * Creates one question with a real stored file in its questiontext area, returning the
     * question id.
     *
     * @return int
     */
    private function make_question_with_file(): int {
        $questionid = questions_repository::add_question(
            (int) $this->instance->id,
            'multichoice',
            '<p>See @@PLUGINFILE@@/pic.png</p>',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_playerpuzzle',
            'filearea'  => 'questiontext',
            'itemid'    => $questionid,
            'filepath'  => '/',
            'filename'  => 'pic.png',
        ], 'fake image bytes');

        return $questionid;
    }

    /**
     * Tests that a non-module context is rejected outright.
     *
     * @return void
     */
    public function test_rejects_non_module_context(): void {
        $result = playerpuzzle_pluginfile(
            $this->course,
            $this->cm,
            \context_system::instance(),
            'questiontext',
            [1, 'pic.png'],
            false
        );

        $this->assertFalse($result);
    }

    /**
     * Tests that an unknown filearea is rejected.
     *
     * @return void
     */
    public function test_rejects_unknown_filearea(): void {
        $questionid = $this->make_question_with_file();

        $result = playerpuzzle_pluginfile(
            $this->course,
            $this->cm,
            $this->context,
            'generalfeedback',
            [$questionid, 'pic.png'],
            false
        );

        $this->assertFalse($result);
    }

    /**
     * Tests that a questiontext itemid belonging to a different instance is rejected — the
     * same instance-isolation rule applied everywhere else a client-supplied id is used.
     *
     * @return void
     */
    public function test_rejects_question_from_another_instance(): void {
        $othergenerator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $otherinstance = $othergenerator->create_instance(['course' => $this->course->id]);
        $foreignquestionid = questions_repository::add_question(
            (int) $otherinstance->id,
            'multichoice',
            'Q',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        $result = playerpuzzle_pluginfile(
            $this->course,
            $this->cm,
            $this->context,
            'questiontext',
            [$foreignquestionid, 'pic.png'],
            false
        );

        $this->assertFalse($result);
    }

    /**
     * Tests that an answertext itemid belonging to a different instance's question is
     * rejected.
     *
     * @return void
     */
    public function test_rejects_answer_from_another_instance(): void {
        $othergenerator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $otherinstance = $othergenerator->create_instance(['course' => $this->course->id]);
        $foreignquestionid = questions_repository::add_question(
            (int) $otherinstance->id,
            'multichoice',
            'Q',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );
        $foreignanswerid = (int) questions_repository::get_question(
            $foreignquestionid,
            (int) $otherinstance->id
        )->answers[0]->id;

        $result = playerpuzzle_pluginfile(
            $this->course,
            $this->cm,
            $this->context,
            'answertext',
            [$foreignanswerid, 'pic.png'],
            false
        );

        $this->assertFalse($result);
    }

    /**
     * Tests that a nonexistent file for an otherwise-valid, owned question returns false
     * rather than erroring.
     *
     * @return void
     */
    public function test_rejects_missing_file(): void {
        $questionid = $this->make_question_with_file();

        $result = playerpuzzle_pluginfile(
            $this->course,
            $this->cm,
            $this->context,
            'questiontext',
            [$questionid, 'doesnotexist.png'],
            false
        );

        $this->assertFalse($result);
    }
}
