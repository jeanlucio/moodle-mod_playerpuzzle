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
 * Unit tests for question_form's growable multichoice slot count.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\form;

/**
 * Tests for question_form.
 *
 * @covers \mod_playerpuzzle\form\question_form
 */
final class question_form_test extends \advanced_testcase {
    /**
     * A brand new question (no existing answers) starts at 5 slots, mirroring core's own
     * question/type/multichoice/edit_multichoice_form.php default.
     *
     * @return void
     */
    public function test_initial_repeat_count_defaults_to_five_for_a_new_question(): void {
        $this->assertSame(5, question_form::initial_repeat_count(0));
    }

    /**
     * A question with fewer than 5 saved answers still renders at least 5 slots.
     *
     * @return void
     */
    public function test_initial_repeat_count_never_goes_below_five(): void {
        $this->assertSame(5, question_form::initial_repeat_count(2));
    }

    /**
     * A question with more than 5 saved answers (from AI generation or bank import, since
     * neither of those is capped to what this form starts with) renders enough slots to show
     * every one of them, not just the first 5.
     *
     * @return void
     */
    public function test_initial_repeat_count_grows_to_fit_existing_answers(): void {
        $this->assertSame(8, question_form::initial_repeat_count(8));
    }
}
