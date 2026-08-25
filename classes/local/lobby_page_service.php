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
 * Service to build PlayerPuzzle Lobby (view.php) page state.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use core_useragent;
use moodle_url;
use stdClass;

/**
 * Builds the mod_playerpuzzle/view_lobby template context for view.php.
 */
class lobby_page_service {
    /**
     * Builds full page data for Lobby rendering.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $course Course record.
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @return array Template context for mod_playerpuzzle/view_lobby.
     */
    public static function build_page_data(
        stdClass $cm,
        stdClass $course,
        stdClass $instance,
        int $userid
    ): array {
        $ismobile = core_useragent::is_ios() || core_useragent::is_webkit_android();
        $playparams = ['id' => $cm->id];
        if ($ismobile) {
            $playparams['mobile'] = 1;
        }

        $data = [
            'welcomemsg' => get_string('lobbywelcome', 'mod_playerpuzzle'),
            'playurl' => (new moodle_url('/mod/playerpuzzle/play.php', $playparams))->out(false),
            'playtext' => get_string('playgame', 'mod_playerpuzzle'),
            'sesskey' => sesskey(),
            'ismobile' => $ismobile,
        ];

        $data += self::build_hud_stats_context((int) $course->id, $instance, $userid);
        $data += self::build_progress_context($instance, $userid);
        $data += self::build_minquestions_context($instance);

        return $data;
    }

    /**
     * Builds the PlayerHUD balances context: coins, Sword level, Shield level. Only the
     * items the teacher actually configured are shown — an unconfigured item (id 0) has
     * nothing meaningful to display.
     *
     * @param int $courseid Course ID.
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @return array
     */
    private static function build_hud_stats_context(int $courseid, stdClass $instance, int $userid): array {
        $data = [];

        if (hud_service::is_available_for_course($courseid)) {
            $blockinstanceid = hud_service::get_block_instance_id($courseid);

            if ((int) $instance->hud_coin_item > 0) {
                $balance = hud_service::get_upgrade_level($blockinstanceid, $userid, (int) $instance->hud_coin_item);
                $data['coinstext'] = get_string('lobby_coinbalance', 'mod_playerpuzzle', $balance);
            }

            if ((int) $instance->hud_sword_item > 0) {
                $swordlevel = hud_service::get_upgrade_level($blockinstanceid, $userid, (int) $instance->hud_sword_item);
                $data['swordtext'] = get_string('lobby_swordlevel', 'mod_playerpuzzle', $swordlevel);
            }

            if ((int) $instance->hud_shield_item > 0) {
                $shieldlevel = hud_service::get_upgrade_level($blockinstanceid, $userid, (int) $instance->hud_shield_item);
                $data['shieldtext'] = get_string('lobby_shieldlevel', 'mod_playerpuzzle', $shieldlevel);
            }
        }

        $data['hasstats'] = isset($data['coinstext']) || isset($data['swordtext']) || isset($data['shieldtext']);

        return $data;
    }

    /**
     * Builds the current Campaign progress context: the most recently started
     * in-progress attempt, if any. Single Match mode has no levels/phases to show, so
     * this is skipped entirely for it.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @return array
     */
    private static function build_progress_context(stdClass $instance, int $userid): array {
        global $DB;

        if ($instance->gamemode !== PLAYERPUZZLE_GAMEMODE_CAMPAIGN) {
            return [];
        }

        $inprogress = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'status' => 'inprogress'],
            'timecreated DESC',
            'id, currentlevel, currentphase',
            0,
            1
        );
        $attempt = reset($inprogress);
        if (!$attempt) {
            return [];
        }

        return [
            'progresstext' => get_string('lobby_currentprogress', 'mod_playerpuzzle', (object) [
                'level' => $attempt->currentlevel,
                'phase' => $attempt->currentphase,
            ]),
        ];
    }

    /**
     * Builds the Minimum Questions notice context: the match can require a number of
     * answered questions before it can end in victory, reviving the boss if needed —
     * this must be visible to the student before they start, or the revive would look
     * like a bug.
     *
     * @param stdClass $instance Activity instance.
     * @return array
     */
    private static function build_minquestions_context(stdClass $instance): array {
        if ((int) $instance->minquestions <= 0) {
            return [];
        }

        return [
            'minquestionstext' => get_string(
                'lobby_minquestions_notice',
                'mod_playerpuzzle',
                (int) $instance->minquestions
            ),
        ];
    }
}
