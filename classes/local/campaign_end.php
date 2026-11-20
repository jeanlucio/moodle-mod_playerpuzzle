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
 * What the end screen tells a student whose Campaign has just ended.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use stdClass;

/**
 * The end screen of a match says "phase lost" and offers to play again, which is wrong when that attempt was the
 * Campaign's last: the Campaign is over, the next Play starts another, if the activity allows one. This builds
 * what the screen shows instead: that it ended and why (won, or out of attempts), how far the student got, the
 * grade it earned when the activity is graded, and the position in the class ranking when the teacher turned it on.
 */
final class campaign_end {
    /**
     * Whether the Campaign the student just played has ended and, if so, the lines that say how it went. Read
     * after the attempt was saved and the grade updated, so the grade is the one the gradebook now holds.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass $cm Course module record.
     * @param int $userid User ID.
     * @param bool $won Whether the attempt that was just saved won the Campaign.
     * @return array ['ended' => bool, 'lines' => string[]]; no lines unless it ended.
     */
    public static function summary(stdClass $instance, stdClass $cm, int $userid, bool $won): array {
        global $DB;

        $none = ['ended' => false, 'lines' => []];
        if ($instance->gamemode !== constants::GAMEMODE_CAMPAIGN) {
            return $none;
        }

        // The attempt just saved is the most recent one that ended, so what the next start reads from it is how
        // its Campaign ended: won, out of attempts, or still going.
        $start = game_page_service::next_campaign_start($instance, $userid);
        if (!$start->startsnew) {
            return $none;
        }

        $attempts = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'campaign' => $start->campaign - 1, 'isdemo' => 0],
            'id ASC',
            'id, campaign, status, gaveup, currentlevel, currentphase, frozenmaxlevels'
        );
        if (!$attempts) {
            return $none;
        }

        $lines = [];
        if ($won) {
            $lines[] = get_string('campaignend_won', 'mod_playerpuzzle');
        } else if ($start->ended === campaigns::ENDED_ATTEMPTS) {
            $lines[] = get_string('campaignover_text', 'mod_playerpuzzle');
        }
        $attempts = array_values($attempts);
        $lines[] = get_string('campaignend_phases', 'mod_playerpuzzle', (object) [
            'won' => campaigns::phases_won_of($attempts),
            'total' => campaigns::total_phases($instance, $attempts),
        ]);
        $grade = self::grade($instance, $userid);
        if ($grade !== null) {
            $lines[] = get_string('campaignend_grade', 'mod_playerpuzzle', $grade);
        }
        if (!empty($instance->show_ranking)) {
            $standing = ranking_service::get_standing($instance, $cm, $userid);
            if ($standing !== null) {
                $lines[] = get_string('campaignend_ranking', 'mod_playerpuzzle', (object) $standing);
            }
        }

        return ['ended' => true, 'lines' => $lines];
    }

    /**
     * The student's grade in the gradebook as they would see it, with the maximum, when the activity is graded
     * and the grade is not hidden from them.
     *
     * @param stdClass $instance Activity instance (id, course, grade).
     * @param int $userid User ID.
     * @return stdClass|null ->grade and ->max, formatted, or null when there is none to show.
     */
    private static function grade(stdClass $instance, int $userid): ?stdClass {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        if ((float) $instance->grade <= 0) {
            return null;
        }
        $grades = grade_get_grades((int) $instance->course, 'mod', 'playerpuzzle', (int) $instance->id, $userid);
        $item = $grades->items[0] ?? null;
        $grade = $item->grades[$userid] ?? null;
        if ($grade === null || $grade->grade === null || !empty($grade->hidden) || !empty($item->hidden)) {
            return null;
        }

        return (object) [
            'grade' => format_float((float) $grade->grade, 2),
            'max' => format_float((float) $item->grademax, 2),
        ];
    }
}
