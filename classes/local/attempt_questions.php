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
 * Recording and reading of the per-attempt answered-question log.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Stores one row per question a student answers during an attempt, and reads it back for
 * the post-game review. Only the student's own answers are recorded — the boss's guesses
 * have no consumer here.
 */
class attempt_questions {
    /**
     * Records one answered question. Answer/question text are stored as a snapshot so the
     * review still reads correctly if the source question is later edited or deleted.
     *
     * @param int $attemptid The playerpuzzle_attempts row id.
     * @param int $questionid The Moodle question bank id.
     * @param int $level Level the question was answered on.
     * @param int $phase Phase the question was answered on.
     * @param string $questiontext Formatted question text.
     * @param string $chosenanswer Formatted text of the chosen answer.
     * @param string $correctanswer Formatted text of the correct answer.
     * @param bool $iscorrect Whether the chosen answer was correct.
     * @return void
     */
    public static function record(
        int $attemptid,
        int $questionid,
        int $level,
        int $phase,
        string $questiontext,
        string $chosenanswer,
        string $correctanswer,
        bool $iscorrect
    ): void {
        global $DB;

        $DB->insert_record('playerpuzzle_attempt_questions', (object) [
            'attemptid'     => $attemptid,
            'questionid'    => $questionid,
            'attemptlevel'  => $level,
            'attemptphase'  => $phase,
            'questiontext'  => $questiontext,
            'chosenanswer'  => $chosenanswer,
            'correctanswer' => $correctanswer,
            'iscorrect'     => $iscorrect ? 1 : 0,
            'timecreated'   => time(),
        ]);
    }

    /**
     * Returns the answered-question rows for one phase of an attempt, oldest first, shaped
     * for the review template.
     *
     * @param int $attemptid The attempt id.
     * @param int $level Level to filter to.
     * @param int $phase Phase to filter to.
     * @return array List of ['questiontext', 'chosenanswer', 'correctanswer', 'iscorrect'].
     */
    public static function get_phase_log(int $attemptid, int $level, int $phase): array {
        global $DB;

        $rows = $DB->get_records(
            'playerpuzzle_attempt_questions',
            ['attemptid' => $attemptid, 'attemptlevel' => $level, 'attemptphase' => $phase],
            'id ASC',
            'id, questiontext, chosenanswer, correctanswer, iscorrect'
        );

        $log = [];
        foreach ($rows as $row) {
            $log[] = [
                'questiontext'  => (string) $row->questiontext,
                'chosenanswer'  => (string) $row->chosenanswer,
                'correctanswer' => (string) $row->correctanswer,
                'iscorrect'     => (bool) $row->iscorrect,
            ];
        }

        return $log;
    }
}
