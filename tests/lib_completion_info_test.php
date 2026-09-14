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
 * Tests for playerpuzzle_get_coursemodule_info() and
 * playerpuzzle_get_completion_active_rule_descriptions() in lib.php.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for playerpuzzle_get_coursemodule_info() and
 * playerpuzzle_get_completion_active_rule_descriptions().
 *
 * @covers ::playerpuzzle_get_coursemodule_info
 * @covers ::playerpuzzle_get_completion_active_rule_descriptions
 */
final class lib_completion_info_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Tests that customdata is populated with both rule thresholds when completion
     * tracking is automatic.
     *
     * @return void
     */
    public function test_get_coursemodule_info_populates_customdata_when_automatic(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course'             => $course->id,
            'completionattempts' => 4,
            'completionwins'     => 2,
        ]);

        $coursemodule = (object) [
            'instance'   => $instance->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ];

        $info = playerpuzzle_get_coursemodule_info($coursemodule);

        $this->assertNotFalse($info);
        $this->assertSame(4, $info->customdata['customcompletionrules']['completionattempts']);
        $this->assertSame(2, $info->customdata['customcompletionrules']['completionwins']);
    }

    /**
     * Tests that customdata stays empty when completion tracking is manual/none — the
     * rules would be meaningless without automatic tracking to evaluate them.
     *
     * @return void
     */
    public function test_get_coursemodule_info_skips_customdata_when_not_automatic(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance([
            'course'             => $course->id,
            'completionattempts' => 4,
        ]);

        $coursemodule = (object) [
            'instance'   => $instance->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ];

        $info = playerpuzzle_get_coursemodule_info($coursemodule);

        $this->assertNotFalse($info);
        $this->assertNull($info->customdata);
    }

    /**
     * Tests that a missing instance returns false rather than fataling.
     *
     * @return void
     */
    public function test_get_coursemodule_info_returns_false_for_missing_instance(): void {
        $coursemodule = (object) ['instance' => 0, 'completion' => COMPLETION_TRACKING_AUTOMATIC];

        $this->assertFalse(playerpuzzle_get_coursemodule_info($coursemodule));
    }

    /**
     * Tests that both descriptions appear when both rules are configured.
     *
     * @return void
     */
    public function test_get_completion_active_rule_descriptions_includes_both_rules(): void {
        $cm = (object) [
            'customdata' => [
                'customcompletionrules' => [
                    'completionattempts' => 3,
                    'completionwins'     => 1,
                ],
            ],
        ];

        $descriptions = playerpuzzle_get_completion_active_rule_descriptions($cm);

        $this->assertCount(2, $descriptions);
        $this->assertStringContainsString('3', $descriptions[0]);
        $this->assertStringContainsString('1', $descriptions[1]);
    }

    /**
     * Tests that a rule left at 0 (disabled) produces no description.
     *
     * @return void
     */
    public function test_get_completion_active_rule_descriptions_skips_disabled_rules(): void {
        $cm = (object) [
            'customdata' => [
                'customcompletionrules' => [
                    'completionattempts' => 0,
                    'completionwins'     => 0,
                ],
            ],
        ];

        $this->assertSame([], playerpuzzle_get_completion_active_rule_descriptions($cm));
    }

    /**
     * Tests that a cm with no customdata at all (completion tracking off) produces no
     * descriptions, rather than an undefined-index notice.
     *
     * @return void
     */
    public function test_get_completion_active_rule_descriptions_handles_missing_customdata(): void {
        $cm = (object) ['customdata' => []];

        $this->assertSame([], playerpuzzle_get_completion_active_rule_descriptions($cm));
    }
}
