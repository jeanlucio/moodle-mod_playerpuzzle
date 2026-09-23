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
 * External function to advance a Campaign attempt to its next phase.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playerpuzzle\local\attempt_consumables;
use mod_playerpuzzle\local\coin_ledger;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\hud_service;
use mod_playerpuzzle\local\move_log;
use mod_playerpuzzle\local\replay_credit;
use mod_playerpuzzle\local\user_stock;
use moodle_exception;

/**
 * Advances a Campaign attempt to its next phase (or next level, every 10th phase) after
 * the boss for the current phase has genuinely been defeated.
 *
 * Unlike save_progress, the attempt is never finalised here: it stays 'inprogress' and
 * the same row continues — winning a phase never opens a new attempt. The token is
 * rotated on every call (the old one becomes invalid immediately), the same
 * anti-replay guarantee save_progress gives its own final submission, so a captured
 * advance_phase request cannot be replayed to skip ahead a second time.
 */
class advance_phase extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'                 => new external_value(PARAM_INT, 'Course module ID'),
            'token'                => new external_value(PARAM_ALPHANUM, 'Anti-replay token issued when the attempt started'),
            'damage'               => new external_value(PARAM_INT, 'Damage dealt to the boss this phase'),
            'coinsearnedsofar'     => new external_value(PARAM_INT, 'Player coins earned this phase, client-reported'),
            'bosscoinsearnedsofar' => new external_value(PARAM_INT, 'Boss coins earned this phase, client-reported'),
            'difficulty'           => new external_value(
                PARAM_ALPHA,
                'Difficulty chosen for the next phase (easy/normal/hard); coerced to a known value',
                VALUE_DEFAULT,
                'normal'
            ),
            'eventoffset'          => new external_value(
                PARAM_INT,
                "Index in the phase's event log of the first event in movelog",
                VALUE_DEFAULT,
                0
            ),
            'movelog'              => save_combat_state::movelog_structure(),
        ]);
    }

    /**
     * Validates the phase was genuinely won, banks this phase's coins from the server's own
     * ledger (never a client-reported total), advances the attempt to its next phase (or
     * level) with a clean ledger, and returns the scaled HP for the new phase together with
     * a fresh token.
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token issued when the attempt started.
     * @param int $damage Damage dealt to the boss this phase.
     * @param int $coinsearnedsofar Player coins earned this phase, client-reported.
     * @param int $bosscoinsearnedsofar Boss coins earned this phase, client-reported.
     * @param string $difficulty Difficulty chosen for the next phase.
     * @param int $eventoffset Index in the phase's event log of the first event in $movelog.
     * @param array $movelog Combat events the last checkpoint had not sent yet, in order.
     * @return array Result with the new token, level, phase, difficulty, scaled boss/student HP, and coins banked.
     */
    public static function execute(
        int $cmid,
        string $token,
        int $damage,
        int $coinsearnedsofar,
        int $bosscoinsearnedsofar,
        string $difficulty = 'normal',
        int $eventoffset = 0,
        array $movelog = []
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'                 => $cmid,
            'token'                => $token,
            'damage'               => $damage,
            'coinsearnedsofar'     => $coinsearnedsofar,
            'bosscoinsearnedsofar' => $bosscoinsearnedsofar,
            'difficulty'           => $difficulty,
            'eventoffset'          => $eventoffset,
            'movelog'              => $movelog,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        // The whole phase-advance mutation (win checks, coin/item credit, token rotation)
        // runs inside the attempt's own lock: two requests racing the same token (a
        // double-click, or a captured request replayed in parallel) must never both see
        // 'inprogress' and both credit the phase's reward — see
        // security::with_locked_attempt()'s own docblock.
        $result = security::with_locked_attempt(
            $params['token'],
            (int) $playerpuzzle->id,
            (int) $USER->id,
            function (\stdClass $attempt) use ($DB, $USER, $playerpuzzle, $params, $context): array {
                if ((bool) $attempt->isdemo) {
                    // A Demo is always a one-shot fight reported to the client as gamemode
                    // 'single' (see game_page_service::build_game_config()) — combat.js's own
                    // gamemode gate means it never legitimately calls this endpoint. Refused
                    // outright rather than silently handled, so a forged request cannot use a
                    // zero-stakes Demo attempt to probe phase-advance behaviour.
                    throw new moodle_exception('demoattemptnoadvance', 'mod_playerpuzzle');
                }

                $currentlevel = (int) $attempt->currentlevel;
                $currentphase = (int) $attempt->currentphase;

                // The phase's last events usually land after the last periodic checkpoint, so
                // they ride along with this call (see save_progress.php for the same step).
                if (move_log::is_valid($params['movelog'])) {
                    move_log::merge_into_attempt($attempt, $params['eventoffset'], $params['movelog']);
                }

                // The phase only advances on a victory the replay verifies. One it cannot
                // verify restarts the phase within this same attempt (up to a cap), and one it
                // finds to be a defeat is left for save_progress to record — the client moves
                // to its defeat screen, which makes that call — see replay_credit::verdict().
                $verdict = replay_credit::verdict(
                    $attempt,
                    $playerpuzzle,
                    $context,
                    true,
                    $params['damage'],
                    $params['coinsearnedsofar'],
                    $params['bosscoinsearnedsofar']
                );
                if ($verdict['outcome'] !== 'won') {
                    if ($verdict['outcome'] === 'restart') {
                        replay_credit::restart_phase($attempt, $playerpuzzle);
                    }
                    $attempt->timemodified = time();
                    $DB->update_record('playerpuzzle_attempts', $attempt);

                    return self::unchanged_phase_result(
                        $attempt,
                        $playerpuzzle,
                        $verdict['outcome'] === 'restart' ? 'restarted' : 'lost'
                    );
                }

                // Backstop against a claimed phase win that bypasses the client-side boss-revive
                // rule entirely (a forged request, or a genuine client bug) — the attempt is
                // never persisted above this point on this path, so a rejection leaves it
                // untouched and still resumable. The client should never actually reach this:
                // the revive keeps the boss alive until enough questions are answered.
                if ((int) $attempt->questions_total < (int) $playerpuzzle->minquestions) {
                    throw new moodle_exception(
                        'minquestionsnotmet',
                        'mod_playerpuzzle',
                        '',
                        (int) $playerpuzzle->minquestions
                    );
                }

                if ($currentphase < 10) {
                    $newlevel = $currentlevel;
                    $newphase = $currentphase + 1;
                } else if ($currentlevel < (int) $playerpuzzle->maxlevels) {
                    $newlevel = $currentlevel + 1;
                    $newphase = 1;
                } else {
                    // Already at the last phase of the last level: nothing left to advance to.
                    // The client must call save_progress with victory instead, to finish the
                    // whole campaign, not this endpoint.
                    throw new moodle_exception('nonextphase', 'mod_playerpuzzle');
                }

                // Difficulty is re-chosen at each phase transition (Campaign): the value
                // fought this phase drove the win check above; from here on the attempt
                // carries the newly chosen one, which the reloaded play.php will scale the
                // next fight with.
                $newdifficulty = security::clean_difficulty($params['difficulty']);

                // Coin ledger: record this just-finished phase's verified coins, bank whatever is
                // available, then reset the ledger to 0 — the next phase starts its own clean
                // window, since coins_earned/boss_coins_earned track only the phase currently
                // being played, not the whole Campaign attempt. attempt_consumables::
                // reset_attempt() below clears the same window's per-type use count, for the
                // same reason.
                coin_ledger::sync($attempt, $verdict['playergold'], $verdict['bossgold']);

                $blockinstanceid = hud_service::get_block_instance_id((int) $playerpuzzle->course);

                $payable = coin_ledger::available($attempt);
                $coinsbanked = $payable;
                if ($payable > 0) {
                    user_stock::credit((int) $USER->id, (int) $playerpuzzle->id, user_stock::CURRENCY_TYPE, $payable);
                }

                // Win-grant item, separate from the coin balance — granted on every phase win,
                // not only the campaign's final one, mirroring hud_win_grant_item's own help
                // text. XP is withheld when maxattempts is Unlimited (0), the same
                // anti-farming rule mod_playerwords already applies to its own infinite-round
                // win grant.
                $grantitem = (int) $playerpuzzle->hud_win_grant_item;
                if ($grantitem > 0 && $blockinstanceid !== null) {
                    hud_service::grant_item(
                        $blockinstanceid,
                        (int) $USER->id,
                        $grantitem,
                        max(1, (int) $playerpuzzle->hud_win_grant_qty),
                        (int) $playerpuzzle->maxattempts === 0
                    );
                }

                coin_ledger::reset($attempt);
                attempt_consumables::reset_attempt((int) $attempt->id);
                // The saved board/HP/meters snapshot belongs to the phase just finished — the
                // next phase always starts with a fresh board and full HP.
                $attempt->combatstate = null;
                // The PRNG seed/move log/frozen config belong to the phase just finished too —
                // see security::seed_replay_state()'s own docblock for why a seed must never
                // survive across phases.
                security::seed_replay_state($attempt, $playerpuzzle);
                $attempt->phaserestarts = 0;

                $newtoken = bin2hex(random_bytes(32));
                $attempt->token = $newtoken;
                $attempt->currentlevel = $newlevel;
                $attempt->currentphase = $newphase;
                $attempt->difficulty = $newdifficulty;
                $attempt->timemodified = time();
                $DB->update_record('playerpuzzle_attempts', $attempt);

                // Winning a phase is new progress for the Campaign grade formula (the
                // furthest phase a continuous streak has ever reached) even though the
                // attempt itself stays inprogress — the gradebook should not wait for the
                // whole campaign to finish to reflect it.
                playerpuzzle_update_grades($playerpuzzle, (int) $USER->id);

                return [
                    'outcome'      => 'advanced',
                    'token'        => $newtoken,
                    'currentlevel' => $newlevel,
                    'currentphase' => $newphase,
                    'difficulty'   => $newdifficulty,
                    'bosshp'       => combat::apply_difficulty(
                        combat::calculate_boss_hp((int) $playerpuzzle->basebosshp, $newlevel, $newphase),
                        $newdifficulty
                    ),
                    'studenthp'    => combat::calculate_student_hp(
                        (int) $playerpuzzle->basestudenthp,
                        $newlevel,
                        $newphase
                    ),
                    'coinsbanked'  => $coinsbanked,
                ];
            }
        );

        if ($result === false) {
            // Token unknown, already rotated/consumed, or belongs to a different
            // user/instance: a replay or forged submission, not a coding mistake. Also
            // reached when a genuinely parallel request for the same attempt lost the race.
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        return $result;
    }

    /**
     * The result for a call that did not advance: the attempt stays on its current phase
     * (restarted, or about to be recorded as lost), with its current token and fight values.
     *
     * @param \stdClass $attempt The attempt row.
     * @param \stdClass $playerpuzzle The instance record.
     * @param string $outcome 'restarted' or 'lost'.
     * @return array Same shape as an advanced result.
     */
    private static function unchanged_phase_result(\stdClass $attempt, \stdClass $playerpuzzle, string $outcome): array {
        $level = (int) $attempt->currentlevel;
        $phase = (int) $attempt->currentphase;

        return [
            'outcome'      => $outcome,
            'token'        => (string) $attempt->token,
            'currentlevel' => $level,
            'currentphase' => $phase,
            'difficulty'   => (string) $attempt->difficulty,
            'bosshp'       => combat::apply_difficulty(
                combat::calculate_boss_hp((int) $playerpuzzle->basebosshp, $level, $phase),
                (string) $attempt->difficulty
            ),
            'studenthp'    => combat::calculate_student_hp((int) $playerpuzzle->basestudenthp, $level, $phase),
            'coinsbanked'  => 0,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'outcome'      => new external_value(
                PARAM_ALPHA,
                "'advanced'; 'restarted' (an unverifiable victory restarted the phase); or 'lost' (the " .
                "replay found a defeat, which the client records through save_progress)"
            ),
            'token'        => new external_value(PARAM_ALPHANUM, 'New anti-replay token for the advanced phase'),
            'currentlevel' => new external_value(PARAM_INT, 'Level the attempt is now on'),
            'currentphase' => new external_value(PARAM_INT, 'Phase the attempt is now on'),
            'difficulty'   => new external_value(PARAM_ALPHA, 'Difficulty the attempt now carries for the new phase'),
            'bosshp'       => new external_value(PARAM_INT, 'Scaled boss HP for the new phase'),
            'studenthp'    => new external_value(PARAM_INT, 'Scaled student HP for the new phase'),
            'coinsbanked'  => new external_value(PARAM_INT, 'PuzzleCoin banked for this phase'),
        ]);
    }
}
