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
 * Tests that production code loads the core libraries it uses.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

/**
 * The PHPUnit bootstrap already has the gradebook and completion libraries loaded, so a missing
 * require_once never fails a test, but a real web service request only has them when something else
 * happens to bring them in (the gradebook is not touched when the activity has grade type "None").
 * A class in classes/ that uses one of them must therefore load it itself.
 *
 * @coversNothing
 */
final class core_libraries_test extends \advanced_testcase {
    /**
     * The production PHP files under classes/ that run on a web service request. The completion and
     * privacy folders are called by the core subsystems, which load what they need themselves.
     *
     * @return string[] Paths relative to the plugin root.
     */
    private function production_files(): array {
        global $CFG;

        $root = $CFG->dirroot . '/mod/playerpuzzle/';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'classes')) as $file) {
            $relative = substr($file->getPathname(), strlen($root));
            if (
                $file->isFile()
                && substr($relative, -4) === '.php'
                && !str_starts_with($relative, 'classes/completion/')
                && !str_starts_with($relative, 'classes/privacy/')
            ) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Every file that uses the completion library loads it.
     *
     * @return void
     */
    public function test_files_using_completion_info_load_completionlib(): void {
        global $CFG;

        $missing = [];
        foreach ($this->production_files() as $relative) {
            $source = file_get_contents($CFG->dirroot . '/mod/playerpuzzle/' . $relative);
            if (
                preg_match('/\bnew\s+completion_info\b|\bCOMPLETION_[A-Z_]+\b/', $source)
                && !str_contains($source, 'completionlib.php')
            ) {
                $missing[] = $relative;
            }
        }

        $this->assertSame([], $missing, 'These files use the completion library without loading it.');
    }

    /**
     * Every file that uses the gradebook library loads it.
     *
     * @return void
     */
    public function test_files_using_the_gradebook_load_gradelib(): void {
        global $CFG;

        $missing = [];
        foreach ($this->production_files() as $relative) {
            $source = file_get_contents($CFG->dirroot . '/mod/playerpuzzle/' . $relative);
            if (
                preg_match('/\bgrade_update\(|\bGRADE_(TYPE|UPDATE)_[A-Z_]+\b|\bgrade_(item|grade)::/', $source)
                && !str_contains($source, 'gradelib.php')
            ) {
                $missing[] = $relative;
            }
        }

        $this->assertSame([], $missing, 'These files use the gradebook library without loading it.');
    }
}
