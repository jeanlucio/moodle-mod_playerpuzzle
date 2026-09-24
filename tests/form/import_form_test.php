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
 * Unit tests for import_form.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\form;

/**
 * Tests for the "Import from question bank" category picker.
 *
 * @covers \mod_playerpuzzle\form\import_form
 */
final class import_form_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Instantiates import_form with the given categories, returning the underlying
     * MoodleQuickForm so the test can inspect elements/options.
     *
     * @param \stdClass[] $categories Category records (id, name).
     * @return \MoodleQuickForm
     */
    private function build_form(array $categories): \MoodleQuickForm {
        $form = new import_form(null, ['categories' => $categories]);

        $refclass = new \ReflectionClass($form);
        $prop = $refclass->getProperty('_form');
        $prop->setAccessible(true);

        return $prop->getValue($form);
    }

    /**
     * Reads the value => label pairs registered on a select element, via reflection —
     * HTML_QuickForm_select exposes no public accessor for its own options array.
     *
     * @param \HTML_QuickForm_select $element The select element.
     * @return array Value => label, in display order.
     */
    private function get_select_options(\HTML_QuickForm_select $element): array {
        $refclass = new \ReflectionClass($element);
        $optionsprop = $refclass->getProperty('_options');
        $optionsprop->setAccessible(true);

        $pairs = [];
        foreach ($optionsprop->getValue($element) as $option) {
            $pairs[(string) $option['attr']['value']] = $option['text'];
        }

        return $pairs;
    }

    /**
     * Tests that the category select is populated with exactly the given categories, in
     * the same order, with names passed through format_string().
     *
     * @return void
     */
    public function test_categoryid_options_match_given_categories(): void {
        $categories = [
            (object) ['id' => 5, 'name' => 'Category A'],
            (object) ['id' => 9, 'name' => 'Category B'],
        ];

        $mform = $this->build_form($categories);

        $this->assertTrue($mform->elementExists('categoryid'));
        $this->assertSame(
            ['5' => 'Category A', '9' => 'Category B'],
            $this->get_select_options($mform->getElement('categoryid'))
        );
    }

    /**
     * Tests that the category select renders with no options at all when there is no
     * importable category — managequestions.php still builds the form unconditionally in
     * that case, only skipping get_data() handling.
     *
     * @return void
     */
    public function test_categoryid_select_is_empty_without_categories(): void {
        $mform = $this->build_form([]);

        $this->assertTrue($mform->elementExists('categoryid'));
        $this->assertSame([], $this->get_select_options($mform->getElement('categoryid')));
    }

    /**
     * Tests that the static notice and the custom-labelled submit button are present.
     *
     * @return void
     */
    public function test_notice_and_submit_button_present(): void {
        $mform = $this->build_form([(object) ['id' => 1, 'name' => 'Category A']]);

        $this->assertTrue($mform->elementExists('importnotice'));
        $this->assertTrue($mform->elementExists('submitbutton'));
        $this->assertSame(
            get_string('importbutton', 'mod_playerpuzzle'),
            $mform->getElement('submitbutton')->getValue()
        );
    }
}
