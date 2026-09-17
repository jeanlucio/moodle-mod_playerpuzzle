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
 * Unit tests for the question management listing's status label/action logic.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for question_list_service.
 *
 * @covers \mod_playerpuzzle\local\question_list_service
 */
final class question_list_service_test extends \advanced_testcase {
    /**
     * Creates a real course module context and instance, needed by build_list_context()
     * for the AI-availability check and the paging bar URL.
     *
     * @return array{0: \stdClass, 1: \context_module}
     */
    private function make_instance_and_context(): array {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        return [$instance, \context_module::instance($cm->id)];
    }

    /**
     * Tests that an AI-generated question awaiting review shows the pending label, gets the
     * warning badge class, and offers a working approve link — the one case a teacher is
     * actually meant to act on from this screen.
     *
     * @return void
     */
    public function test_ai_sourced_unapproved_question_is_labelled_pending_with_approve_link(): void {
        global $PAGE;

        $this->resetAfterTest();
        [$instance, $context] = $this->make_instance_and_context();

        questions_repository::add_question(
            (int) $instance->id,
            'multichoice',
            'Generated question?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2,
            'ai',
            false
        );

        $listcontext = question_list_service::build_list_context(
            $instance,
            (int) get_coursemodule_from_instance('playerpuzzle', $instance->id)->id,
            $PAGE->get_renderer('core'),
            $context
        );

        $row = $listcontext['questions'][0];
        $this->assertSame(get_string('pendingstatus', 'mod_playerpuzzle'), $row['statuslabel']);
        $this->assertSame('bg-warning text-dark', $row['statusbadgeclass']);
        $this->assertTrue($row['ispending']);
        $this->assertNotSame('', $row['approveurl']);
    }

    /**
     * Tests that a bank-sourced question disabled by the sync (orphaned from its source
     * category) shows the disabled label, gets the secondary badge class, and offers no
     * approve link — reactivating it is resyncing, not approving (§R7 of
     * banco-questoes-unificacao.md).
     *
     * @return void
     */
    public function test_bank_sourced_unapproved_question_is_labelled_disabled_without_approve_link(): void {
        global $PAGE;

        $this->resetAfterTest();
        [$instance, $context] = $this->make_instance_and_context();

        questions_repository::add_question(
            (int) $instance->id,
            'multichoice',
            'Orphaned bank question?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            0,
            'bank',
            false,
            FORMAT_PLAIN,
            123
        );

        $listcontext = question_list_service::build_list_context(
            $instance,
            (int) get_coursemodule_from_instance('playerpuzzle', $instance->id)->id,
            $PAGE->get_renderer('core'),
            $context
        );

        $row = $listcontext['questions'][0];
        $this->assertSame(get_string('disabledstatus', 'mod_playerpuzzle'), $row['statuslabel']);
        $this->assertSame('bg-secondary pp-status-disabled', $row['statusbadgeclass']);
        $this->assertFalse($row['ispending']);
        $this->assertSame('', $row['approveurl']);
    }

    /**
     * Tests that an approved question, regardless of source, shows the approved label and
     * the success badge class.
     *
     * @return void
     */
    public function test_approved_question_is_labelled_approved(): void {
        global $PAGE;

        $this->resetAfterTest();
        [$instance, $context] = $this->make_instance_and_context();

        questions_repository::add_question(
            (int) $instance->id,
            'multichoice',
            'Approved question?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        $listcontext = question_list_service::build_list_context(
            $instance,
            (int) get_coursemodule_from_instance('playerpuzzle', $instance->id)->id,
            $PAGE->get_renderer('core'),
            $context
        );

        $row = $listcontext['questions'][0];
        $this->assertSame(get_string('approvedstatus', 'mod_playerpuzzle'), $row['statuslabel']);
        $this->assertSame('bg-success', $row['statusbadgeclass']);
        $this->assertFalse($row['ispending']);
        $this->assertSame('', $row['approveurl']);
    }
}
