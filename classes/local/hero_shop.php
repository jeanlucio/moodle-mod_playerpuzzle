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
 * The hero upgrade shop: what a student can buy for their Campaign hero, and the buying.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\security;
use moodle_exception;
use stdClass;

/**
 * Sells upgrades of HP, attack and armor for PuzzleCoin, up to the cap of the hero's stage, and moves the
 * hero up a stage when its conditions are met (see heroes). The shop is the same one in the Lobby and on the
 * screen between phases. What is bought counts from the next phase: a phase freezes the hero's side when
 * it starts, and one that has not been played yet is frozen again.
 */
class hero_shop {
    /**
     * Buys one upgrade of an attribute for the student's Campaign hero, spending PuzzleCoin.
     *
     * @param stdClass $instance Activity instance (id, gamemode, maxlevels, maxattempts, basestudenthp).
     * @param int $userid User ID.
     * @param string $attribute One of heroes::ATTRIBUTES.
     * @return array ['newcoinbalance' => int, 'bought' => int, 'cap' => int, 'stage' => int, 'evolved' => bool,
     *  'sprite' => string]
     * @throws moodle_exception When it is not an upgrade, the activity has no hero to upgrade, the stage's cap is
     *  reached, the balance is short, or another purchase of the student is under way.
     */
    public static function buy(stdClass $instance, int $userid, string $attribute): array {
        if (!in_array($attribute, heroes::ATTRIBUTES, true)) {
            throw new moodle_exception('upgradeinvalid', 'mod_playerpuzzle');
        }

        $ppid = (int) $instance->id;
        $result = security::with_locked_user_stock($userid, $ppid, function () use ($instance, $userid, $attribute, $ppid): array {
            global $DB;

            $hero = game_page_service::current_hero($instance, $userid);
            if ($hero === null) {
                throw new moodle_exception('upgradeunavailable', 'mod_playerpuzzle');
            }
            $maxlevels = (int) $instance->maxlevels;
            $buys = user_hero::buys($hero);
            $cap = heroes::stage_cap($maxlevels, (int) $hero->stage);
            if ($buys[$attribute] >= $cap) {
                throw new moodle_exception('upgradecapreached', 'mod_playerpuzzle');
            }
            if (!user_stock::debit($userid, $ppid, user_stock::CURRENCY_TYPE, heroes::PRICE)) {
                throw new moodle_exception('insufficientcoins', 'mod_playerpuzzle');
            }

            $buys[$attribute]++;
            $stage = heroes::evolved_stage(
                $hero->hero,
                $buys,
                (int) $hero->stage,
                $maxlevels,
                campaigns::phases_won($ppid, $userid, (int) $hero->campaign)
            );
            $DB->update_record('playerpuzzle_heroes', (object) [
                'id' => $hero->id,
                $attribute . 'buys' => $buys[$attribute],
                'stage' => $stage,
                'timemodified' => time(),
            ]);

            return [
                'newcoinbalance' => user_stock::get_quantity($userid, $ppid, user_stock::CURRENCY_TYPE),
                'bought' => $buys[$attribute],
                'cap' => $cap,
                'stage' => $stage,
                'evolved' => $stage > (int) $hero->stage,
                'sprite' => heroes::sprite($hero->hero, $stage),
            ];
        });
        if ($result === false) {
            // Another purchase of the same student is changing the balance right now: a double click or a
            // second tab, not a coding mistake.
            throw new moodle_exception('stockpurchaselocked', 'mod_playerpuzzle');
        }

        self::refresh_fresh_phase($instance, $userid);

        return $result;
    }

    /**
     * Moves the hero up the stages it has earned, called when the Campaign advances a phase: the milestone may
     * have been passed with the specialty already at the cap.
     *
     * @param stdClass $instance Activity instance (id, maxlevels).
     * @param int $userid User ID.
     * @param int $campaign Campaign number.
     * @param int $completed Phases of the Campaign already won.
     * @return bool Whether the hero evolved.
     */
    public static function evolve(stdClass $instance, int $userid, int $campaign, int $completed): bool {
        global $DB;

        $hero = user_hero::get((int) $instance->id, $userid, $campaign);
        if ($hero === null) {
            return false;
        }
        $stage = heroes::evolved_stage(
            $hero->hero,
            user_hero::buys($hero),
            (int) $hero->stage,
            (int) $instance->maxlevels,
            $completed
        );
        if ($stage === (int) $hero->stage) {
            return false;
        }
        $DB->update_record('playerpuzzle_heroes', (object) [
            'id' => $hero->id,
            'stage' => $stage,
            'timemodified' => time(),
        ]);

        return true;
    }

    /**
     * Gives a phase the student has not played yet what they have now: their hero's current side.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid User ID.
     * @return void
     */
    private static function refresh_fresh_phase(stdClass $instance, int $userid): void {
        global $DB;

        $inprogress = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'status' => 'inprogress', 'isdemo' => 0],
            'timecreated DESC, id DESC',
            'id',
            0,
            1
        );
        $attempt = reset($inprogress);
        if ($attempt) {
            security::refresh_student_side((int) $attempt->id, $instance);
        }
    }
}
