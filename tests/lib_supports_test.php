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
 * Tests for the playerpuzzle_supports callback in lib.php.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for playerpuzzle_supports() — no database access needed.
 *
 * @covers ::playerpuzzle_supports
 */
final class lib_supports_test extends \basic_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Known features return their declared support value, and an unrecognised feature
     * returns null. Asserts the honest current state of the plugin — backup is declared
     * unsupported until its real implementation lands, not the aspirational end state.
     * Gradebook support (Fase 6 Lote A) and completion rules (Fase 6 Lote C) are both real:
     * playerpuzzle_grade_item_update()/update_grades()/grade_calculator, and
     * playerpuzzle_get_coursemodule_info()/classes/completion/custom_completion.php, all exist.
     *
     * @return void
     */
    public function test_supports_known_features(): void {
        $this->assertTrue(playerpuzzle_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(playerpuzzle_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertTrue(playerpuzzle_supports(FEATURE_GROUPS));
        $this->assertTrue(playerpuzzle_supports(FEATURE_GROUPINGS));
        $this->assertTrue(playerpuzzle_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(playerpuzzle_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertFalse(playerpuzzle_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertNull(playerpuzzle_supports('unknown_feature'));
    }
}
