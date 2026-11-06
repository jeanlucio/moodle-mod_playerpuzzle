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
 * Grade calculation for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use stdClass;

/**
 * Computes a student's final grade from their attempts, one independent formula per Modo
 * de Jogo — not a shared weighted blend. Each mode's own attempt shape drives what "did
 * well" means: a Campaign is measured by how far it reached, a Single Match by how it
 * scored, and the teacher's chosen grademethod combines the student's Campaigns or matches.
 * Both scale against $instance->grade (the configured Nota Máxima), never a hardcoded 100.
 */
class grade_calculator {
    /**
     * Calculates a user's final grade from every attempt they have made on this instance.
     *
     * @param stdClass $instance Activity instance (gamemode, grademethod, grade,
     *  minquestions, considererrors, maxlevels, max_single_matches).
     * @param stdClass[] $attempts All of this user's attempts on this instance, any status
     *  (currentlevel, currentphase, status, questions_correct, questions_total,
     *  timefinished).
     * @return float|null Null when there is nothing to grade yet.
     */
    public static function calculate_user_grade(stdClass $instance, array $attempts): ?float {
        if (empty($attempts)) {
            return null;
        }

        if ($instance->gamemode === constants::GAMEMODE_SINGLE) {
            return self::calculate_single_match_grade($instance, $attempts);
        }

        return self::calculate_campaign_grade($instance, $attempts);
    }

    /**
     * Campaign mode: each graded Campaign scores on its own (see campaign_score()), and the
     * grademethod combines them, the way it combines Single Matches. Which Campaigns are graded is
     * campaigns::graded(): the ended ones, and the first even while it is still being played.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $attempts All attempts for this user on this instance.
     * @return float
     */
    private static function calculate_campaign_grade(stdClass $instance, array $attempts): float {
        $scores = array_map(
            fn(array $campaign): float => self::campaign_score($instance, $campaign, (float) $instance->grade),
            campaigns::graded($instance, $attempts)
        );

        return self::aggregate($instance, array_values($scores), (int) ($instance->maxcampaigns ?? 0));
    }

    /**
     * Returns when the student submitted the work behind their current grade, for the
     * gradebook's datesubmitted.
     *
     * @param stdClass $instance Activity instance (gamemode, grademethod, minquestions,
     *  considererrors).
     * @param stdClass[] $attempts All of this user's attempts on this instance, any status
     *  (currentlevel, currentphase, status, timefinished, timephasewon).
     * @return int|null Unix timestamp, or null when there is nothing graded yet.
     */
    public static function calculate_user_datesubmitted(stdClass $instance, array $attempts): ?int {
        if (empty($attempts)) {
            return null;
        }

        if ($instance->gamemode === constants::GAMEMODE_SINGLE) {
            return self::single_match_datesubmitted($instance, $attempts);
        }

        return self::campaign_datesubmitted($instance, $attempts);
    }

    /**
     * Campaign mode submission time: the submission time of the Campaign that produces the grade,
     * chosen the way single_match_datesubmitted() chooses a match — the best one for the highest
     * grade (the earliest of any tie), the first or last one for those methods, and the latest of
     * them all for both averages.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $attempts All attempts for this user on this instance.
     * @return int|null
     */
    private static function campaign_datesubmitted(stdClass $instance, array $attempts): ?int {
        $graded = campaigns::graded($instance, $attempts);
        $dates = array_map(fn(array $campaign): ?int => self::one_campaign_datesubmitted($instance, $campaign), $graded);

        switch ((int) $instance->grademethod) {
            case constants::GRADE_FIRST:
                return reset($dates);
            case constants::GRADE_LAST:
                return end($dates);
            case constants::GRADE_AVERAGE:
            case constants::GRADE_AVERAGE_ALL:
                $known = array_filter($dates);
                return $known ? max($known) : null;
            default:
                $scores = array_map(fn(array $campaign): float => self::campaign_score($instance, $campaign, 1.0), $graded);
                $best = max($scores);
                $tied = array_filter(
                    $dates,
                    static fn(?int $date, int $number): bool => $date !== null && abs($scores[$number] - $best) < 1e-9,
                    ARRAY_FILTER_USE_BOTH
                );
                return $tied ? min($tied) : null;
        }
    }

