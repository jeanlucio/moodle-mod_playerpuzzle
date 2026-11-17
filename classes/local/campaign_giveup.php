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
 * A student giving up a whole Campaign from the Lobby.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\security;
use stdClass;

/**
 * Giving up a Campaign ends it where it stands, the way winning it or spending its attempts does: it is graded
 * on how far it got, and the student's next Play begins a new one, if the activity allows another. The end is
 * written on the Campaign's last attempt (its `gaveup` column), so everything that reads a Campaign's attempts,
 * the grade and the history included, sees it without a table of its own. An attempt still open is closed
 * first, as abandoned and not as lost: the student may be between two phases, and no phase was fought and lost.
 */
final class campaign_giveup {
    /**
     * Whether the student has a Campaign to give up: a Campaign activity, and a Campaign under way, that is one
     * with a hero and at least one real attempt.
     *
     * @param stdClass $instance Activity instance (id, gamemode, maxlevels, maxattempts, maxcampaigns).
     * @param int $userid User ID.
     * @return bool
     */
    public static function is_available(stdClass $instance, int $userid): bool {
        global $DB;

        if ($instance->gamemode !== constants::GAMEMODE_CAMPAIGN) {
            return false;
        }
        // A Campaign that ended and whose next hero is not chosen yet has no hero row: there is nothing under way.
        $start = game_page_service::next_campaign_start($instance, $userid);
        if (user_hero::get((int) $instance->id, $userid, $start->campaign) === null) {
            return false;
        }

        return $DB->record_exists('playerpuzzle_attempts', [
            'playerpuzzleid' => $instance->id,
            'userid' => $userid,
            'campaign' => $start->campaign,
            'isdemo' => 0,
        ]);
    }

    /**
     * Whether the Campaign the student would give up is the last one the activity allows, so that giving it
     * up ends their play for good, which the confirmation then says.
     *
     * @param stdClass $instance Activity instance (id, maxlevels, maxattempts, maxcampaigns).
     * @param int $userid User ID.
     * @return bool
     */
    public static function is_last_campaign(stdClass $instance, int $userid): bool {
        $limit = (int) $instance->maxcampaigns;

        return $limit > 0 && game_page_service::next_campaign_start($instance, $userid)->campaign >= $limit;
    }

    /**
     * Gives up the student's current Campaign. Held under the same lock as the start of an attempt, so a
     * Play pressed at the same moment never creates a phase of the Campaign being ended.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass $cm Course module, for the completion state.
     * @param int $userid User ID.
     * @return bool Whether a Campaign was given up; false when there was none to give up, or the start lock
     *  could not be had.
     */
    public static function give_up(stdClass $instance, stdClass $cm, int $userid): bool {
        global $CFG;
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $done = security::with_locked_attempt_start(
            $userid,
            (int) $instance->id,
            fn(): bool => self::mark_ended($instance, $userid)
        );
        if ($done !== true) {
            return false;
        }

        // The Campaign now counts as ended, which the grade and the completion rules read.
        playerpuzzle_update_grades($instance, $userid);
        $completioninfo = new \completion_info(get_course((int) $instance->course));
        if ($completioninfo->is_enabled($cm)) {
            $completioninfo->update_state($cm, COMPLETION_COMPLETE, $userid);
        }

        return true;
    }

    /**
     * Writes the end of the Campaign: on the attempt still open, which is closed with it, or else on the last
     * attempt the Campaign finished.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid User ID.
     * @return bool Whether there was a Campaign to end.
     */
    private static function mark_ended(stdClass $instance, int $userid): bool {
        global $DB;

        if (!self::is_available($instance, $userid)) {
            return false;
        }
        $campaign = game_page_service::next_campaign_start($instance, $userid)->campaign;
        $ppid = (int) $instance->id;

        $open = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $ppid, 'userid' => $userid, 'campaign' => $campaign, 'isdemo' => 0, 'status' => 'inprogress'],
            'timecreated DESC, id DESC',
            'id, token',
            0,
            1
        );
        if ($open) {
            $token = (string) reset($open)->token;

            return security::with_locked_attempt(
                $token,
                $ppid,
                $userid,
                function (stdClass $attempt) use ($DB): bool {
                    $attempt->status = 'abandoned';
                    $attempt->gaveup = 1;
                    $attempt->timefinished = time();
                    $attempt->timemodified = $attempt->timefinished;
                    $DB->update_record('playerpuzzle_attempts', $attempt);

                    return true;
                }
            ) === true;
        }

        $last = $DB->get_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND campaign = :campaign AND isdemo = 0 AND status <> :inprogress',
            ['ppid' => $ppid, 'uid' => $userid, 'campaign' => $campaign, 'inprogress' => 'inprogress'],
            'timecreated DESC, id DESC',
            'id',
            0,
            1
        );
        if (!$last) {
            return false;
        }
        // The attempt keeps the time it finished, which the grade reads; timemodified records the give-up, which
        // the cooldown before the next Campaign counts from.
        $DB->update_record('playerpuzzle_attempts', (object) ['id' => reset($last)->id, 'gaveup' => 1, 'timemodified' => time()]);

        return true;
    }
}
