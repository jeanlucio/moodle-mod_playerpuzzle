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
 * Teacher report query service for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context;
use mod_playerpuzzle\local\engine\combat;
use stdClass;

/**
 * Builds the per-student and whole-class views of the teacher report.
 */
class report_service {
    /**
     * Returns one row per student, even a student with zero attempts — so the teacher
     * sees who has not engaged yet, not just who has.
     *
     * @param stdClass $instance Activity instance record.
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id, for SEPARATEGROUPS scoping.
     * @return array Rows: {userid, fullname, hasgrade, grade, attemptsused,
     *  avgdamagepercent, avgcorrect, avgtotal, lastmatch}.
     */
    public static function get_student_rows(
        stdClass $instance,
        stdClass $cm,
        context $context,
        int $viewerid
    ): array {
        global $DB;

        $students = self::get_student_pool($cm, $context, $viewerid);
        if (empty($students)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'stu');
        // Demo attempts are excluded: they are disposable practice fights with fixed HP, and
        // must never appear in a teacher-facing report as if they were real submissions.
        $sql = "SELECT a.id, a.userid, a.currentlevel, a.currentphase, a.difficulty, a.status,
                       a.bosshp_remaining, a.questions_correct, a.questions_total, a.timefinished
                  FROM {playerpuzzle_attempts} a
                 WHERE a.playerpuzzleid = :instanceid
                       AND a.isdemo = 0
                       AND a.userid $insql";
        $attempts = $DB->get_records_sql(
            $sql,
            array_merge(['instanceid' => (int) $instance->id], $inparams)
        );

        $byuser = [];
        foreach ($attempts as $attempt) {
            $byuser[(int) $attempt->userid][] = $attempt;
        }

        $canviewfullnames = has_capability('moodle/site:viewfullnames', $context);
        $rows = [];
        foreach ($students as $userid => $student) {
            $rows[] = self::build_student_row(
                $instance,
                $student,
                $byuser[$userid] ?? [],
                $canviewfullnames
            );
        }

        usort($rows, fn(array $a, array $b): int => strcmp($a['fullname'], $b['fullname']));

        return $rows;
    }

    /**
     * Builds one student's report row from their own attempts.
     *
     * @param stdClass $instance Activity instance record.
     * @param stdClass $student User record (id, name fields).
     * @param stdClass[] $userattempts This student's own attempts, any status.
     * @param bool $canviewfullnames Whether the viewer may see real student names.
     * @return array
     */
    private static function build_student_row(
        stdClass $instance,
        stdClass $student,
        array $userattempts,
        bool $canviewfullnames
    ): array {
        $finished = array_values(array_filter(
            $userattempts,
            fn(stdClass $a): bool => $a->status !== 'inprogress'
        ));

        $grade = grade_calculator::calculate_user_grade($instance, $userattempts);

        $damagepercents = [];
        $correctsum = 0;
        $totalsum = 0;
        $lastfinished = 0;
        foreach ($finished as $attempt) {
            $bosshp = combat::apply_difficulty(
                combat::calculate_boss_hp(
                    (int) $instance->basebosshp,
                    (int) $attempt->currentlevel,
                    (int) $attempt->currentphase
                ),
                (string) $attempt->difficulty
            );
            $damage = max(0, $bosshp - (int) $attempt->bosshp_remaining);
            $damagepercents[] = $bosshp > 0 ? ($damage / $bosshp) * 100 : 0.0;
            $correctsum += (int) $attempt->questions_correct;
            $totalsum += (int) $attempt->questions_total;
            $lastfinished = max($lastfinished, (int) $attempt->timefinished);
        }

        $attemptcount = count($finished);

        return [
            'userid'           => (int) $student->id,
            'fullname'         => fullname($student, $canviewfullnames),
            'hasgrade'         => $grade !== null,
            'grade'            => $grade !== null ? format_float($grade, 2) : '-',
            'attemptsused'     => $attemptcount,
            'avgdamagepercent' => $attemptcount > 0
                ? format_float(array_sum($damagepercents) / $attemptcount, 1)
                : '-',
            'avgcorrect'       => $attemptcount > 0 ? format_float($correctsum / $attemptcount, 1) : '-',
            'avgtotal'         => $attemptcount > 0 ? format_float($totalsum / $attemptcount, 1) : '-',
            'lastmatch'        => $lastfinished > 0
                ? userdate($lastfinished, get_string('strftimedatetime', 'langconfig'))
                : '-',
        ];
    }

