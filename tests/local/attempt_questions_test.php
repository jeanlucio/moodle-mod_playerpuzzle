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
 * Unit tests for the per-attempt answered-question log.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for attempt_questions.
 *
 * @covers \mod_playerpuzzle\local\attempt_questions
 */
final class attempt_questions_test extends \advanced_testcase {
    /**
     * Records three answers across two phases and checks get_phase_log returns only the
     * asked phase's rows, oldest first, in the template shape.
     *
     * @return void
     */
    public function test_record_and_get_phase_log(): void {
        $this->resetAfterTest();

        attempt_questions::record(5, 100, 2, 3, '<p>Q1</p>', 'A', 'A', true);
        attempt_questions::record(5, 101, 2, 3, '<p>Q2</p>', 'B', 'C', false);
        attempt_questions::record(5, 102, 2, 4, '<p>Q3</p>', 'D', 'D', true);
        // A different attempt, same phase — must not bleed in.
        attempt_questions::record(9, 200, 2, 3, '<p>X</p>', 'Z', 'Z', true);

        $log = attempt_questions::get_phase_log(5, 2, 3);

        $this->assertCount(2, $log);
        $this->assertSame('<p>Q1</p>', $log[0]['questiontext']);
        $this->assertTrue($log[0]['iscorrect']);
        $this->assertSame('B', $log[1]['chosenanswer']);
        $this->assertSame('C', $log[1]['correctanswer']);
        $this->assertFalse($log[1]['iscorrect']);

        $this->assertCount(1, attempt_questions::get_phase_log(5, 2, 4));
        $this->assertCount(0, attempt_questions::get_phase_log(5, 1, 1));
    }
}
