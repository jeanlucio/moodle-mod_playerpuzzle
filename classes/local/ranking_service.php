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
 * Student-facing ranking for a PlayerPuzzle activity.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context_module;
use stdClass;

/**
 * Ranks the students of one activity, scored against a fixed base rather than the activity's
 * own grade, so an activity set to "No grade" still gets a meaningful ranking.
 *
 * Campaign scores exactly like the grade (grade_calculator::campaign_score(): how far a
 * winning streak has reached, blended with question accuracy when errors count), ties broken
 * by fewer attempts. Single Match adds up every finished match's own score — engagement counts
 * here, unlike the grade's grademethod — ties broken by fewer matches, then less time played.
 *
 * Who appears follows the teacher report exactly (report_service::get_student_pool()):
 * enrolled students only, never staff, and only the viewer's own groups under SEPARATEGROUPS.
 * Demo matches never count. Every phase won and every match won is one the server-side replay
 * verified, so these numbers cannot be claimed into place.
 */
class ranking_service {
    /** @var int What a complete campaign, or one won match, is worth in the ranking. */
    public const BASE_POINTS = 100;

    /** @var int Rows shown at the top of the ranking. */
    public const TOP_N = 5;

    /**
     * Builds the ranking as a viewer sees it: the top rows, plus the viewer's own row when it
     * falls outside them.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass $cm Course module record.
     * @param int $viewerid Current user id.
     * @return array {rows, outsiderrow, hasoutsider, isempty} — each row {position, fullname,
     *  points, iscurrentuser}.
     */
    public static function get_ranking(stdClass $instance, stdClass $cm, int $viewerid): array {
        $context = context_module::instance($cm->id);
        $entries = self::score_students($instance, report_service::get_student_pool($cm, $context, $viewerid));

        $canviewfullnames = has_capability('moodle/site:viewfullnames', $context);
        $rows = [];
        $outsiderrow = null;
        foreach ($entries as $index => $entry) {
            $position = $index + 1;
            $row = [
                'position' => $position,
                'fullname' => fullname($entry['user'], $canviewfullnames),
                'points' => format_float($entry['points'], 1, true, true),
                'iscurrentuser' => (int) $entry['user']->id === $viewerid,
            ];
            if ($position <= self::TOP_N) {
                $rows[] = $row;
            } else if ($row['iscurrentuser']) {
                $outsiderrow = $row;
            }
        }

        return [
            'rows' => $rows,
            'outsiderrow' => $outsiderrow,
            'hasoutsider' => $outsiderrow !== null,
            'isempty' => $entries === [],
        ];
    }

    /**
     * Scores and orders every student who has played, best first.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $students Student pool keyed by user id (name fields included).
     * @return array List of ['user' => stdClass, 'points' => float, 'attempts' => int,
     *  'timeplayed' => int], ordered.
     */
    private static function score_students(stdClass $instance, array $students): array {
        global $DB;

        if ($students === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'uid');
        $params['playerpuzzleid'] = (int) $instance->id;
        $records = $DB->get_records_select(
            'playerpuzzle_attempts',
            "playerpuzzleid = :playerpuzzleid AND isdemo = 0 AND userid $insql",
            $params,
            'id ASC',
            'id, userid, currentlevel, currentphase, status, questions_correct, questions_total, timecreated, timefinished'
        );

        $byuser = [];
        foreach ($records as $record) {
            $byuser[(int) $record->userid][] = $record;
        }

        $single = $instance->gamemode === PLAYERPUZZLE_GAMEMODE_SINGLE;
        $entries = [];
        foreach ($byuser as $userid => $attempts) {
            $entry = $single ? self::score_single_match($instance, $attempts) : self::score_campaign($instance, $attempts);
            if ($entry !== null) {
                $entry['user'] = $students[$userid];
                $entries[] = $entry;
            }
        }

        usort($entries, static function (array $a, array $b): int {
            return [$b['points'], $a['attempts'], $a['timeplayed'], fullname($a['user'])]
                <=> [$a['points'], $b['attempts'], $b['timeplayed'], fullname($b['user'])];
        });

        return $entries;
    }

    /**
     * Campaign: the grade's own formula against BASE_POINTS. A student appears once they
     * have won a phase or finished an attempt — merely opening the game does not rank them.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $attempts The student's attempts.
     * @return array|null ['points', 'attempts', 'timeplayed'], or null when nothing to rank.
     */
    private static function score_campaign(stdClass $instance, array $attempts): ?array {
        $points = grade_calculator::campaign_score($instance, $attempts, self::BASE_POINTS);
        $finished = array_filter($attempts, static fn(stdClass $a): bool => (int) $a->timefinished > 0);
        if ($points <= 0 && $finished === []) {
            return null;
        }

        return ['points' => $points, 'attempts' => count($attempts), 'timeplayed' => 0];
    }

    /**
     * Single Match: the sum of every finished match's own score, against BASE_POINTS.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $attempts The student's attempts.
     * @return array|null ['points', 'attempts', 'timeplayed'], or null when no match finished.
     */
    private static function score_single_match(stdClass $instance, array $attempts): ?array {
        $finished = array_filter($attempts, static fn(stdClass $a): bool => (int) $a->timefinished > 0);
        if ($finished === []) {
            return null;
        }

        $points = 0.0;
        $timeplayed = 0;
        foreach ($finished as $attempt) {
            $points += grade_calculator::single_match_score($instance, $attempt, self::BASE_POINTS);
            $timeplayed += max(0, (int) $attempt->timefinished - (int) $attempt->timecreated);
        }

        return ['points' => $points, 'attempts' => count($finished), 'timeplayed' => $timeplayed];
    }
}
