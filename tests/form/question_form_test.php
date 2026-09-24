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
 * Unit tests for question_form.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\form;

/**
 * Tests for the manual question entry/edit form.
 *
 * @covers \mod_playerpuzzle\form\question_form
 */
final class question_form_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Instantiates question_form with the given customdata, returning the underlying
     * MoodleQuickForm so the test can inspect elements/options.
     *
     * @param int $answercount Existing answer count (0 for a brand new question).
     * @return \MoodleQuickForm
     */
    private function build_form(int $answercount = 0): \MoodleQuickForm {
        $form = new question_form(null, [
            'context' => \context_system::instance(),
            'answercount' => $answercount,
        ]);

        $refclass = new \ReflectionClass($form);
        $prop = $refclass->getProperty('_form');
        $prop->setAccessible(true);

        return $prop->getValue($form);
    }

    /**
     * Instantiates question_form, returning the form object itself rather than just its
     * underlying MoodleQuickForm — needed to call the form's own public validation()
     * method.
     *
     * @return question_form
     */
    private function build_form_object(): question_form {
        return new question_form(null, [
            'context' => \context_system::instance(),
            'answercount' => 0,
        ]);
    }

    /**
     * Asserts that hideIf($elementname, $dependenton, $condition, $value) was registered
     * on the form — hideIf is purely client-side JS, so the only server-testable proof is
     * the private $_hideifs registry MoodleQuickForm::hideIf() writes into (formslib.php).
     *
     * @param \MoodleQuickForm $mform The form to inspect.
     * @param string $elementname The element expected to be hidden.
     * @param string $dependenton The field the visibility depends on.
     * @param string $condition The hideIf condition (e.g. 'eq').
     * @param string $value The value that triggers hiding.
     * @return void
     */
    private function assert_hideif_registered(
        \MoodleQuickForm $mform,
        string $elementname,
        string $dependenton,
        string $condition,
        string $value
    ): void {
        $refclass = new \ReflectionClass($mform);
        $prop = $refclass->getProperty('_hideifs');
        $prop->setAccessible(true);
        $hideifs = $prop->getValue($mform);

        $this->assertArrayHasKey($dependenton, $hideifs);
        $this->assertArrayHasKey($condition, $hideifs[$dependenton]);
        $this->assertArrayHasKey($value, $hideifs[$dependenton][$condition]);
        $this->assertContains($elementname, $hideifs[$dependenton][$condition][$value]);
    }

    /**
     * Tests that initial_repeat_count() never goes below 5, matching core's own
     * multichoice edit form default.
     *
     * @return void
     */
    public function test_initial_repeat_count_defaults_to_five(): void {
        $this->assertSame(5, question_form::initial_repeat_count(0));
        $this->assertSame(5, question_form::initial_repeat_count(3));
    }

    /**
     * Tests that initial_repeat_count() grows to show every existing answer when a
     * question being edited already has more than 5.
     *
     * @return void
     */
    public function test_initial_repeat_count_grows_for_existing_answers(): void {
        $this->assertSame(7, question_form::initial_repeat_count(7));
    }

    /**
     * Tests that a brand new question renders exactly 5 multichoice option slots.
     *
     * @return void
     */
    public function test_renders_five_option_slots_for_a_new_question(): void {
        $mform = $this->build_form(0);

        $this->assertTrue($mform->elementExists('optiontext_editor[5]'));
        $this->assertFalse($mform->elementExists('optiontext_editor[6]'));
    }

    /**
     * Tests that editing a question with more than 5 existing answers renders enough
     * slots to show all of them.
     *
     * @return void
     */
    public function test_renders_enough_option_slots_for_existing_answers(): void {
        $mform = $this->build_form(8);

        $this->assertTrue($mform->elementExists('optiontext_editor[8]'));
        $this->assertFalse($mform->elementExists('optiontext_editor[9]'));
    }

    /**
     * Tests that the True/False radio hides for Multichoice, and the Multichoice-only
     * elements (option adder, correct-answer hint, mccorrect radio) hide for True/False.
     *
     * @return void
     */
    public function test_qtype_specific_elements_hide_for_the_other_type(): void {
        $mform = $this->build_form();

        $this->assert_hideif_registered($mform, 'tfcorrect', 'qtype', 'eq', 'multichoice');
        $this->assert_hideif_registered($mform, 'optiontext_editor[1]', 'qtype', 'eq', 'truefalse');
        $this->assert_hideif_registered($mform, 'mccorrect', 'qtype', 'eq', 'truefalse');
        $this->assert_hideif_registered($mform, 'option_add_fields', 'qtype', 'eq', 'truefalse');
        $this->assert_hideif_registered($mform, 'mccorrecthint', 'qtype', 'eq', 'truefalse');
    }

    /**
     * Tests that validation rejects an empty question text.
     *
     * @return void
     */
    public function test_validation_requires_questiontext(): void {
        $form = $this->build_form_object();

        $errors = $form->validation([
            'qtype' => 'truefalse',
            'questiontext_editor' => ['text' => '', 'format' => FORMAT_HTML],
            'optiontext_editor' => [],
            'mccorrect' => 1,
        ], []);

        $this->assertArrayHasKey('questiontext_editor', $errors);
    }

    /**
     * Tests that an embedded image with no caption text still counts as real content —
     * editor_text_is_empty() must not treat it as blank.
     *
     * @return void
     */
    public function test_questiontext_with_only_an_image_is_not_empty(): void {
        $form = $this->build_form_object();

        $errors = $form->validation([
            'qtype' => 'truefalse',
            'questiontext_editor' => ['text' => '<img src="x.png">', 'format' => FORMAT_HTML],
            'optiontext_editor' => [],
            'mccorrect' => 1,
        ], []);

        $this->assertArrayNotHasKey('questiontext_editor', $errors);
    }

    /**
     * Tests that a valid True/False submission passes validation cleanly.
     *
     * @return void
     */
    public function test_validation_passes_for_valid_truefalse(): void {
        $form = $this->build_form_object();

        $errors = $form->validation([
            'qtype' => 'truefalse',
            'questiontext_editor' => ['text' => 'Is the sky blue?', 'format' => FORMAT_HTML],
            'optiontext_editor' => [],
            'mccorrect' => 1,
        ], []);

        $this->assertSame([], $errors);
    }

    /**
     * Tests that Multichoice validation requires at least two filled options.
     *
     * @return void
     */
    public function test_validation_requires_at_least_two_multichoice_options(): void {
        $form = $this->build_form_object();

        $errors = $form->validation([
            'qtype' => 'multichoice',
            'questiontext_editor' => ['text' => 'Pick one', 'format' => FORMAT_HTML],
            'optiontext_editor' => [
                1 => ['text' => 'Only option', 'format' => FORMAT_HTML],
                2 => ['text' => '', 'format' => FORMAT_HTML],
            ],
            'mccorrect' => 1,
        ], []);

        $this->assertArrayHasKey('optiontext_editor[1]', $errors);
    }

    /**
     * Tests that Multichoice validation rejects a submission whose marked-correct option
     * is itself empty, even when enough other options are filled.
     *
     * @return void
     */
    public function test_validation_rejects_empty_marked_correct_option(): void {
        $form = $this->build_form_object();

        $errors = $form->validation([
            'qtype' => 'multichoice',
            'questiontext_editor' => ['text' => 'Pick one', 'format' => FORMAT_HTML],
            'optiontext_editor' => [
                1 => ['text' => '', 'format' => FORMAT_HTML],
                2 => ['text' => 'Second option', 'format' => FORMAT_HTML],
                3 => ['text' => 'Third option', 'format' => FORMAT_HTML],
            ],
            'mccorrect' => 1,
        ], []);

        $this->assertArrayHasKey('optiontext_editor[1]', $errors);
    }

    /**
     * Tests that a valid Multichoice submission passes validation cleanly.
     *
     * @return void
     */
    public function test_validation_passes_for_valid_multichoice(): void {
        $form = $this->build_form_object();

        $errors = $form->validation([
            'qtype' => 'multichoice',
            'questiontext_editor' => ['text' => 'Pick one', 'format' => FORMAT_HTML],
            'optiontext_editor' => [
                1 => ['text' => 'First option', 'format' => FORMAT_HTML],
                2 => ['text' => 'Second option', 'format' => FORMAT_HTML],
            ],
            'mccorrect' => 1,
        ], []);

        $this->assertSame([], $errors);
    }
}