    /**
     * Returns the questions with the highest error rate across the whole class, most
     * missed first.
     *
     * Uses a snapshot questiontext already stored on each row rather than a live lookup
     * against the question bank — the same question may have since been edited or
     * deleted, but the text the student actually saw is what a "most missed" report
     * should show.
     *
     * @param stdClass $instance Activity instance record.
     * @param int $limit Maximum number of questions to return.
     * @return array Rows: {questiontext, errors, total, errorrate}, most missed first.
     */
    public static function get_most_missed_questions(stdClass $instance, int $limit = 10): array {
        global $DB;

        $sql = "SELECT stats.questionid, stats.errors, stats.total, sample.questiontext
                  FROM (SELECT aq.questionid,
                               SUM(CASE WHEN aq.iscorrect = 0 THEN 1 ELSE 0 END) AS errors,
                               COUNT(*) AS total,
                               MIN(aq.id) AS sampleid
                          FROM {playerpuzzle_attempt_questions} aq
                          JOIN {playerpuzzle_attempts} a ON a.id = aq.attemptid
                         WHERE a.playerpuzzleid = :instanceid
                               AND a.isdemo = 0
                      GROUP BY aq.questionid) stats
                  JOIN {playerpuzzle_attempt_questions} sample ON sample.id = stats.sampleid";
        $records = $DB->get_records_sql($sql, ['instanceid' => (int) $instance->id]);

        $rows = [];
        foreach ($records as $record) {
            $total = (int) $record->total;
            $errors = (int) $record->errors;
            $rows[] = [
                'questiontext' => format_string($record->questiontext),
                'errors'       => $errors,
                'total'        => $total,
                'errorrate'    => $total > 0 ? round(($errors / $total) * 100, 1) : 0.0,
            ];
        }

        usort($rows, fn(array $a, array $b): int => $b['errorrate'] <=> $a['errorrate']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Buckets every finished attempt's own score (0-100, before scaling by the
     * instance's Nota Máxima) into 5 fixed ranges.
     *
     * @param stdClass $instance Activity instance record.
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id, for SEPARATEGROUPS scoping.
     * @return array Rows: {min, max, count}, one per bucket, in ascending order.
     */
    public static function get_score_distribution(
        stdClass $instance,
        stdClass $cm,
        context $context,
        int $viewerid
    ): array {
        global $DB;

        $buckets = [
            ['min' => 0, 'max' => 20, 'count' => 0],
            ['min' => 21, 'max' => 40, 'count' => 0],
            ['min' => 41, 'max' => 60, 'count' => 0],
            ['min' => 61, 'max' => 80, 'count' => 0],
            ['min' => 81, 'max' => 100, 'count' => 0],
        ];

        $students = self::get_student_pool($cm, $context, $viewerid);
        if (empty($students)) {
            return $buckets;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'stu');
        $scores = $DB->get_fieldset_select(
            'playerpuzzle_attempts',
            'score',
            "playerpuzzleid = :instanceid AND status <> :inprogress AND isdemo = 0 AND userid $insql",
            array_merge(['instanceid' => (int) $instance->id, 'inprogress' => 'inprogress'], $inparams)
        );

        foreach ($scores as $score) {
            $buckets[self::bucket_index((float) $score)]['count']++;
        }

        return $buckets;
    }

    /**
     * Resolves which of the 5 fixed score buckets a score falls into.
     *
     * @param float $score A finished attempt's own score, 0-100.
     * @return int Bucket index, 0-4.
     */
    private static function bucket_index(float $score): int {
        if ($score <= 20) {
            return 0;
        }
        if ($score <= 40) {
            return 1;
        }
        if ($score <= 60) {
            return 2;
        }
        if ($score <= 80) {
            return 3;
        }
        return 4;
    }

    /**
     * Returns how many of the relevant students have completed the activity at least
     * once — Campaign: reached a genuine 'won' status (the whole configured campaign,
     * not a mid-run phase win); Single Match: finished at least one match, win or lose.
     *
     * @param stdClass $instance Activity instance record.
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id, for SEPARATEGROUPS scoping.
     * @return array {completed, total, percent}.
     */
    public static function get_completion_rate(
        stdClass $instance,
        stdClass $cm,
        context $context,
        int $viewerid
    ): array {
        global $DB;

        $students = self::get_student_pool($cm, $context, $viewerid);
        $total = count($students);
        if ($total === 0) {
            return ['completed' => 0, 'total' => 0, 'percent' => 0.0];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'stu');
        $params = array_merge(['instanceid' => (int) $instance->id], $inparams);

        if ($instance->gamemode === PLAYERPUZZLE_GAMEMODE_SINGLE) {
            $params['inprogress'] = 'inprogress';
            $completed = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid)
                   FROM {playerpuzzle_attempts}
                  WHERE playerpuzzleid = :instanceid AND status <> :inprogress AND isdemo = 0
                        AND userid $insql",
                $params
            );
        } else {
            $params['won'] = 'won';
            $completed = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid)
                   FROM {playerpuzzle_attempts}
                  WHERE playerpuzzleid = :instanceid AND status = :won AND isdemo = 0
                        AND userid $insql",
                $params
            );
        }

        return [
            'completed' => $completed,
            'total'     => $total,
            'percent'   => round(($completed / $total) * 100, 1),
        ];
    }

    /**
     * Returns the students this report should show, keyed by user id: enrolled users
     * holding the 'view' capability, minus anyone who can also view the report
     * themselves (teachers/managers previewing the activity are not tracked as
     * players), scoped to the viewer's own group(s) under SEPARATEGROUPS.
     *
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id.
     * @return stdClass[] Keyed by user id.
     */
    private static function get_student_pool(stdClass $cm, context $context, int $viewerid): array {
        $namefields = \core_user\fields::for_name()->get_sql('u')->selects;
        $students = get_enrolled_users(
            $context,
            'mod/playerpuzzle:view',
            0,
            "u.id{$namefields}",
            null,
            0,
            0,
            true
        );

        $staff = get_users_by_capability($context, 'mod/playerpuzzle:viewreport', 'u.id');
        foreach (array_keys($staff) as $staffid) {
            unset($students[$staffid]);
        }

        $groupfilter = self::resolve_group_filter($cm, $context, $viewerid);
        if ($groupfilter !== null) {
            $students = array_intersect_key($students, array_flip($groupfilter));
        }

        return $students;
    }

    /**
     * Resolves the group-membership filter for the current viewer — same pattern
     * already used by ranking_service::resolve_user_filter()/attempts_history_service's
     * own resolve_group_filter() in mod_playerwords, with the moodle/site:accessallgroups
     * override a report-viewing role can legitimately hold.
     *
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id.
     * @return int[]|null Null when no filter is needed (every student visible).
     */
    private static function resolve_group_filter(stdClass $cm, context $context, int $viewerid): ?array {
        global $DB;

        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode != SEPARATEGROUPS || has_capability('moodle/site:accessallgroups', $context, $viewerid)) {
            return null;
        }

        $groups = groups_get_all_groups($cm->course, $viewerid, $cm->groupingid);
        if (empty($groups)) {
            return [$viewerid];
        }

        [$sql, $params] = groups_get_members_ids_sql(
            array_keys($groups),
            \context_course::instance($cm->course)
        );
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }
}
