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
 * Abandoning a match from the Lobby.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\replay;
use mod_playerpuzzle\local\engine\security;
use stdClass;

/**
 * Abandoning, from the Lobby, the phase a student left in progress: the same as giving up inside the game. The phase
 * ends as a defeat, the attempt is spent and its coins are lost, scored by the damage the server's replay finds in
 * its log. Only a phase already under way can be abandoned; one nothing has happened in yet has nothing to abandon,
 * and its difficulty can still be changed.
 */
final class match_abandon {
    /**
     * The student's real attempt with a phase under way, or null when there is none.
     *
     * @param stdClass $instance Activity instance (id).
     * @param int $userid User ID.
     * @return stdClass|null The attempt row.
     */
    public static function open_attempt(stdClass $instance, int $userid): ?stdClass {
        global $DB;

        $open = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'status' => 'inprogress', 'isdemo' => 0],
            'timecreated DESC, id DESC',
            '*',
            0,
            1
        );
        $attempt = reset($open) ?: null;
        if ($attempt === null || security::phase_not_started($attempt)) {
            return null;
        }
        return $attempt;
    }

    /**
     * Whether the student has a match to abandon.
     *
     * @param stdClass $instance Activity instance (id).
     * @param int $userid User ID.
     * @return bool
     */
    public static function is_available(stdClass $instance, int $userid): bool {
        return self::open_attempt($instance, $userid) !== null;
    }

    /**
     * Abandons the student's match in progress, as a defeat, then brings the grade and the completion up to date
     * the way the end of any match does.
     *
     * @param stdClass $instance Activity instance.
     * @param stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param int $userid User ID.
     * @return bool Whether a match was abandoned; false when there was none, or another request held it.
     */
    public static function abandon(stdClass $instance, stdClass $cm, \context_module $context, int $userid): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $attempt = self::open_attempt($instance, $userid);
        if ($attempt === null) {
            return false;
        }

        $ended = security::with_locked_attempt(
            (string) $attempt->token,
            (int) $instance->id,
            $userid,
            function (stdClass $locked) use ($DB, $instance): ?stdClass {
                // Read again under the lock: the match may have ended in another tab meanwhile.
                if ($locked->status !== 'inprogress' || !empty($locked->isdemo) || security::phase_not_started($locked)) {
                    return null;
                }
                $derived = replay::derive($locked, $instance);
                $bosshp = combat::phase_opponent($locked, $instance)['maxbosshp'];
                $damage = max(0, min((int) ($derived['damage'] ?? 0), $bosshp));

                $locked->bosshp_remaining = $bosshp - $damage;
                $locked->score = round(($damage / max(1, $bosshp)) * 100, 5);
                $locked->status = 'lost';
                $locked->timefinished = time();
                $locked->timemodified = $locked->timefinished;
                $locked->combatstate = null;
                $DB->update_record('playerpuzzle_attempts', $locked);

                return $locked;
            }
        );
        if (!$ended instanceof stdClass) {
            return false;
        }

        \mod_playerpuzzle\event\game_completed::create([
            'objectid' => $ended->id,
            'context'  => $context,
            'other'    => [
                'gamemode'     => $instance->gamemode,
                'status'       => $ended->status,
                'currentlevel' => (int) $ended->currentlevel,
                'currentphase' => (int) $ended->currentphase,
                'score'        => (float) $ended->score,
            ],
        ])->trigger();

        playerpuzzle_update_grades($instance, $userid);
        $completioninfo = new \completion_info(get_course((int) $instance->course));
        if ($completioninfo->is_enabled($cm)) {
            $completioninfo->update_state($cm, COMPLETION_COMPLETE, $userid);
        }

        return true;
    }
}
