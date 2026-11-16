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
    /** @var int Students per page of the report's student table. */
    public const PER_PAGE = 50;

    /**
     * The activity's name as plain text, for a Mustache `{{ }}` tag.
     *
     * See text::plain_string() for why format_string() cannot be passed to the template as it is.
     *
     * @param stdClass $instance The activity record.
     * @param context $context The activity's context, for the filters.
     * @return string
     */
    public static function activity_name(stdClass $instance, context $context): string {
        return text::plain_string($instance->name, $context);
    }

    /**
     * Returns one row per student, even a student with zero attempts — so the teacher
     * sees who has not engaged yet, not just who has.
     *
     * @param stdClass $instance Activity instance record.
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id, for SEPARATEGROUPS scoping.
     * @param int $page Zero-based page of the name-ordered list; ignored when $perpage is 0.
     * @param int $perpage Rows per page, 0 for every student. Only the students of the page have their attempts
     *  loaded and scored, so a large class costs what one page costs.
     * @return array Rows ordered by name: {userid, fullname, hasgrade, grade, attemptsused,
     *  avgdamagepercent, avgcorrect, avgtotal, lastmatch}.
     */
    public static function get_student_rows(
        stdClass $instance,
        stdClass $cm,
        context $context,
        int $viewerid,
        int $page = 0,
        int $perpage = 0
    ): array {
        global $DB;

        $students = self::get_student_pool($cm, $context, $viewerid);
        if (empty($students)) {
            return [];
        }

        $canviewfullnames = has_capability('moodle/site:viewfullnames', $context);
        $names = [];
        foreach ($students as $userid => $student) {
            $names[$userid] = fullname($student, $canviewfullnames);
        }
        uasort($names, 'strcmp');
        if ($perpage > 0) {
            $names = array_slice($names, max(0, $page) * $perpage, $perpage, true);
        }
        if ($names === []) {
            return [];
        }
        $students = array_replace($names, array_intersect_key($students, $names));

        $byuser = self::load_attempts_by_user($instance, array_keys($students));

        $rows = [];
        foreach ($students as $userid => $student) {
            $rows[] = self::build_student_row(
                $instance,
                $student,
                $byuser[$userid] ?? [],
                $canviewfullnames
            );
        }

        return $rows;
    }

    /**
     * Loads the real (non-Demo) attempts of the given students, grouped by student, oldest first.
     *
     * Demo attempts are excluded: they are disposable practice fights with fixed HP, and must never appear in a
     * teacher-facing report as if they were real submissions. The columns are the ones grade_calculator reads, which
     * takes frozenmaxlevels from the last attempt of each Campaign: without the column, or with the attempts out of
     * order, a later change of the level count would re-score Campaigns already played.
     *
     * @param stdClass $instance Activity instance record.
     * @param int[] $userids The students whose attempts to load.
     * @return array Attempt records keyed by user id, each a list in play order.
     */
    private static function load_attempts_by_user(stdClass $instance, array $userids): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'stu');
        $sql = "SELECT a.id, a.userid, a.currentlevel, a.currentphase, a.campaign, a.difficulty, a.status,
                       a.bosshp_remaining, a.questions_correct, a.questions_total, a.timefinished,
                       a.isdemo, a.frozenbasebosshp, a.frozenbossdamage, a.frozenopponent, a.frozenmaxlevels
                  FROM {playerpuzzle_attempts} a
                 WHERE a.playerpuzzleid = :instanceid
                       AND a.isdemo = 0
                       AND a.userid $insql
              ORDER BY a.timecreated ASC, a.id ASC";
        $attempts = $DB->get_records_sql(
            $sql,
            array_merge(['instanceid' => (int) $instance->id], $inparams)
        );

        $byuser = [];
        foreach ($attempts as $attempt) {
            $byuser[(int) $attempt->userid][] = $attempt;
        }

        return $byuser;
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
            // The boss HP this phase was really fought at, from the config and opponent frozen
            // when it started — the teacher may have changed basebosshp since, which must not
            // rewrite the damage of matches already played.
            $bosshp = combat::phase_opponent($attempt, $instance)['maxbosshp'];
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
                ? userdate($lastfinished, get_string('strftimedatetimeshort', 'langconfig'))
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
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id, for SEPARATEGROUPS scoping.
     * @param int $limit Maximum number of questions to return.
     * @return array Rows: {questiontext, errors, total, errorrate, errorratetext}, most missed first. A question
     *  nobody got wrong is not a missed question, so it is left out.
     */
    public static function get_most_missed_questions(
        stdClass $instance,
        stdClass $cm,
        context $context,
        int $viewerid,
        int $limit = 10
    ): array {
        global $DB;

        $students = self::get_student_pool($cm, $context, $viewerid);
        if (empty($students)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'stu');
        $sql = "SELECT stats.questionid, stats.errors, stats.total, sample.questiontext
                  FROM (SELECT aq.questionid,
                               SUM(CASE WHEN aq.iscorrect = 0 THEN 1 ELSE 0 END) AS errors,
                               COUNT(*) AS total,
                               MIN(aq.id) AS sampleid
                          FROM {playerpuzzle_attempt_questions} aq
                          JOIN {playerpuzzle_attempts} a ON a.id = aq.attemptid
                         WHERE a.playerpuzzleid = :instanceid
                               AND a.isdemo = 0
                               AND a.userid $insql
                      GROUP BY aq.questionid) stats
                  JOIN {playerpuzzle_attempt_questions} sample ON sample.id = stats.sampleid
                 WHERE stats.errors > 0";
        $records = $DB->get_records_sql(
            $sql,
            array_merge(['instanceid' => (int) $instance->id], $inparams)
        );

        $rows = [];
        foreach ($records as $record) {
            $total = (int) $record->total;
            $errors = (int) $record->errors;
            $rows[] = [
                // The snapshot is already formatted HTML. Reducing it to plain text here, rather
                // than passing it through format_string(), leaves entities decoded, so the
                // template's single escaping is the only one.
                'questiontext' => trim(content_to_text($record->questiontext, FORMAT_HTML)),
                'errors'       => $errors,
                'total'        => $total,
                'errorrate'    => $total > 0 ? round(($errors / $total) * 100, 1) : 0.0,
                'errorratetext' => $total > 0 ? format_float(($errors / $total) * 100, 1) : format_float(0, 1),
            ];
        }

        usort($rows, fn(array $a, array $b): int => $b['errorrate'] <=> $a['errorrate']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Buckets the students by their grade, as a percentage of the activity's maximum grade, into 5 fixed ranges.
     *
     * Each student counts once, by the same grade the gradebook and the table hold (grade_calculator), so the chart
     * and the table agree. A student with no grade yet is left out.
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

        $maxgrade = (float) $instance->grade;
        foreach (self::load_attempts_by_user($instance, array_keys($students)) as $userattempts) {
            $grade = grade_calculator::calculate_user_grade($instance, $userattempts);
            if ($grade === null) {
                continue;
            }
            // Rounded so a grade sitting on a boundary is not pushed over it by floating point noise.
            $percent = $maxgrade > 0 ? round(($grade / $maxgrade) * 100, 5) : 0.0;
            $buckets[self::bucket_index($percent)]['count']++;
        }

        return $buckets;
    }

    /**
     * Resolves which of the 5 fixed score buckets a grade percentage falls into.
     *
     * @param float $score A grade as a percentage of the maximum grade, 0-100.
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

        if ($instance->gamemode === constants::GAMEMODE_SINGLE) {
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
     * How each difficulty has gone for the class, from the phases its students have fought: the figures a teacher
     * needs to tell a difficulty that is too hard from a class that has not practised yet. Only Campaigns have
     * phases, so a Single Match activity has none.
     *
     * @param stdClass $instance Activity instance record.
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id, for SEPARATEGROUPS scoping.
     * @return array {difficulties, phasecolumns, phases, heroes}, see summarise_fights(); empty lists without data.
     */
    public static function get_difficulty_breakdown(
        stdClass $instance,
        stdClass $cm,
        context $context,
        int $viewerid
    ): array {
        $empty = ['difficulties' => [], 'phasecolumns' => [], 'phases' => [], 'heroes' => []];
        if ($instance->gamemode !== constants::GAMEMODE_CAMPAIGN) {
            return $empty;
        }

        $students = self::get_student_pool($cm, $context, $viewerid);
        if (empty($students)) {
            return $empty;
        }

        return self::summarise_fights(
            self::load_attempts_by_user($instance, array_keys($students)),
            self::load_heroes_by_user($instance, array_keys($students))
        );
    }

    /**
     * The hero each of the given students played in each of their Campaigns.
     *
     * @param stdClass $instance Activity instance record.
     * @param int[] $userids The students whose heroes to load.
     * @return array Hero keys, [user id][Campaign number] => hero.
     */
    private static function load_heroes_by_user(stdClass $instance, array $userids): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'stu');
        $rows = $DB->get_records_select(
            'playerpuzzle_heroes',
            "playerpuzzleid = :instanceid AND userid $insql",
            array_merge(['instanceid' => (int) $instance->id], $inparams),
            '',
            'id, userid, campaign, hero'
        );

        $byuser = [];
        foreach ($rows as $row) {
            $byuser[(int) $row->userid][(int) $row->campaign] = $row->hero;
        }

        return $byuser;
    }

    /**
     * Counts the phases fought in the given attempts, by difficulty, and lays them out for the report.
     *
     * An attempt row spans phases: it starts where the Campaign stood (Level 1, Phase 1, or the phase the previous
     * attempt lost) and keeps advancing while it wins, so every phase before the one it sits on was won, and the
     * phase it sits on was lost, won (the last phase of a won Campaign) or is undecided (still being played, or left
     * by the student). Only decided fights count. A fight is a "first try" when the student had not lost that phase
     * before in the same Campaign. The difficulty of a fight is the one its attempt was played on.
     *
     * The same fights are also counted for the hero of their Campaign: how many Campaigns each hero started, and how
     * its first tries went, whatever the difficulty.
     *
     * @param array $attemptsbyuser Attempts (campaign, difficulty, status, currentlevel, currentphase) in play order,
     *  keyed by user id.
     * @param array $herobyuser Hero keys, [user id][Campaign number] => hero, for the heroes table.
     * @return array {difficulties: rows {name, students, fights, firsttry, tries}, phasecolumns: names, phases: rows
     *  {phase, cells: texts}, heroes: rows {name, specialty, campaigns, students, firsttry}}, the difficulties and
     *  phases without a decided fight left out of the first list, the heroes never chosen out of the last.
     */
    public static function summarise_fights(array $attemptsbyuser, array $herobyuser = []): array {
        $levels = [constants::DIFFICULTY_EASY, constants::DIFFICULTY_NORMAL, constants::DIFFICULTY_HARD];
        $blank = ['users' => [], 'fights' => 0, 'wins' => 0, 'firsttryfights' => 0, 'firsttrywins' => 0];
        $bydifficulty = array_fill_keys($levels, $blank);
        $byphase = array_fill_keys($levels, array_fill(1, campaigns::PHASES_PER_LEVEL, [0, 0]));
        $byhero = [];
        foreach ($herobyuser as $userid => $userheroes) {
            foreach ($userheroes as $hero) {
                if (isset(heroes::CATALOG[$hero])) {
                    $byhero[$hero] ??= ['campaigns' => 0, 'users' => [], 'firsttryfights' => 0, 'firsttrywins' => 0];
                    $byhero[$hero]['campaigns']++;
                    $byhero[$hero]['users'][(int) $userid] = true;
                }
            }
        }

        foreach ($attemptsbyuser as $userid => $userattempts) {
            foreach (campaigns::group($userattempts) as $campaign => $attempts) {
                $hero = $herobyuser[$userid][$campaign] ?? '';
                $start = 1;
                $lostat = [];
                foreach ($attempts as $attempt) {
                    $end = (((int) $attempt->currentlevel - 1) * campaigns::PHASES_PER_LEVEL) + (int) $attempt->currentphase;
                    $difficulty = $attempt->difficulty;
                    if (isset($bydifficulty[$difficulty])) {
                        $lastwon = $attempt->status === 'won' ? $end : $end - 1;
                        $fights = [];
                        for ($ordinal = $start; $ordinal <= $lastwon; $ordinal++) {
                            $fights[] = [$ordinal, true];
                        }
                        if ($attempt->status === 'lost' && $end >= $start) {
                            $fights[] = [$end, false];
                        }
                        foreach ($fights as [$ordinal, $won]) {
                            $first = !isset($lostat[$ordinal]);
                            $phase = (($ordinal - 1) % campaigns::PHASES_PER_LEVEL) + 1;
                            $bydifficulty[$difficulty]['users'][(int) $userid] = true;
                            $bydifficulty[$difficulty]['fights']++;
                            $bydifficulty[$difficulty]['wins'] += $won ? 1 : 0;
                            if ($first) {
                                $bydifficulty[$difficulty]['firsttryfights']++;
                                $bydifficulty[$difficulty]['firsttrywins'] += $won ? 1 : 0;
                                $byphase[$difficulty][$phase][0]++;
                                $byphase[$difficulty][$phase][1] += $won ? 1 : 0;
                                if (isset($byhero[$hero])) {
                                    $byhero[$hero]['firsttryfights']++;
                                    $byhero[$hero]['firsttrywins'] += $won ? 1 : 0;
                                }
                            }
                            if (!$won) {
                                $lostat[$ordinal] = true;
                            }
                        }
                    }
                    $start = max($start, $end);
                }
            }
        }

        $rows = [];
        $columns = [];
        foreach ($levels as $difficulty) {
            $tally = $bydifficulty[$difficulty];
            if ($tally['fights'] === 0) {
                continue;
            }
            $name = get_string('difficulty_' . $difficulty, 'mod_playerpuzzle');
            $columns[$difficulty] = $name;
            $rows[] = [
                'name' => $name,
                'students' => count($tally['users']),
                'fights' => $tally['fights'],
                'firsttry' => self::share_text($tally['firsttrywins'], $tally['firsttryfights']),
                'tries' => $tally['wins'] > 0 ? format_float($tally['fights'] / $tally['wins'], 1) : '–',
            ];
        }

        $phases = [];
        for ($phase = 1; $phase <= campaigns::PHASES_PER_LEVEL; $phase++) {
            $cells = [];
            foreach (array_keys($columns) as $difficulty) {
                [$fought, $won] = $byphase[$difficulty][$phase];
                $cells[] = ['text' => $fought > 0 ? self::share_text($won, $fought) : '–'];
            }
            $phases[] = ['phase' => $phase, 'cells' => $cells];
        }

        $heroesrows = [];
        foreach (array_keys(heroes::CATALOG) as $hero) {
            if (!isset($byhero[$hero])) {
                continue;
            }
            $tally = $byhero[$hero];
            $heroesrows[] = [
                'name' => get_string('hero_name_' . $hero, 'mod_playerpuzzle'),
                'specialty' => get_string('hero_attribute_' . heroes::specialty($hero), 'mod_playerpuzzle'),
                'campaigns' => $tally['campaigns'],
                'students' => count($tally['users']),
                'firsttry' => $tally['firsttryfights'] > 0
                    ? self::share_text($tally['firsttrywins'], $tally['firsttryfights'])
                    : '–',
            ];
        }
        // Most chosen first; the sort is stable, so heroes chosen as often keep the catalog order.
        usort($heroesrows, static fn(array $a, array $b): int => $b['campaigns'] <=> $a['campaigns']);

        return [
            'heroes' => $heroesrows,
            'difficulties' => $rows,
            'phasecolumns' => array_map(static fn(string $name): array => ['name' => $name], array_values($columns)),
            'phases' => empty($rows) ? [] : $phases,
        ];
    }

    /**
     * A share as the report prints it: the percentage and the counts behind it, so a figure resting on two fights
     * does not read like one resting on two hundred.
     *
     * @param int $part The part.
     * @param int $whole The whole, above zero.
     * @return string For example "58% (7/12)".
     */
    private static function share_text(int $part, int $whole): string {
        return round(($part / $whole) * 100) . '% (' . $part . '/' . $whole . ')';
    }

    /**
     * Returns the students a viewer may see, keyed by user id: enrolled users holding the
     * 'view' capability, minus anyone who can also view the report themselves
     * (teachers/managers previewing the activity are not tracked as players), scoped to the
     * viewer's own group(s) under SEPARATEGROUPS. Shared by this report and the student-facing
     * ranking (ranking_service), so both apply the exact same people and group rules.
     *
     * @param stdClass $cm Course module record.
     * @param context $context Module context.
     * @param int $viewerid Current viewer's user id.
     * @return stdClass[] Keyed by user id.
     */
    public static function get_student_pool(stdClass $cm, context $context, int $viewerid): array {
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
