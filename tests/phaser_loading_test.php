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
 * Regression guard for the Phaser-loading race with core_message/message_drawer.js.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle;

/**
 * The bug this guards against (SCOPE.md §17) is a client-side timing race, not something
 * that can be reproduced deterministically in PHPUnit (server-side) or reliably in Behat
 * (depends on RequireJS module cache state at the time of the run). The only defensible
 * automated guard is asserting the structural invariant that prevents the race in the
 * first place: play.php must never queue phaser.min.js as a static <script> via
 * $PAGE->requires->js(), and game_boot.js must load it dynamically instead (mirrors
 * filter_mathjaxloader's loadMathJax() pattern, verified live against a real browser
 * before this test was written).
 *
 * @coversNothing
 */
final class phaser_loading_test extends \basic_testcase {
    /**
     * play.php must never queue phaser.min.js as a static <script> tag. A static tag
     * there sits in the page footer and can race core_message/message_drawer.js, which
     * expects its own drawer markup (rendered further down the same footer) to already
     * be in the DOM by the time its require() callback runs.
     *
     * @return void
     */
    public function test_play_php_does_not_queue_phaser_as_static_script(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/mod/playerpuzzle/play.php');

        $this->assertDoesNotMatchRegularExpression(
            '/requires->js\(\s*new moodle_url\([\'"]\/mod\/playerpuzzle\/javascript\/phaser\.min\.js[\'"]\)/',
            $source
        );
    }

    /**
     * game_boot.js must load Phaser dynamically (a <script> created and appended via JS,
     * resolved through its onload event) rather than assuming a PHP-queued <script> tag
     * already exists on the page by the time init() runs.
     *
     * @return void
     */
    public function test_game_boot_loads_phaser_dynamically(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/mod/playerpuzzle/amd/src/game_boot.js');

        $this->assertMatchesRegularExpression('/document\.createElement\([\'"]script[\'"]\)/', $source);
        $this->assertMatchesRegularExpression('/script\.onload\s*=/', $source);
    }
}