    /**
     * One Campaign's submission time.
     *
     * With Considerar Erros the accuracy half of the grade is pooled across every attempt,
     * so the grade depends on all of them, like an average: it was last submitted at the
     * latest phase won or fight ended. Without it the grade is the furthest phase ever
     * reached, like a highest grade: it was submitted when that phase was first reached, so
     * an attempt that only ties it, or stops short of it, never moves the date. With no phase
     * won at all, the zero grade came from the first fight that ended.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $attempts The attempts of that one Campaign.
     * @return int|null
     */
    private static function one_campaign_datesubmitted(stdClass $instance, array $attempts): ?int {
        if ((int) $instance->considererrors && (int) $instance->minquestions >= 1) {
            $latest = 0;
            foreach ($attempts as $attempt) {
                $latest = max($latest, (int) $attempt->timephasewon, (int) $attempt->timefinished);
            }

            return $latest ?: null;
        }

        $furthest = max(array_map([self::class, 'won_phase_ordinal'], $attempts));
        if ($furthest > 0) {
            $reached = [];
            foreach ($attempts as $attempt) {
                if (self::won_phase_ordinal($attempt) === $furthest && (int) $attempt->timephasewon > 0) {
                    $reached[] = (int) $attempt->timephasewon;
                }
            }

            return $reached ? min($reached) : null;
        }

        $ended = array_filter(array_map(fn(stdClass $a): int => (int) $a->timefinished, $attempts));

        return $ended ? min($ended) : null;
    }

    /**
     * Single Match submission time: the finish time of the match that produces the grade —
     * the best one for the highest grade (the earliest of any tie, so a later match with the
     * same score never moves it), the first or last one for those methods, and the latest one
     * for both averages, which depend on every match (the rule mod_quiz applies).
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $attempts All attempts for this user on this instance.
     * @return int|null Null when no match has finished yet.
     */
    private static function single_match_datesubmitted(stdClass $instance, array $attempts): ?int {
        $finished = array_values(array_filter($attempts, fn(stdClass $a): bool => (int) $a->timefinished > 0));
        if (empty($finished)) {
            return null;
        }
        usort($finished, fn(stdClass $a, stdClass $b): int => $a->timefinished <=> $b->timefinished);

        switch ((int) $instance->grademethod) {
            case constants::GRADE_FIRST:
                return (int) $finished[0]->timefinished;
            case constants::GRADE_LAST:
            case constants::GRADE_AVERAGE:
            case constants::GRADE_AVERAGE_ALL:
                return (int) $finished[count($finished) - 1]->timefinished;
            default:
                $best = $finished[0];
                $bestscore = self::single_match_score($instance, $best, 1.0);
                foreach ($finished as $attempt) {
                    $score = self::single_match_score($instance, $attempt, 1.0);
                    if ($score > $bestscore) {
                        $best = $attempt;
                        $bestscore = $score;
                    }
                }
                return (int) $best->timefinished;
        }
    }

    /**
     * One Campaign's score: the furthest phase it reached, across all of its attempts (a
     * "Tentar Novamente" after a loss opens a new attempt row rather than reusing the old
     * one), against the phases the Campaign had (campaigns::total_phases(): the level count its last attempt started
     * with, so changing the activity's level count later leaves a Campaign already played alone). Never a count of
     * `status = 'won'` rows — a single continuous attempt can cover dozens of phases of progress at once.
     *
     * Scaled against $base rather than always $instance->grade, so the ranking can reuse the
     * exact same formula against its own fixed base (see ranking_service::BASE_POINTS).
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $attempts The attempts of one Campaign.
     * @param float $base What a complete campaign is worth.
     * @return float
     */
    public static function campaign_score(stdClass $instance, array $attempts, float $base): float {
        $totalphases = campaigns::total_phases($instance, $attempts);
        $wonordinal = max(array_map([self::class, 'won_phase_ordinal'], $attempts));
        $progresspercent = min(100, ($wonordinal / $totalphases) * 100);

        if ((int) $instance->considererrors && (int) $instance->minquestions >= 1) {
            // Aggregated across every attempt of the Campaign, not just the one that reached
            // furthest — the question-accuracy component measures content engagement over the
            // whole Campaign, independent of which specific attempt made the most progress.
            $correct = array_sum(array_map(fn(stdClass $a): int => (int) $a->questions_correct, $attempts));
            $total = array_sum(array_map(fn(stdClass $a): int => (int) $a->questions_total, $attempts));
            $accuracypercent = $total > 0 ? ($correct / $total) * 100 : 100;

            return (($progresspercent + $accuracypercent) / 2) * $base / 100;
        }

        return ($progresspercent / 100) * $base;
    }

