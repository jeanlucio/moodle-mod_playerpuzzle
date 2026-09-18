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
 * External function to save player progress after a game session.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use completion_info;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playerpuzzle\local\attempt_questions;
use mod_playerpuzzle\local\coin_ledger;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\hud_service;
use moodle_exception;

/**
 * Saves the player's coin rewards and game result to the inventory.
 */
class save_progress extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'                 => new external_value(PARAM_INT, 'Course module ID'),
            'token'                => new external_value(PARAM_ALPHANUM, 'Anti-replay token issued when the attempt started'),
            'victory'              => new external_value(PARAM_INT, 'Whether it was a victory (1) or defeat (0)'),
            'damage'               => new external_value(PARAM_INT, 'Damage dealt to the boss'),
            'coinsearnedsofar'     => new external_value(PARAM_INT, 'Player coins earned so far this phase/match, client-reported'),
            'bosscoinsearnedsofar' => new external_value(PARAM_INT, 'Boss coins earned so far this phase/match, client-reported'),
        ]);
    }

    /**
     * Consumes the attempt token, persists the attempt outcome, and credits coins on victory —
     * the amount banked comes from the server's own coin ledger (coin_ledger::available()),
     * never from a client-reported total.
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token issued when the attempt started.
     * @param int $victory Whether it was a victory.
     * @param int $damage Damage dealt to the boss.
     * @param int $coinsearnedsofar Player coins earned so far this phase/match, client-reported.
     * @param int $bosscoinsearnedsofar Boss coins earned so far this phase/match, client-reported.
     * @return array Result with status, message, and coins banked.
     */
    public static function execute(
        int $cmid,
        string $token,
        int $victory,
        int $damage,
        int $coinsearnedsofar,
        int $bosscoinsearnedsofar
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'                 => $cmid,
            'token'                => $token,
            'victory'              => $victory,
            'damage'               => $damage,
            'coinsearnedsofar'     => $coinsearnedsofar,
            'bosscoinsearnedsofar' => $bosscoinsearnedsofar,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        $isvictory = $params['victory'] === 1;
        $finalstatus = $isvictory ? 'won' : 'lost';

        // Backstop against a claimed victory that bypasses the client-side boss-revive rule
        // entirely (a forged request, or a genuine client bug) — checked before the token is
        // consumed below, so a rejected claim leaves the attempt resumable instead of wasting
        // it. The client should never actually reach this: the revive keeps the boss alive
        // until enough questions are answered.
        if ($isvictory) {
            $pending = $DB->get_record('playerpuzzle_attempts', [
                'token'          => $params['token'],
                'playerpuzzleid' => (int) $playerpuzzle->id,
                'userid'         => (int) $USER->id,
                'status'         => 'inprogress',
            ]);
            if ($pending && (int) $pending->questions_total < (int) $playerpuzzle->minquestions) {
                throw new moodle_exception('minquestionsnotmet', 'mod_playerpuzzle', '', (int) $playerpuzzle->minquestions);
            }
        }

        $attempt = security::validate_and_consume_token(
            $params['token'],
            (int) $playerpuzzle->id,
            (int) $USER->id,
            $finalstatus
        );
        if (!$attempt) {
            // Token unknown, already consumed, or belongs to a different user/instance:
            // this is a replay or forged submission, not a coding mistake.
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        // Sanity check: damage can never exceed the boss HP the server itself calculated for
        // the attempt's own level/phase and difficulty — never the raw configured base, which
        // would wrongly cap every Campaign attempt to the Level 1, Phase 1 value regardless of
        // how far the student actually progressed (Single Match always carries currentlevel =
        // currentphase = 1, so the formula returns the base HP unchanged there). The same
        // difficulty factor game_page_service used to build the fight is applied here, so a
        // Hard-mode loss is scored against the doubled boss HP it was really fighting. A Demo
        // attempt always fought the fixed combat::DEMO_HP instead, matching what the client
        // was actually shown for that fight.
        $isdemo = (bool) $attempt->isdemo;
        $bosshp = $isdemo ? combat::DEMO_HP : combat::apply_difficulty(
            combat::calculate_boss_hp(
                (int) $playerpuzzle->basebosshp,
                (int) $attempt->currentlevel,
                (int) $attempt->currentphase
            ),
            (string) $attempt->difficulty
        );
        $safedamage = max(0, min($params['damage'], $bosshp));
        $attempt->bosshp_remaining = max(0, $bosshp - $safedamage);
        $attempt->score = round(($safedamage / max(1, $bosshp)) * 100, 5);

        // Coin ledger: the amount actually banked below comes from coins_earned/boss_coins_earned/
        // coins_spent, never from a raw client-reported gold total — the client's own report is
        // only trusted up to a plausibility ceiling sized to this phase's own boss HP (a stable
        // value, not tied to damage dealt — see combat::coin_ceiling()'s own docblock for why).
        $scaledbossdamage = combat::apply_difficulty(
            combat::calculate_boss_hp(
                (int) $playerpuzzle->bossdamage,
                (int) $attempt->currentlevel,
                (int) $attempt->currentphase
            ),
            (string) $attempt->difficulty
        );
        $ceiling = combat::coin_ceiling(
            $bosshp,
            $scaledbossdamage,
            (int) $playerpuzzle->coingain,
            combat::difficulty_coin_factor((string) $attempt->difficulty)
        );
        coin_ledger::sync($attempt, $params['coinsearnedsofar'], $params['bosscoinsearnedsofar'], $ceiling);
        // The attempt just reached a final status — no fight left to resume.
        $attempt->combatstate = null;
        $DB->update_record('playerpuzzle_attempts', $attempt);

        $event = \mod_playerpuzzle\event\game_completed::create([
            'objectid' => $attempt->id,
            'context'  => $context,
            'other'    => [
                'gamemode'     => $playerpuzzle->gamemode,
                'status'       => $finalstatus,
                'currentlevel' => (int) $attempt->currentlevel,
                'currentphase' => (int) $attempt->currentphase,
                'score'        => (float) $attempt->score,
            ],
        ]);
        $event->trigger();

        $coinsbanked = 0;
        if ($isvictory && !$isdemo) {
            // Defeat/timeout discards the session's coins; only a win banks them, and only into
            // the item the teacher configured — PlayerPuzzle keeps no local currency of its own.
            // A Demo win never banks anything: it is a disposable practice fight,
            // repeatable at will, and would otherwise let coins/XP be farmed without limit.
            $payable = coin_ledger::available($attempt);
            $blockinstanceid = hud_service::get_block_instance_id((int) $playerpuzzle->course);
            if ($blockinstanceid !== null) {
                $banked = hud_service::credit_coins(
                    $blockinstanceid,
                    (int) $USER->id,
                    (int) $playerpuzzle->hud_coin_item,
                    $payable
                );
                $coinsbanked = $banked ? $payable : 0;

                // Win-grant item, separate from the coin balance. XP is withheld when the
                // attempt limit relevant to this instance's own game mode is Unlimited (0) —
                // the same anti-farming rule mod_playerwords already applies to its own
                // infinite-round win grant.
                $grantitem = (int) $playerpuzzle->hud_win_grant_item;
                if ($grantitem > 0) {
                    $relevantlimit = $playerpuzzle->gamemode === PLAYERPUZZLE_GAMEMODE_SINGLE
                        ? (int) $playerpuzzle->max_single_matches
                        : (int) $playerpuzzle->maxattempts;
                    hud_service::grant_item(
                        $blockinstanceid,
                        (int) $USER->id,
                        $grantitem,
                        max(1, (int) $playerpuzzle->hud_win_grant_qty),
                        $relevantlimit === 0
                    );
                }
            }
        }

        if (!$isdemo) {
            // The attempt just reached a final status either way (won or lost/timeout) — both
            // outcomes are new information the gradebook needs: a win may be this student's
            // best score yet, and even a loss finalizes a Single Match round grade_calculator
            // must now count among their finished matches. A Demo attempt is excluded from
            // playerpuzzle_update_grades()'s own query too (belt and suspenders), but skipping
            // the call outright avoids a pointless recompute on every Demo play.
            playerpuzzle_update_grades($playerpuzzle, (int) $USER->id);

            // Automatic completion (the "require attempts"/"require wins" custom rules) is only
            // recomputed and persisted when something explicitly asks for it — Moodle has no
            // cron sweep for this, unlike grading. Trigger it here so the activity page's
            // completion badge reflects a finished attempt immediately, the same way
            // mod_choice/mod_playerwords call update_state() right after recording a response.
            // A Demo attempt must never satisfy completion — see custom_completion.php's own
            // isdemo exclusion for why an unlimited free practice fight cannot count here.
            $course = get_course((int) $playerpuzzle->course);
            $completioninfo = new completion_info($course);
            if ($completioninfo->is_enabled($cm)) {
                $completioninfo->update_state($cm, COMPLETION_COMPLETE, (int) $USER->id);
            }
        }

        return [
            'status'      => 'success',
            'message'     => get_string('progresssaved', 'mod_playerpuzzle', $coinsbanked),
            'coinsbanked' => $coinsbanked,
            'questionlog' => attempt_questions::get_phase_log(
                (int) $attempt->id,
                (int) $attempt->currentlevel,
                (int) $attempt->currentphase
            ),
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'      => new external_value(PARAM_ALPHA, 'Success status'),
            'message'     => new external_value(PARAM_TEXT, 'Feedback message for the player'),
            'coinsbanked' => new external_value(PARAM_INT, 'Coins banked into PlayerHUD this session'),
            'questionlog' => new external_multiple_structure(
                new external_single_structure([
                    'questiontext'  => new external_value(PARAM_RAW, 'Question text'),
                    'chosenanswer'  => new external_value(PARAM_RAW, 'Answer the student chose'),
                    'correctanswer' => new external_value(PARAM_RAW, 'The correct answer'),
                    'iscorrect'     => new external_value(PARAM_BOOL, 'Whether the chosen answer was correct'),
                ]),
                'The questions answered this phase, for the post-game review'
            ),
        ]);
    }
}
