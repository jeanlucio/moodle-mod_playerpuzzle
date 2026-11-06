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
 * The student's own history of Campaigns, for the Lobby.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use stdClass;

/**
 * One line for each Campaign a student has played: how it stands, the hero, how far it got, the attempts it used
 * and its points. Only reads what the Campaigns already record, and only for the student asking.
 */
final class campaign_history {
    /** @var string A Campaign whose last phase was won. */
    private const WON = 'won';

    /** @var string A Campaign that used every attempt it had. */
    private const SPENT = 'spent';

    /** @var string A Campaign still being played. */
    private const CURRENT = 'current';

    /**
     * The Lobby's history panel for a student: a row for each Campaign, the latest first. The points are the
     * ones the ranking counts (grade_calculator::campaign_score() against ranking_service::BASE_POINTS).
     *
     * @param stdClass $instance Activity instance (id, maxlevels, maxattempts, considererrors, minquestions).
     * @param int $userid The student.
     * @return array ['historyrows' => list of rows, see row(); 'historyhasrows' => bool].
     */
    public static function context(stdClass $instance, int $userid): array {
        global $DB;

        $attempts = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'isdemo' => 0],
            'id ASC',
            'id, campaign, status, currentlevel, currentphase, questions_correct, questions_total, timecreated, '
                . 'timefinished'
        );
        $heroes = user_hero::for_student((int) $instance->id, $userid);

        $rows = [];
        foreach (array_reverse(campaigns::group(array_values($attempts)), true) as $number => $group) {
            $rows[] = self::row($instance, (int) $number, $group, $heroes[$number] ?? null);
        }

        return ['historyrows' => $rows, 'historyhasrows' => $rows !== []];
    }

    /**
     * One Campaign's line.
     *
     * @param stdClass $instance Activity instance.
     * @param int $number The Campaign's number.
     * @param stdClass[] $attempts The attempts of that Campaign.
     * @param stdClass|null $hero The Campaign's hero row, or null for one from before heroes.
     * @return array The template data: title, statustext and statusclass, pointstext, phasestext, attemptstext,
     *  datestext and, when it has a hero, herohas, heroname, heroimageurl and herostagetext.
     */
    private static function row(stdClass $instance, int $number, array $attempts, ?stdClass $hero): array {
        $state = self::state($instance, $attempts);
        $finishedattempts = count(array_filter(
            $attempts,
            static fn(stdClass $attempt): bool => $attempt->status !== 'inprogress'
        ));
        $maxattempts = (int) ($instance->maxattempts ?? 0);
        $points = grade_calculator::campaign_score($instance, $attempts, ranking_service::BASE_POINTS);

        $row = [
            'title' => get_string('lobby_campaignname', 'mod_playerpuzzle', $number),
            'statustext' => get_string('history_status_' . $state, 'mod_playerpuzzle'),
            'statusclass' => 'pp-history-' . $state,
            'pointstext' => get_string('history_points', 'mod_playerpuzzle', format_float($points, 1, true, true)),
            'phasestext' => get_string('history_phases', 'mod_playerpuzzle', (object) [
                'won' => campaigns::phases_won_of($attempts),
                'total' => max(1, (int) $instance->maxlevels) * 10,
            ]),
            'attemptstext' => $maxattempts > 0
                ? get_string('history_attempts_of', 'mod_playerpuzzle', (object) [
                    'used' => $finishedattempts,
                    'max' => $maxattempts,
                ])
                : get_string('history_attempts', 'mod_playerpuzzle', $finishedattempts),
            'datestext' => self::dates($attempts, $state !== self::CURRENT),
            'herohas' => $hero !== null,
        ];
        if ($hero !== null) {
            $row['heroname'] = get_string('hero_name_' . $hero->hero, 'mod_playerpuzzle');
            $row['heroimageurl'] = heroes::sprite_url(heroes::sprite($hero->hero, (int) $hero->stage))->out(false);
            $row['herostagetext'] = get_string('shop_hero_stage', 'mod_playerpuzzle', (object) [
                'stage' => (int) $hero->stage,
                'total' => heroes::STAGES,
            ]);
        }

        return $row;
    }

    /**
     * How a Campaign stands: won, out of attempts, or still going.
     *
     * @param stdClass $instance Activity instance (maxattempts).
     * @param stdClass[] $attempts The attempts of one Campaign.
     * @return string One of the state constants.
     */
    private static function state(stdClass $instance, array $attempts): string {
        foreach ($attempts as $attempt) {
            if ($attempt->status === 'won') {
                return self::WON;
            }
        }

        return campaigns::is_finished($instance, $attempts) ? self::SPENT : self::CURRENT;
    }

    /**
     * When a Campaign was played: the day it started and, once it ended, the day it did.
     *
     * @param stdClass[] $attempts The attempts of one Campaign.
     * @param bool $ended Whether the Campaign has ended.
     * @return string The date, or the two dates with a dash between them.
     */
    private static function dates(array $attempts, bool $ended): string {
        $format = get_string('strftimedatefullshort', 'langconfig');
        $from = userdate(min(array_map(static fn(stdClass $a): int => (int) $a->timecreated, $attempts)), $format);
        $last = max(array_map(static fn(stdClass $a): int => (int) $a->timefinished, $attempts));
        if (!$ended || $last <= 0) {
            return $from;
        }
        $to = userdate($last, $format);

        return $from === $to ? $from : get_string('history_period', 'mod_playerpuzzle', (object) [
            'from' => $from,
            'to' => $to,
        ]);
    }
}
