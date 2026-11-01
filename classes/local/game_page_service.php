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
 * Service to build PlayerPuzzle game page (play.php) state.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context;
use context_module;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\replay;
use mod_playerpuzzle\local\engine\security;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Handles the attempt-limit check and game config assembly for play.php.
 */
class game_page_service {
    /**
     * Keeps visitors to the Demo: the guest account, and a logged-in user who is in the
     * course only through guest access. A real attempt, its coins and its grade belong to a
     * participant, and the guest account is one record shared by every visitor, so they
     * could never be told apart; the Demo already counts for none of that. Same stance
     * mod_playerwords takes by letting guests play a round that persists nothing.
     *
     * @param bool $isdemo Whether the requested fight is the Demo.
     * @param moodle_url $returnurl Where the error page links back to.
     * @param context $context Module context.
     * @return void
     * @throws moodle_exception When a visitor asks for a real attempt.
     */
    public static function check_guest_demo_only(bool $isdemo, moodle_url $returnurl, context $context): void {
        if (!$isdemo && is_guest($context)) {
            throw new moodle_exception('guestdemoonly', 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Checks the attempt limit for the instance's own game mode, throwing when it has
     * already been reached. Campaign counts finished attempts against `maxattempts`
     * (failures/restarts across the whole campaign); Single Match counts finished
     * attempts against `max_single_matches` (each match played, win or lose, spends one).
     * Both are 0 = unlimited, and both only count attempts already in a final status —
     * an abandoned in-progress row is resumed by resume_or_create_attempt_token(), never
     * counted here.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the limit for this game mode has been reached.
     */
    public static function check_attempt_limit(stdClass $instance, int $userid, moodle_url $returnurl): void {
        global $DB;

        if ($instance->gamemode === constants::GAMEMODE_SINGLE) {
            $limit = (int) $instance->max_single_matches;
            $errorkey = 'maxsinglematchesreached';
        } else {
            $limit = (int) $instance->maxattempts;
            $errorkey = 'maxattemptsreached';
        }

        if ($limit <= 0) {
            return;
        }

        // Demo attempts never count against this limit — see check_retry_cost()'s own
        // isdemo exclusion for the same rationale.
        $finishedattempts = $DB->count_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0',
            ['ppid' => $instance->id, 'uid' => $userid, 'inprogress' => 'inprogress']
        );
        if ($finishedattempts >= $limit) {
            throw new moodle_exception($errorkey, 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Refuses a match that cannot be won: when the activity requires answering a minimum of questions
     * before a victory counts and has no approved question to draw, the boss is revived every time its HP
     * reaches zero and the server refuses the victory, so the student could only lose, spending an attempt
     * and the retry cost. A zero minimum needs no question and is never refused.
     *
     * @param stdClass $instance Activity instance.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the activity has a minimum of questions but none to draw.
     */
    public static function check_has_questions(stdClass $instance, moodle_url $returnurl): void {
        if (self::lacks_questions($instance)) {
            throw new moodle_exception('noapprovedquestions', 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Whether the activity requires answering questions but has none approved to draw.
     *
     * @param stdClass $instance Activity instance.
     * @return bool
     */
    public static function lacks_questions(stdClass $instance): bool {
        return (int) $instance->minquestions > 0 && !questions_repository::has_approved_question((int) $instance->id);
    }

    /**
     * Runs every gate a real (non-Demo) attempt must pass before play.php creates it.
     *
     * The order is part of the contract: check_retry_cost() actually debits the PlayerHUD
     * item, so it has to be the last gate. Any gate placed after it that throws would leave
     * the student charged for an attempt that never starts.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When any of the gates rejects the new attempt.
     */
    public static function check_can_start_attempt(stdClass $instance, int $userid, moodle_url $returnurl): void {
        self::check_has_questions($instance, $returnurl);
        self::check_attempt_limit($instance, $userid, $returnurl);
        self::check_cooldown($instance, $userid, $returnurl);
        self::check_retry_cost($instance, $userid, $returnurl);
    }

    /**
     * Charges the configured retry-cost item, from the 2nd attempt onwards, before a new
     * attempt is created — never for the very first attempt, and never for a play.php POST
     * that will only resume an already in-progress one (a plain reload, or the client's own
     * "Continue"/"Play again" form submit hitting the same phase again is not "a new try").
     *
     * Retries stay free whenever there is nothing configured to charge against: no item set,
     * or no block_playerhud instance in the course at all — this gate is meant as an optional
     * toll a teacher opts into, not a hard requirement of the activity. Only an actually-
     * configured item with insufficient balance blocks the retry.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the item is configured and available, but the student
     *  does not hold enough of it.
     */
    public static function check_retry_cost(stdClass $instance, int $userid, moodle_url $returnurl): void {
        global $DB;

        $itemid = (int) $instance->hud_retry_cost_item;
        if ($itemid <= 0) {
            return;
        }
        if (security::has_inprogress_attempt((int) $instance->id, $userid)) {
            return;
        }

        // Demo attempts never count as a real "finished attempt" here — a student who has
        // only ever played the Demo still gets a free first real try.
        $finishedattempts = $DB->count_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0',
            ['ppid' => $instance->id, 'uid' => $userid, 'inprogress' => 'inprogress']
        );
        if ($finishedattempts === 0) {
            return;
        }

        $blockinstanceid = hud_service::get_block_instance_id((int) $instance->course);
        if ($blockinstanceid === null) {
            return;
        }

        $qty = max(1, (int) $instance->hud_retry_cost_qty);
        $consumed = hud_service::consume_item($blockinstanceid, $userid, $itemid, $qty);
        // Null means the item is gone from PlayerHUD (deleted, or the block was replaced): the cost
        // can never be paid, so it is waived. Only a genuine shortfall blocks the retry.
        if ($consumed === false) {
            throw new moodle_exception('insufficientretrycost', 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Enforces the configured cooldown between matches, Single Match mode only — a
     * continuous Campaign attempt has no equivalent "rodada curta" to farm, so the
     * setting is meaningless there and this is a no-op regardless of what is stored
     * (see the mod_form.php field, hidden the same way for Campaign).
     *
     * Anchored on the most recent finished (non-inprogress, non-Demo) attempt's
     * timefinished — never checked at all while an in-progress attempt exists to resume,
     * since resuming one never creates a new attempt and so never spends anything the
     * cooldown would need to protect.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the cooldown since the last finished match has not
     *  elapsed yet.
     */
    public static function check_cooldown(stdClass $instance, int $userid, moodle_url $returnurl): void {
        global $DB;

        if ($instance->gamemode !== constants::GAMEMODE_SINGLE) {
            return;
        }

        $seconds = (int) $instance->cooldown_seconds;
        if ($seconds <= 0) {
            return;
        }
        if (security::has_inprogress_attempt((int) $instance->id, $userid)) {
            return;
        }

        $lastfinished = $DB->get_field_sql(
            "SELECT MAX(timefinished)
               FROM {playerpuzzle_attempts}
              WHERE playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0",
            ['ppid' => $instance->id, 'uid' => $userid, 'inprogress' => 'inprogress']
        );
        if (empty($lastfinished)) {
            return;
        }

        $until = (int) $lastfinished + $seconds;
        if ($until > time()) {
            throw new moodle_exception('cooldownactive', 'mod_playerpuzzle', $returnurl, format_time($until - time()));
        }
    }

    /**
     * Starts a match for the Lobby's Play button: for a real attempt, runs the gates that decide whether
     * a new one may begin and then resumes or creates the attempt, all under one lock per student and
     * activity. Two requests of the same student arriving together (two tabs, a double click, a repeated
     * request) would otherwise both pass the limit, the cooldown and the retry cost before either created
     * its row, and both would create an attempt. A Demo has nothing at stake and takes no lock.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid Current user ID.
     * @param bool $ismobile Whether the request is from a mobile device.
     * @param string $difficulty Student-chosen difficulty for a fresh attempt.
     * @param bool $isdemo Whether this is a disposable Demo attempt.
     * @param moodle_url $returnurl URL to send the student back to when a gate rejects the attempt.
     * @return array JS game config for game_boot.js, see build_game_config().
     * @throws moodle_exception When a gate rejects the attempt, or another start of the same student
     *  does not finish in time.
     */
    public static function start_game(
        stdClass $cm,
        stdClass $instance,
        context_module $context,
        int $userid,
        bool $ismobile,
        string $difficulty,
        bool $isdemo,
        moodle_url $returnurl
    ): array {
        if ($isdemo) {
            self::check_has_questions($instance, $returnurl);
            return self::build_game_config($cm, $instance, $context, $userid, $ismobile, $difficulty, true);
        }

        $config = security::with_locked_attempt_start(
            $userid,
            (int) $instance->id,
            function () use ($cm, $instance, $context, $userid, $ismobile, $difficulty, $returnurl): array {
                self::check_can_start_attempt($instance, $userid, $returnurl);
                return self::build_game_config($cm, $instance, $context, $userid, $ismobile, $difficulty, false);
            }
        );
        if ($config === false) {
            throw new moodle_exception('startbusy', 'mod_playerpuzzle', $returnurl);
        }

        return $config;
    }

    /**
     * Resumes or creates the attempt, and assembles the full JS game config: the scaled
     * boss/student HP and combat damage for the attempt's current level/phase (Single Match
     * always resolves to the base values unchanged, since its attempts stay at Level 1,
     * Phase 1), the anti-replay token, and sprites. Questions are no longer bundled here —
     * the client draws one at a time from mod_playerpuzzle_draw_question, server-picked, so
     * it never learns which question the next challenge will be ahead of time.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid Current user ID.
     * @param bool $ismobile Whether the request is from a mobile device.
     * @param string $difficulty Student-chosen difficulty for a fresh attempt; not applied
     *  when an in-progress attempt is resumed (that attempt keeps its current phase's own
     *  difficulty, which advance_phase changes between phases). Ignored entirely for a Demo
     *  attempt, which always fights at a fixed HP regardless of difficulty.
     * @param bool $isdemo Whether this is a disposable Demo attempt (Lobby's "Play Demo"
     *  button) — fixed HP on both sides, always Level 1/Phase 1, never counted for grade,
     *  coins, completion, or the attempt limit.
     * @return array JS game config for game_boot.js.
     */
    public static function build_game_config(
        stdClass $cm,
        stdClass $instance,
        context_module $context,
        int $userid,
        bool $ismobile,
        string $difficulty = 'normal',
        bool $isdemo = false
    ): array {
        global $OUTPUT;

        $attemptinfo = security::resume_or_create_attempt_token(
            (int) $instance->id,
            $userid,
            $difficulty,
            (int) $instance->maxlevels,
            $isdemo
        );
        $difficulty = $attemptinfo->difficulty;

        if ($attemptinfo->isnew) {
            $event = \mod_playerpuzzle\event\game_started::create([
                'objectid' => $attemptinfo->attemptid,
                'context'  => $context,
                'other'    => ['gamemode' => $instance->gamemode, 'difficulty' => $difficulty],
            ]);
            $event->trigger();
        }

        // The opponent's HP, the phase's base damage and the profile's attack and armor come from
        // the same calculation the server replay uses (combat::phase_opponent()), from the config
        // frozen when the phase started, so a teacher editing the activity mid-phase never changes
        // what a resumed client fights. A Demo fights at a fixed HP with no profile. Student HP is
        // never touched by difficulty, and a Demo uses the fixed one too.
        $opponentside = combat::phase_opponent($attemptinfo, $instance);
        $bosshp = $opponentside['maxbosshp'];
        $bossdamage = $opponentside['basedamage'];
        if ($isdemo) {
            $studenthp = combat::DEMO_HP;
            $coingain = (int) $instance->coingain;
        } else {
            $studenthp = combat::calculate_student_hp(
                (int) $instance->basestudenthp,
                $attemptinfo->currentlevel,
                $attemptinfo->currentphase
            );
            $coingain = $attemptinfo->frozencoingain;
        }

        // A resumed real phase is rebuilt by the server's own replay rather than taken from
        // the client's last checkpoint (see replay::snapshot()). A Demo keeps the checkpoint:
        // it fights at a fixed HP the replay does not model, and has nothing at stake.
        $snapshot = (!$attemptinfo->isnew && !$isdemo)
            ? self::rebuild_resume_point($attemptinfo, $instance, $userid)
            : null;

        $consumableuses = attempt_consumables::get_uses_by_type($attemptinfo->attemptid);

        // Units of each type currently owned (bought pre-match in the Lobby) — lets the
        // in-combat badges show and gate on real ownership from the moment the page loads,
        // without needing a failed use_stock call first. A single bulk read for all five
        // types, same shape as get_uses_by_type() just above.
        $consumablestock = user_stock::get_all($userid, (int) $instance->id);

        $spriteurls = [];
        for ($i = 0; $i < 7; $i++) {
            $spriteurls[] = $OUTPUT->image_url('sprites/item' . $i, 'mod_playerpuzzle')->out(false);
        }

        // The opponent was fixed when the phase started; a row from before opponents were frozen
        // falls back to the one the phase would get today.
        $opponent = $attemptinfo->frozenopponent !== ''
            ? $attemptinfo->frozenopponent
            : opponents::for_phase($instance, $attemptinfo->currentlevel, $attemptinfo->currentphase);
        $bossurl = opponents::sprite_url($opponent)->out(false);
        $playerurl = $OUTPUT->image_url('player', 'mod_playerpuzzle')->out(false);
        $stagebgurl = opponents::stage_url($opponent)->out(false);

        return [
            'cmid'                 => $cm->id,
            'token'                => $attemptinfo->token,
            // A Demo is always reported as 'single' to the client regardless of the
            // instance's own configured gamemode: it is a one-shot fight (no advance_phase),
            // exactly the flow combat.js already has for Single Match — see
            // combat.js::hasNextPhase()'s gamemode gate.
            'gamemode'             => $isdemo ? constants::GAMEMODE_SINGLE : $instance->gamemode,
            'difficulty'           => $difficulty,
            // The client never counts its own answered questions for the "Perguntas: X/N" HUD
            // counter or the boss-revive rule — it only ever mirrors this server-reported total,
            // updated on every validate_answer call. A Demo has no minimum: it is a practice
            // fight with nothing at stake, so its opponent never revives.
            'minquestions'         => $isdemo ? 0 : (int) $instance->minquestions,
            'questionstotal'       => $attemptinfo->questionstotal,
            // Coin ledger counts already on the attempt (0 for a fresh one, whatever the
            // current phase/match carries for a resumed one) — lets the coin display render
            // its correct state on load.
            'coinsearnedsofar'     => $attemptinfo->coinsearned,
            'bosscoinsearnedsofar' => $attemptinfo->bosscoinsearned,
            // Where a resumed phase stands, rebuilt by the server (see replay::snapshot()):
            // board, PRNG position, HP/meters/gold, whose turn, a question still open, or a
            // match already over. When present, the client loads it instead of combatstate.
            'snapshot'             => $snapshot === null ? null : [
                'grid'            => $snapshot['grid'],
                'rngstate'        => $snapshot['rngstate'],
                'turn'            => $snapshot['turn'],
                'pendingquestion' => $snapshot['pendingquestion'],
                'terminal'        => $snapshot['terminal'],
                'state'           => $snapshot['state'],
            ],
            // The board/HP/meters/turn the client's last checkpoint declared (null for a phase
            // that never got one, or that was just started/advanced) — only used to resume
            // when there is no server-rebuilt snapshot above (a Demo, or a log that does not
            // replay consistently).
            'combatstate'          => $snapshot === null ? $attemptinfo->combatstate : null,
            'consumableuses'       => $consumableuses,
            'consumablestock'      => $consumablestock,
            // Coin multiplier the client applies to its own gold display so the end screen
            // matches what the server will actually bank (Easy 0.5x, Hard 3x). The client
            // still sends the raw, unmultiplied gold; the server re-applies this factor.
            'coinfactor'           => combat::difficulty_coin_factor($difficulty),
            'currentlevel'         => $attemptinfo->currentlevel,
            'currentphase'         => $attemptinfo->currentphase,
            'maxlevels'            => (int) $instance->maxlevels,
            'bosshp'               => $bosshp,
            'studenthp'            => $studenthp,
            'bossdamage'           => $bossdamage,
            // The opponent profile's multiplier on the hits it lands and the share of each hit it
            // takes that its armor absorbs (1 and 0 without a profile). The client only uses them
            // to play the fight; the server replay reads its own from the frozen opponent.
            'bossattack'           => $opponentside['bossattack'],
            'bossarmor'            => $opponentside['bossarmor'],
            // The opponent's powers (none in a Demo), for the client's own rules and the notice
            // that explains each one when the phase starts.
            'bosspowers'           => $opponentside['bosspowers'],
            // Deliberately not scaled by level/phase, unlike bossdamage above: a teacher's
            // fixed-price consumable shop would otherwise get proportionally cheaper as a
            // campaign progresses.
            'coingain'             => $coingain,
            'bossavatar'           => $opponent,
            'bossurl'              => $bossurl,
            'playerurl'            => $playerurl,
            'stagebgurl'           => $stagebgurl,
            'spriteurls'           => $spriteurls,
            'mobile'               => $ismobile,
            'viewurl'              => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]))->out(false),
            // Seeds the client's deterministic PRNG (engine/prng.js), replacing Math.random
            // for board generation/gravity/shuffle — reset every new attempt/phase (see
            // security::seed_replay_state()), so a future server-side replay of this phase's
            // recorded moves reaches the same board states the client did.
            'rngseed'              => $attemptinfo->rngseed,
            // Events already stored for this phase — the offset the client's next batch starts at.
            'moveseq'              => $snapshot === null ? $attemptinfo->moveseq : $snapshot['eventcount'],
            'enablespeech'         => sound_preferences::is_enabled('speech', $userid),
            'musicenabled'         => sound_preferences::is_enabled('music', $userid),
            'sfxenabled'           => sound_preferences::is_enabled('sfx', $userid),
            'isdemo'               => $attemptinfo->isdemo,
        ];
    }

    /**
     * Rebuilds a resumed attempt's current phase through the server-side replay, storing any
     * event the replay had to add (a question judged or a consumable counted before the
     * reload, but never logged) — under the attempt's lock, like every other writer of its
     * event log.
     *
     * @param stdClass $attemptinfo The resumed attempt, as returned by
     *  security::resume_or_create_attempt_token().
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @return array|null The snapshot (see replay::snapshot()), or null when the phase cannot
     *  be rebuilt from its log.
     */
    private static function rebuild_resume_point(stdClass $attemptinfo, stdClass $instance, int $userid): ?array {
        global $DB;

        $result = security::with_locked_attempt(
            $attemptinfo->token,
            (int) $instance->id,
            $userid,
            function (stdClass $attempt) use ($DB, $instance): ?array {
                $snapshot = replay::snapshot($attempt, $instance);
                if ($snapshot === null || $snapshot['appendedevents'] === []) {
                    return $snapshot;
                }

                $stored = count(move_log::decode($attempt->movelog));
                if (!move_log::merge_into_attempt($attempt, $stored, $snapshot['appendedevents'])) {
                    return null;
                }
                $DB->update_record('playerpuzzle_attempts', (object) [
                    'id' => $attempt->id,
                    'movelog' => $attempt->movelog,
                    'moveseq' => $attempt->moveseq,
                ]);

                return $snapshot;
            }
        );

        return $result === false ? null : $result;
    }
}
