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
 * Tests for the plain text helper.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for the plain text helper.
 *
 * @covers \mod_playerpuzzle\local\text
 */
final class text_test extends \advanced_testcase {
    /**
     * Tests that the result is the same plain text whatever the formatstringstriptags setting says.
     *
     * @return void
     */
    public function test_plain_string_does_not_depend_on_the_striptags_setting(): void {
        $this->resetAfterTest();

        foreach ([1, 0] as $striptags) {
            set_config('formatstringstriptags', $striptags);
            \core\di::reset_container();

            $this->assertSame('Q & A > 3', text::plain_string('Q & A > 3'), "striptags={$striptags}");
            $this->assertSame('Q & A', text::plain_string('<b>Q</b> &amp; A'), "striptags={$striptags}");
            $this->assertSame('', text::plain_string(null), "striptags={$striptags}");
            $this->assertSame('', text::plain_string(''), "striptags={$striptags}");
        }
    }
}
