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
}