    /**
     * The last phase this attempt's own winning streak actually cleared. currentlevel/
     * currentphase only ever advance on a genuine phase win (advance_phase.php), so an
     * attempt that ended in anything other than 'won' was still mid-fight on the phase it
     * is sitting on — never won it — meaning only the phases strictly before it count.
     *
     * @param stdClass $attempt One attempt row.
     * @return int Phase ordinal, 0 or more (0 = nothing won yet).
     */
    private static function won_phase_ordinal(stdClass $attempt): int {
        $ordinal = (((int) $attempt->currentlevel - 1) * 10) + (int) $attempt->currentphase;
        if ($attempt->status !== 'won') {
            $ordinal--;
        }

        return max(0, $ordinal);
    }

    /**
     * Single Match mode: each finished match scores independently — a win multiplied by
     * that match's own question accuracy when Considerar Erros is on, otherwise a plain
     * binary grade/0 — aggregated by the teacher's chosen grademethod (Maior/Média/
     * Primeira/Última/Média sobre todas), the same five methods mod_playerwords already
     * implements identically for its own rounds.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass[] $attempts All attempts for this user on this instance.
     * @return float|null Null when no match has actually finished yet — an attempt still
     *  `inprogress` (open or abandoned) has no outcome to grade.
     */
    private static function calculate_single_match_grade(stdClass $instance, array $attempts): ?float {
        $finished = array_values(array_filter($attempts, fn(stdClass $a): bool => (int) $a->timefinished > 0));
        if (empty($finished)) {
            return null;
        }
        usort($finished, fn(stdClass $a, stdClass $b): int => $a->timefinished <=> $b->timefinished);

        $scores = array_map(
            fn(stdClass $attempt): float => self::single_match_score($instance, $attempt, (float) $instance->grade),
            $finished
        );

        return self::aggregate($instance, $scores, (int) $instance->max_single_matches);
    }

    /**
     * Combines scores, in the order they were played, by the teacher's grademethod. For
     * constants::GRADE_AVERAGE_ALL the divisor is the required count when the teacher set one
     * (an unplayed Campaign or match counts as zero), or the number actually played when Unlimited
     * (0), since there is then no fixed total to divide by.
     *
     * @param stdClass $instance Activity instance (grademethod).
     * @param float[] $scores Scores, at least one, in play order.
     * @param int $required The configured Campaign or match count, 0 for unlimited.
     * @return float
     */
    private static function aggregate(stdClass $instance, array $scores, int $required): float {
        return match ((int) $instance->grademethod) {
            constants::GRADE_AVERAGE => array_sum($scores) / count($scores),
            constants::GRADE_FIRST => $scores[array_key_first($scores)],
            constants::GRADE_LAST => $scores[array_key_last($scores)],
            constants::GRADE_AVERAGE_ALL => array_sum($scores) / ($required > 0 ? $required : count($scores)),
            default => max($scores),
        };
    }

    /**
     * One finished match's own score: $base for a win — weighted by that match's own question
     * accuracy when Considerar Erros is on — and 0 for anything else. Takes $base rather than
     * always $instance->grade so the ranking can reuse it (see ranking_service::BASE_POINTS).
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass $attempt A finished attempt.
     * @param float $base What a win is worth.
     * @return float
     */
    public static function single_match_score(stdClass $instance, stdClass $attempt, float $base): float {
        if ($attempt->status !== 'won') {
            return 0.0;
        }
        if (!((int) $instance->considererrors && (int) $instance->minquestions >= 1)) {
            return $base;
        }

        $total = (int) $attempt->questions_total;
        $accuracy = $total > 0 ? ((int) $attempt->questions_correct / $total) : 1.0;

        return $base * $accuracy;
    }
}
