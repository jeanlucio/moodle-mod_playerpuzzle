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
 * External function tests for generate_questions.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

/**
 * Tests for the mod_playerpuzzle_generate_questions web service.
 *
 * Only the parts reachable without an actual AI provider are covered here (capability
 * enforcement and input validation) — same scope mod_playerwords\local\
 * ai_word_generator_test applies to its own AI-calling class: the network call itself is
 * never unit-tested, only what happens before and after it.
 *
 * @covers \mod_playerpuzzle\external\generate_questions
 */
final class generate_questions_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Activity instance. */
    private \stdClass $instance;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

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
        generate_questions::execute($cm->id, 'Astronomy', 5);
    }

    /**
     * Tests that an empty topic is rejected before any AI call is attempted.
     *
     * @return void
     */
    public function test_rejects_empty_topic(): void {
        $this->setAdminUser();
        $cm = get_coursemodule_from_instance('playerpuzzle', $this->instance->id);

        $this->expectException(\moodle_exception::class);
        generate_questions::execute($cm->id, '   ', 5);
    }
}
