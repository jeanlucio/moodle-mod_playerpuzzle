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
 * Tests for db/access.php's capability definitions.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests capability metadata declared in db/access.php — a plain data file, not a class, so
 * there is no production class to attribute coverage to.
 *
 * @covers \mod_playerpuzzle\access_test
 */
final class access_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that mod/playerpuzzle:managequestions declares RISK_SPAM — the content it
     * unlocks authoring of (question/answer text) is shown to every student, but is always
     * cleaned on output (question_editor_files::editor_options()'s noclean => false,
     * question_fetcher::format_with_files()'s plain format_text() call), so RISK_XSS would
     * be the wrong risk to claim here — unlike moodle/question:add, which genuinely renders
     * with noclean => true. Missing riskbitmask entirely left the role-definition screen
     * with no warning at all for this capability (security audit finding #3,
     * moodle-security-audit, 2026-09-18).
     *
     * @return void
     */
    public function test_managequestions_declares_risk_spam(): void {
        $capability = get_capability_info('mod/playerpuzzle:managequestions');

        $this->assertNotNull($capability);
        $this->assertSame(RISK_SPAM, (int) $capability->riskbitmask);
    }
}
