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
 * Unit tests for mod_playerpuzzle_mod_form's PlayerHUD integration section.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for mod_playerpuzzle_mod_form.
 *
 * @covers \mod_playerpuzzle_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Skips the current test when block_playerhud is not installed.
     *
     * @return void
     */
    private function skip_if_no_playerhud(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('block_playerhud_items')) {
            $this->markTestSkipped('block_playerhud not installed.');
        }
    }

    /**
     * Inserts a block_playerhud block instance in the course, with one enabled item.
     *
     * @return int Block instance ID.
     */
    private function make_hud_block_with_item(): int {
        global $DB;

        $ctx = \context_course::instance($this->course->id);
        $biid = $DB->insert_record('block_instances', (object) [
            'blockname'         => 'playerhud',
            'parentcontextid'   => $ctx->id,
            'showinsubcontexts' => 0,
            'pagetypepattern'   => 'course-view-*',
            'subpagepattern'    => null,
            'defaultregion'     => 'side-pre',
            'defaultweight'     => 0,
            'configdata'        => base64_encode(serialize(new \stdClass())),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
        $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $biid,
            'name'            => 'Gold Coin',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 1,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        return $biid;
    }

    /**
     * Instantiates mod_playerpuzzle_mod_form for an existing instance, returning the
     * underlying MoodleQuickForm so the test can inspect elements/options.
     *
     * @param \stdClass $instance Activity instance record.
     * @param \stdClass $cm Course module record.
     * @return \MoodleQuickForm
     */
    private function build_form(\stdClass $instance, \stdClass $cm): \MoodleQuickForm {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/playerpuzzle/mod_form.php');

        $PAGE->set_course($this->course);

        // The private add_stale_hud_item_option() reads $this->current->{$field}, so the
        // real instance fields (hud_coin_item included) must be present, not just the
        // bare instance/id/course triplet moodleform_mod strictly requires.
        $data = clone $instance;
        $data->instance = $instance->id;
        $data->id = $cm->id;
        $data->course = $this->course->id;

        $form = new \mod_playerpuzzle_mod_form($data, 0, $cm, $this->course);

        $refclass = new \ReflectionClass(\mod_playerpuzzle_mod_form::class);
        $formprop = $refclass->getProperty('_form');
        $formprop->setAccessible(true);

        return $formprop->getValue($form);
    }

    /**
     * Instantiates mod_playerpuzzle_mod_form for an existing instance, returning the form
     * object itself rather than just its underlying MoodleQuickForm — needed to call the
     * form's own public validation() method, which reads $this->_form internally and
     * therefore cannot be invoked on an instance built via
     * ReflectionClass::newInstanceWithoutConstructor().
     *
     * @param \stdClass $instance Activity instance record.
     * @param \stdClass $cm Course module record.
     * @return \mod_playerpuzzle_mod_form
     */
    private function build_form_object(\stdClass $instance, \stdClass $cm): \mod_playerpuzzle_mod_form {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/playerpuzzle/mod_form.php');

        $PAGE->set_course($this->course);

        $data = clone $instance;
        $data->instance = $instance->id;
        $data->id = $cm->id;
        $data->course = $this->course->id;

        return new \mod_playerpuzzle_mod_form($data, 0, $cm, $this->course);
    }

    /**
     * Reads the option values registered on a select element, via reflection —
     * HTML_QuickForm_select exposes no public accessor for its own options array.
     *
     * @param \HTML_QuickForm_select $element The select element.
     * @return string[] Option values in display order.
     */
    private function get_select_option_values(\HTML_QuickForm_select $element): array {
        $refclass = new \ReflectionClass($element);
        $optionsprop = $refclass->getProperty('_options');
        $optionsprop->setAccessible(true);

        return array_map(
            static fn(array $option): string => (string) $option['attr']['value'],
            $optionsprop->getValue($element)
        );
    }

    /**
     * Asserts that hideIf($elementname, $dependenton, $condition, $value) was registered
     * on the form. hideIf is purely client-side JS — the element always exists server-side
     * regardless of the current field value — so the only server-testable proof that the
     * dependency exists at all is the private $_hideifs registry MoodleQuickForm::hideIf()
     * writes into (formslib.php).
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
     * Asserts that disabledIf($elementname, $dependenton, $condition, $value) was
     * registered on the form — same rationale as assert_hideif_registered(), but reading
     * MoodleQuickForm's own $_dependencies registry instead.
     *
     * @param \MoodleQuickForm $mform The form to inspect.
     * @param string $elementname The element expected to be disabled.
     * @param string $dependenton The field the disabled state depends on.
     * @param string $condition The disabledIf condition (e.g. 'eq').
     * @param string $value The value that triggers disabling.
     * @return void
     */
    private function assert_disabledif_registered(
        \MoodleQuickForm $mform,
        string $elementname,
        string $dependenton,
        string $condition,
        string $value
    ): void {
        $refclass = new \ReflectionClass($mform);
        $prop = $refclass->getProperty('_dependencies');
        $prop->setAccessible(true);
        $dependencies = $prop->getValue($mform);

        $this->assertArrayHasKey($dependenton, $dependencies);
        $this->assertArrayHasKey($condition, $dependencies[$dependenton]);
        $this->assertArrayHasKey($value, $dependencies[$dependenton][$condition]);
        $this->assertContains($elementname, $dependencies[$dependenton][$condition][$value]);
    }

    /**
     * Tests that the form definition does not fatal for a plain instance and that the
     * PlayerHUD section is absent when no block_playerhud instance exists in the
     * course.
     *
     * @return void
     */
    public function test_hud_elements_absent_without_playerhud_block(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $this->assertFalse($mform->elementExists('hud_coin_item'));
        $this->assertFalse($mform->elementExists('hud_sword_item'));
        $this->assertFalse($mform->elementExists('hud_shield_item'));
    }

    /**
     * Tests that the PlayerHUD section appears, with the configured item selectable,
     * once a block_playerhud instance exists in the course.
     *
     * @return void
     */
    public function test_hud_elements_appear_with_playerhud_block(): void {
        $this->skip_if_no_playerhud();
        $this->make_hud_block_with_item();

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $this->assertTrue($mform->elementExists('hud_coin_item'));
        $this->assertTrue($mform->elementExists('hud_sword_item'));
        $this->assertTrue($mform->elementExists('hud_shield_item'));
    }

    /**
     * Tests that an item configured on a past instance keeps showing as a selectable
     * option even after it stops being enabled — add_stale_hud_item_option() must
     * never silently drop the stored value from the select.
     *
     * @return void
     */
    public function test_stale_hud_item_option_preserved(): void {
        global $DB;

        $this->skip_if_no_playerhud();
        $biid = $this->make_hud_block_with_item();
        $staleitemid = $DB->insert_record('block_playerhud_items', (object) [
            'blockinstanceid' => $biid,
            'name'            => 'Retired Coin',
            'xp'              => 0,
            'image'           => '',
            'description'     => '',
            'enabled'         => 0,
            'secret'          => 0,
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course'        => $this->course->id,
            'hud_coin_item' => $staleitemid,
        ]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $element = $mform->getElement('hud_coin_item');
        $values = $this->get_select_option_values($element);

        $this->assertContains((string) $staleitemid, $values);
    }

    /**
     * Tests that gamemode defaults to Campaign — preserves the behaviour documented
     * before the two-mode split existed.
     *
     * @return void
     */
    public function test_gamemode_defaults_to_campaign(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $this->assertSame(['campaign'], $mform->getElement('gamemode')->getValue());
    }

    /**
     * Tests that the Campaign-only fields (levels/phases header, maxlevels,
     * maxattempts) are registered to hide when gamemode is Single Match.
     *
     * @return void
     */
    public function test_campaign_only_fields_hide_in_single_match_mode(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $this->assert_hideif_registered($mform, 'levelsandphases', 'gamemode', 'eq', 'single');
        $this->assert_hideif_registered($mform, 'maxlevels', 'gamemode', 'eq', 'single');
        $this->assert_hideif_registered($mform, 'maxattempts', 'gamemode', 'eq', 'single');
    }

    /**
     * Tests that the Single Match-only fields (its header, max_single_matches,
     * grademethod) are registered to hide when gamemode is Campaign.
     *
     * @return void
     */
    public function test_single_match_only_fields_hide_in_campaign_mode(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $this->assert_hideif_registered($mform, 'singlematchheader', 'gamemode', 'eq', 'campaign');
        $this->assert_hideif_registered($mform, 'max_single_matches', 'gamemode', 'eq', 'campaign');
        $this->assert_hideif_registered($mform, 'grademethod', 'gamemode', 'eq', 'campaign');
    }

    /**
     * Tests that minquestions defaults to 3 — opt-out, not opt-in: a teacher who never
     * touches this setting still gets a minimum content-knowledge check by default.
     *
     * @return void
     */
    public function test_minquestions_defaults_to_three(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $this->assertSame([3], $mform->getElement('minquestions')->getValue());
    }

    /**
     * Tests that considererrors is registered to disable when minquestions is 0.
     *
     * @return void
     */
    public function test_considererrors_disables_when_minquestions_zero(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $this->assert_disabledif_registered($mform, 'considererrors', 'minquestions', 'eq', '0');
    }

    /**
     * Tests that the retry-cost and win-grant quantity fields are registered to hide
     * when their respective item is left unconfigured (id 0).
     *
     * @return void
     */
    public function test_hud_quantity_fields_hide_when_item_unconfigured(): void {
        $this->skip_if_no_playerhud();
        $this->make_hud_block_with_item();

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);

        $mform = $this->build_form($instance, $cm);

        $this->assert_hideif_registered($mform, 'hud_retry_cost_qty', 'hud_retry_cost_item', 'eq', '0');
        $this->assert_hideif_registered($mform, 'hud_win_grant_qty', 'hud_win_grant_item', 'eq', '0');
    }

    /**
     * Tests that saving with a configured retry-cost item but a quantity below 1 is
     * rejected — the PlayerHUD equivalent to PlayerWords' own error_hud_cost_qty check.
     *
     * @return void
     */
    public function test_validation_rejects_zero_hud_retry_cost_qty_when_item_configured(): void {
        $this->skip_if_no_playerhud();
        $biid = $this->make_hud_block_with_item();

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $formobj = $this->build_form_object($instance, $cm);

        $items = \mod_playerpuzzle\local\hud_service::get_items_for_block($biid);
        $data = (array) $instance;
        $data['name'] = $instance->name;
        $data['modulename'] = 'playerpuzzle';
        $data['instance'] = $instance->id;
        $data['coursemodule'] = $cm->id;
        $data['availabilityconditionsjson'] = '';
        $data['cmidnumber'] = '';
        $data['hud_retry_cost_item'] = $items[0]->id;
        $data['hud_retry_cost_qty'] = 0;

        $errors = $formobj->validation($data, []);

        $this->assertArrayHasKey('hud_retry_cost_qty', $errors);
    }

    /**
     * Tests that saving with a configured win-grant item but a quantity below 1 is
     * rejected.
     *
     * @return void
     */
    public function test_validation_rejects_zero_hud_win_grant_qty_when_item_configured(): void {
        $this->skip_if_no_playerhud();
        $biid = $this->make_hud_block_with_item();

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $formobj = $this->build_form_object($instance, $cm);

        $items = \mod_playerpuzzle\local\hud_service::get_items_for_block($biid);
        $data = (array) $instance;
        $data['name'] = $instance->name;
        $data['modulename'] = 'playerpuzzle';
        $data['instance'] = $instance->id;
        $data['coursemodule'] = $cm->id;
        $data['availabilityconditionsjson'] = '';
        $data['cmidnumber'] = '';
        $data['hud_win_grant_item'] = $items[0]->id;
        $data['hud_win_grant_qty'] = 0;

        $errors = $formobj->validation($data, []);

        $this->assertArrayHasKey('hud_win_grant_qty', $errors);
    }
}
