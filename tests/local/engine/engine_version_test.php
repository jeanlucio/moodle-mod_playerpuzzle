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

namespace mod_playerpuzzle\local\engine;

/**
 * Tests that the combat rules never change without engine_version moving with them.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_playerpuzzle\local\engine\engine_version
 */
final class engine_version_test extends \advanced_testcase {
    /** @var string[] The files a replay depends on, from the plugin's root. */
    private const RULE_FILES = [
        'amd/src/engine/board_rules.js',
        'amd/src/engine/combat_rules.js',
        'amd/src/engine/prng.js',
        'classes/local/engine/board_engine.php',
        'classes/local/engine/combat.php',
        'classes/local/engine/combat_engine.php',
        'classes/local/engine/prng.php',
        'classes/local/engine/replay.php',
        'classes/local/opponents.php',
    ];

    /**
     * The rule files' code without comments and spacing, so editing a comment does not count as a change of rules.
     *
     * @return string A SHA-1 of the code.
     */
    private static function fingerprint(): string {
        global $CFG;

        $code = '';
        foreach (self::RULE_FILES as $file) {
            $path = $CFG->dirroot . '/mod/playerpuzzle/' . $file;
            if (str_ends_with($file, '.php')) {
                // The text of every token but comments and spacing, which reads the same on every PHP version.
                foreach (token_get_all(file_get_contents($path)) as $token) {
                    if (!is_array($token)) {
                        $code .= $token;
                    } else if (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                        $code .= $token[1] . ' ';
                    }
                }
                continue;
            }
            $source = preg_replace('~/\*.*?\*/~s', '', file_get_contents($path));
            $source = preg_replace('~(^|\s)//.*$~m', '$1', $source);
            $code .= preg_replace('~\s+~', ' ', $source);
        }

        return sha1($code);
    }

    /**
     * The versions the rules have had, oldest first, each with the fingerprint of its code.
     *
     * @return array[] Each ['version' => int, 'fingerprint' => string].
     */
    private static function history(): array {
        global $CFG;

        return json_decode(file_get_contents($CFG->dirroot . '/mod/playerpuzzle/tests/fixtures/engine_versions.json'), true);
    }

    /**
     * Tests that the latest version on record is the one the code runs, and that every version on record is newer
     * than the one before, so a change of rules cannot be recorded under a version already used.
     *
     * @return void
     */
    public function test_the_history_ends_on_the_current_version(): void {
        $versions = array_column(self::history(), 'version');

        $this->assertSame(engine_version::CURRENT, end($versions));
        $sorted = $versions;
        sort($sorted);
        $this->assertSame($sorted, $versions, 'the versions are in order');
        $this->assertSame(count($versions), count(array_unique($versions)), 'no version is used twice');
    }

    /**
     * Tests that the rule files are the ones the current version was recorded with. When they changed, raise
     * engine_version::CURRENT and add the new version with the fingerprint this test prints to
     * tests/fixtures/engine_versions.json.
     *
     * @return void
     */
    public function test_the_rules_did_not_change_under_the_current_version(): void {
        $history = self::history();
        $fingerprint = self::fingerprint();

        $this->assertSame(
            end($history)['fingerprint'],
            $fingerprint,
            "The combat rules changed. Raise engine_version::CURRENT, so that a phase under way restarts instead of "
                . "being replayed under rules it was not played with, and add {\"version\": <the new version>, "
                . "\"fingerprint\": \"$fingerprint\"} to tests/fixtures/engine_versions.json."
        );
    }
}
