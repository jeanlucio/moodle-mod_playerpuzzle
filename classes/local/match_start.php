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
 * Starting a match, for the game page and for the next match started from inside the game.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context_module;
use mod_playerpuzzle\exception\start_refused;
use moodle_url;
use stdClass;

/**
 * Gets a match ready the one way the game knows: the visitor check, the cooldown, the Campaign's hero, then the
 * attempt itself with its limits and retry cost under the student's lock. play.php runs it for the page, and the
 * game runs it through mod_playerpuzzle_start_match to go on to the next match without leaving the page (and full
 * screen). A start the rules refuse comes back as the page to go to instead, where the refusal is explained.
 */
final class match_start {
    /**
     * Gets the match ready.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid User ID.
     * @param array $request What the student asked for: isdemo (bool), hero (string), confirmnewcampaign (bool),
     *  difficulty (string), chosendifficulty (?string), mobile (bool).
     * @return array ['config' => array|null, 'redirect' => moodle_url|null]: the game's config, or the page to go
     *  to instead.
     * @throws \moodle_exception When a visitor asks for a real attempt, or another start of the same student does
     *  not finish in time.
     */
    public static function begin(
        stdClass $cm,
        stdClass $instance,
        context_module $context,
        int $userid,
        array $request
    ): array {
        $isdemo = !empty($request['isdemo']);
        $returnurl = new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]);
        game_page_service::check_guest_demo_only($isdemo, $returnurl, $context);

        // A Campaign attempt needs the student's hero for that Campaign. The choice screen sends it on with the Play
        // that starts the match, and it is saved here, so the Campaign has it from its first phase. A request without
        // one (the end screen's "Play again" once a Campaign ended, or a stale link) goes back to the Lobby, where the
        // choice, and the warning a new Campaign comes with, begin.
        if (!$isdemo) {
            // Checked before the hero is saved too: choosing the hero of a new Campaign is what starts it, so a
            // Campaign refused by the cooldown would otherwise begin anyway. start_game() checks again under its lock.
            try {
                game_page_service::check_cooldown($instance, $userid, $returnurl);
            } catch (start_refused $refused) {
                return self::refused($cm, $refused);
            }
            $choice = hero_page_service::choose_for_play(
                $instance,
                $userid,
                (string) ($request['hero'] ?? ''),
                !empty($request['confirmnewcampaign'])
            );
            if ($choice === hero_page_service::CHOICE_INVALID) {
                $herourl = new moodle_url('/mod/playerpuzzle/hero.php', ['id' => $cm->id, 'heroerror' => 1]);
                return ['config' => null, 'redirect' => $herourl];
            }
            if ($choice === hero_page_service::CHOICE_LOBBY) {
                return ['config' => null, 'redirect' => $returnurl];
            }
        }

        // The hero a Single Match or a Demo is fought with, from the choice screen; the Campaign's hero is the
        // student's own row. Only a hero from the catalog counts, and the last one chosen for a Single Match is
        // remembered, never one for a Demo.
        $hero = (string) ($request['hero'] ?? '');
        if (!heroes::is_valid($hero)) {
            $hero = array_key_first(heroes::CATALOG);
        } else if (!$isdemo && $instance->gamemode === constants::GAMEMODE_SINGLE) {
            last_hero::set($userid, $hero);
        }

        // For a real attempt this also runs the gates that decide whether a new one may begin (attempt limit,
        // cooldown, retry cost), under the lock that stops two simultaneous requests from both passing them.
        try {
            $config = game_page_service::start_game(
                $cm,
                $instance,
                $context,
                $userid,
                !empty($request['mobile']),
                (string) ($request['difficulty'] ?? constants::DIFFICULTY_NORMAL),
                $isdemo,
                $returnurl,
                $hero,
                $request['chosendifficulty'] ?? null
            );
        } catch (start_refused $refused) {
            return self::refused($cm, $refused);
        }

        return ['config' => $config, 'redirect' => null];
    }

    /**
     * What the game's page shows around the game for a match: the loading screen, the opponent's card, How to play
     * and the give up confirmation, for mod_playerpuzzle/game_parts.
     *
     * @param stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid User ID.
     * @param array $config The match's config, from begin().
     * @return array Template context.
     */
    public static function parts_context(stdClass $instance, context_module $context, int $userid, array $config): array {
        $isdemo = !empty($config['isdemo']);
        return [
            'loadingtext' => get_string('loadinggame', 'mod_playerpuzzle'),
            'opponenthelp' => [
                'closelabel' => get_string('close', 'mod_playerpuzzle'),
            ] + opponent_card::for_fight($config),
            'giveup' => game_page_service::giveup_dialog(
                $isdemo,
                !$isdemo && (game_page_service::attempt_position($instance, $userid)['last'] ?? false)
            ),
            'howtoplay' => howtoplay::context($instance, is_guest($context, $userid)),
        ];
    }

    /**
     * The Lobby, where a refused start is explained in a dialog rather than on an error page.
     *
     * @param stdClass $cm Course module.
     * @param start_refused $refused Why the start was refused.
     * @return array ['config' => null, 'redirect' => moodle_url]
     */
    private static function refused(stdClass $cm, start_refused $refused): array {
        $lobbyurl = new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id, 'refused' => $refused->errorcode]);
        return ['config' => null, 'redirect' => $lobbyurl];
    }
}
