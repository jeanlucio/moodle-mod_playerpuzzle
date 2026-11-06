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
 * The student's Campaigns: where the next one starts, when one has ended, which ones grade.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use stdClass;

/**
 * A Campaign is one complete game, from Level 1, Phase 1 until it is won or runs out of attempts
 * (defeats and restarts, limited by maxattempts in each Campaign). Once it ends the student may play
 * another, up to maxcampaigns, and every attempt row records which Campaign it belongs to.
 *
 * Only real Campaign-mode attempts have Campaigns: a Demo and every Single Match stay on the first.
 */
class campaigns {
    /** @var string A Campaign that ended because its last phase was won. */
    public const ENDED_WON = 'won';

    /** @var string A Campaign that ended because it ran out of attempts. */
    public const ENDED_ATTEMPTS = 'attempts';

    /**
     * Where a brand new real attempt starts, from the student's most recently finished one: on the
     * phase that attempt lost, in the same Campaign, or at Level 1, Phase 1 of the next Campaign when
     * the last one was won or spent its last attempt. Ordered by id as well as timecreated, so two
     * attempts finished within the same second still have one well-defined "most recent".
     *
     * @param int $playerpuzzleid Activity instance ID.
     * @param int $userid User ID.
     * @param int $maxlevels The instance's level count, already at least 1.
     * @param int $maxattempts Attempts allowed in each Campaign, 0 for unlimited.
     * @return stdClass ->campaign, ->level, ->phase, ->startsnew (true when this begins a Campaign after
     *  an earlier one ended) and ->ended (self::ENDED_* for the Campaign that ended, '' otherwise).
     */
    public static function next_start(int $playerpuzzleid, int $userid, int $maxlevels, int $maxattempts): stdClass {
        global $DB;

        $recent = $DB->get_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0',
            ['ppid' => $playerpuzzleid, 'uid' => $userid, 'inprogress' => 'inprogress'],
            'timecreated DESC, id DESC',
            'id, status, currentlevel, currentphase, campaign',
            0,
            1
        );
        $last = reset($recent);
        if (!$last) {
            return self::start(1, 1, 1, '');
        }

        $campaign = (int) $last->campaign;
        if ($last->status === 'won') {
            return self::start($campaign + 1, 1, 1, self::ENDED_WON);
        }
        if ($maxattempts > 0) {
            $finished = $DB->count_records_select(
                'playerpuzzle_attempts',
                'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0 AND campaign = :campaign',
                ['ppid' => $playerpuzzleid, 'uid' => $userid, 'inprogress' => 'inprogress', 'campaign' => $campaign]
            );
            if ($finished >= $maxattempts) {
                return self::start($campaign + 1, 1, 1, self::ENDED_ATTEMPTS);
            }
        }

        $level = (int) $last->currentlevel;
        $phase = (int) $last->currentphase;
        if ($level > $maxlevels) {
            // The teacher has since reduced the level count below where the student had reached: the
            // Campaign resumes at the start of the new last level, since the phase reached no longer
            // exists.
            return self::start($campaign, $maxlevels, 1, '');
        }

        return self::start($campaign, $level, $phase, '');
    }

    /**
     * How many phases of a Campaign the student has won: the furthest any of its attempts got. An attempt
     * that is not won is sitting on the phase it is fighting, so only the ones before it count; a won attempt
     * cleared its last phase too.
     *
     * @param int $playerpuzzleid Activity instance ID.
     * @param int $userid User ID.
     * @param int $campaign Campaign number.
     * @return int Phases won, 0 or more.
     */
    public static function phases_won(int $playerpuzzleid, int $userid, int $campaign): int {
        global $DB;

        return self::phases_won_of($DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $playerpuzzleid, 'userid' => $userid, 'campaign' => $campaign, 'isdemo' => 0],
            '',
            'id, status, currentlevel, currentphase'
        ));
    }

    /**
     * How many phases the given attempts of one Campaign have won, for a caller that already has them.
     *
     * @param stdClass[] $attempts The attempts of one Campaign (status, currentlevel, currentphase).
     * @return int Phases won, 0 or more.
     */
    public static function phases_won_of(array $attempts): int {
        $won = 0;
        foreach ($attempts as $attempt) {
            $ordinal = (((int) $attempt->currentlevel - 1) * 10) + (int) $attempt->currentphase;
            $won = max($won, $attempt->status === 'won' ? $ordinal : $ordinal - 1);
        }

        return max(0, $won);
    }

    /**
     * Groups attempts by Campaign, the first Campaign first. An attempt read without the column (an
     * older caller) counts as the first Campaign.
     *
     * @param stdClass[] $attempts Attempts of one student on one instance.
     * @return array Attempt lists keyed by Campaign number, in ascending order.
     */
    public static function group(array $attempts): array {
        $groups = [];
        foreach ($attempts as $attempt) {
            $groups[(int) ($attempt->campaign ?? 1)][] = $attempt;
        }
        ksort($groups);

        return $groups;
    }

    /**
     * Whether a Campaign has ended: one of its attempts won the last phase, or it has used every
     * attempt the instance allows in a Campaign.
     *
     * @param stdClass $instance Activity instance (maxattempts).
     * @param stdClass[] $attempts The attempts of that one Campaign.
     * @return bool
     */
    public static function is_finished(stdClass $instance, array $attempts): bool {
        $finished = 0;
        foreach ($attempts as $attempt) {
            if ($attempt->status === 'won') {
                return true;
            }
            if ($attempt->status !== 'inprogress') {
                $finished++;
            }
        }
        $maxattempts = (int) ($instance->maxattempts ?? 0);

        return $maxattempts > 0 && $finished >= $maxattempts;
    }

    /**
     * The Campaigns that count towards the grade: every Campaign that has ended, and the first one
     * even while it is still being played, so a student who never finishes it is still graded on how
     * far they got. A later Campaign only counts once it ends, so starting another one never pulls a
     * "last" or "average" grade down before it has been played.
     *
     * @param stdClass $instance Activity instance (maxattempts).
     * @param stdClass[] $attempts Attempts of one student on one instance.
     * @return array Attempt lists keyed by Campaign number, in ascending order.
     */
    public static function graded(stdClass $instance, array $attempts): array {
        $groups = self::group($attempts);
        $first = array_key_first($groups);

        return array_filter(
            $groups,
            static fn(array $group, int $number): bool => $number === $first || self::is_finished($instance, $group),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Builds next_start()'s return value.
     *
     * @param int $campaign Campaign number.
     * @param int $level Level.
     * @param int $phase Phase.
     * @param string $ended self::ENDED_* for the Campaign that just ended, or ''.
     * @return stdClass
     */
    private static function start(int $campaign, int $level, int $phase, string $ended): stdClass {
        return (object) [
            'campaign' => $campaign,
            'level' => $level,
            'phase' => $phase,
            'startsnew' => $ended !== '',
            'ended' => $ended,
        ];
    }
}
