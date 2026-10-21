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
 * Tests for the hygiene of db/install.xml.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * Tests for db/install.xml.
 *
 * @coversNothing
 */
final class install_xml_test extends \advanced_testcase {
    /**
     * The schema must not name a constant, table or component of a sibling plugin: the file is
     * started from another plugin's, and a comment that still points there sends whoever reads the
     * XMLDB editor to look for a definition that does not exist here.
     *
     * @return void
     */
    public function test_comments_do_not_point_at_a_sibling_plugin(): void {
        global $CFG;

        $xml = file_get_contents($CFG->dirroot . '/mod/playerpuzzle/db/install.xml');

        $this->assertDoesNotMatchRegularExpression(
            '/player(?:words|cross|cards|land|raid|video|group)_/i',
            $xml
        );
    }
}
