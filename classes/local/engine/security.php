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
 * Security and anti-cheat engine for PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

use mod_playerpuzzle\local\combat_state;

/**
 * Security class to handle tokens and anti-cheat validations.
 */
class security {
    /**
     * Difficulty values a new attempt may be created with. A value outside this set is
     * coerced to 'normal' rather than trusted — the choice reaches here from a client POST.
     */
    public const DIFFICULTIES = ['easy', 'normal', 'hard'];

    /**
     * Coerces a client-supplied difficulty to a known value, defaulting to 'normal'.
     *
     * @param string $difficulty Raw value from the request.
     * @return string One of self::DIFFICULTIES.
     */
    public static function clean_difficulty(string $difficulty): string {
        return in_array($difficulty, self::DIFFICULTIES, true) ? $difficulty : 'normal';
    }

    /**
     * Generates a unique token for a new game attempt.
     *
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @param string $difficulty Student-chosen difficulty; coerced to a known value.
     * @param int $currentlevel Level this attempt starts on (see resume_or_create_attempt_token()
     *  for why this is not always 1).
     * @param int $currentphase Phase this attempt starts on.
     * @param bool $isdemo Whether this is a disposable Demo attempt (Lobby's "Play Demo"
     *  button), never counted for grade/coins/completion/attempt-limit.
     * @return string The generated secure token.
     */
    public static function generate_attempt_token(
        int $playerpuzzleid,
        int $userid,
        string $difficulty = 'normal',
        int $currentlevel = 1,
        int $currentphase = 1,
        bool $isdemo = false
    ): string {
        global $DB;

        // Generate a secure 64-character hex token using PHP 7+ random_bytes.
        $token = bin2hex(random_bytes(32));

        $attempt = new \stdClass();
        $attempt->playerpuzzleid = $playerpuzzleid;
        $attempt->userid = $userid;
        $attempt->token = $token;
        $attempt->difficulty = self::clean_difficulty($difficulty);
        $attempt->isdemo = $isdemo ? 1 : 0;
        $attempt->status = 'inprogress';
        $attempt->currentlevel = $currentlevel;
        $attempt->currentphase = $currentphase;
        $attempt->timecreated = time();
        $attempt->timemodified = $attempt->timecreated;

        $DB->insert_record('playerpuzzle_attempts', $attempt);

        return $token;
    }

    /**
     * Statuses a token may be consumed into. Mirrors db/install.xml's playerpuzzle_attempts.status.
     */
    public const FINAL_STATUSES = ['won', 'lost', 'timeout', 'abandoned'];

    /**
     * Whether the user already has a real (non-Demo) in-progress attempt on this instance —
     * a play.php POST that finds one will resume it rather than create a new one (see
     * resume_or_create_attempt_token()), which is not "a new try" for the retry-cost gate
     * (game_page_service::check_retry_cost()) to charge for. Demo attempts are excluded: they
     * are never "a real attempt in progress" for this purpose.
     *
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @return bool
     */
    public static function has_inprogress_attempt(int $playerpuzzleid, int $userid): bool {
        global $DB;

        return $DB->record_exists('playerpuzzle_attempts', [
            'playerpuzzleid' => $playerpuzzleid,
            'userid'         => $userid,
            'status'         => 'inprogress',
            'isdemo'         => 0,
        ]);
    }

    /**
     * Resumes the most recent in-progress attempt for this user/instance, if one exists,
     * or creates a brand new one otherwise. Resuming rotates the token (the old one, tied
     * to whatever session left the attempt in progress, becomes invalid immediately) but
     * preserves currentlevel/currentphase, so a Campaign student who left mid-campaign
     * continues where they stopped instead of restarting at Level 1, Phase 1 — an
     * attempt is a continuous winning streak, not reset by simply reloading the page.
     *
     * With no in-progress attempt, a brand new one is still not always Level 1/Phase 1: an
     * attempt is a continuous winning streak that only ends by losing or by winning the whole
     * campaign (the original design intent, never fully wired until now — losing a fight used
     * to always restart the entire campaign regardless of how far the student had gotten,
     * discarding real progress instead of just re-fighting the phase that was lost). When the
     * most recently finished attempt for this user/instance ended in 'lost', the new attempt
     * starts back on that same level/phase instead of defaulting to 1/1. A 'won' attempt (the
     * student cleared the whole campaign) starts a genuinely fresh run at 1/1, same as today —
     * finishing the campaign is not "a loss to retry", it is completing it.
     *
     * Uses get_records() rather than get_record(): a site upgraded from before this method
     * existed may already have more than one stale in-progress row for the same user/
     * instance (every play.php load used to insert a fresh one). Picking the most recently
     * created one is the only sane resolution; the older rows are left as harmless clutter,
     * never resumable again since they no longer hold the current token.
     *
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @param string $difficulty Student-chosen difficulty for a fresh attempt. Not applied when
     *  resuming: the attempt keeps the difficulty its current phase was entered at, which
     *  advance_phase() is what changes between phases in Campaign mode.
     * @param int $maxlevels The instance's configured level count, used to clamp an inherited
     *  level/phase down if the teacher has since reduced it below where the student had
     *  reached (Single Match always passes/keeps the default, since its attempts never
     *  advance past Level 1, Phase 1 in the first place). Ignored for a Demo attempt, which
     *  always starts at Level 1/Phase 1.
     * @param bool $isdemo Whether this is a disposable Demo attempt (Lobby's "Play Demo"
     *  button). A Demo request only ever resumes another in-progress Demo, never a real
     *  attempt, and vice versa — the two are entirely separate resume namespaces.
     * @return \stdClass Object with ->attemptid, ->token, ->currentlevel, ->currentphase,
     *  ->difficulty, ->questionstotal, ->coinsearned, ->bosscoinsearned, ->coinsspent,
     *  ->combatstate, ->isdemo, ->isnew (true when a brand new attempt row was just
     *  created, so the caller can trigger a game_started event exactly once per attempt).
     */
    public static function resume_or_create_attempt_token(
        int $playerpuzzleid,
        int $userid,
        string $difficulty = 'normal',
        int $maxlevels = 10,
        bool $isdemo = false
    ): \stdClass {
        global $DB;

        $existing = $DB->get_records(
            'playerpuzzle_attempts',
            [
                'playerpuzzleid' => $playerpuzzleid,
                'userid'         => $userid,
                'status'         => 'inprogress',
                'isdemo'         => $isdemo ? 1 : 0,
            ],
            'timecreated DESC, id DESC',
            '*',
            0,
            1
        );
        $attempt = reset($existing);

        if ($attempt) {
            $token = bin2hex(random_bytes(32));
            $attempt->token = $token;
            $attempt->timemodified = time();
            $DB->update_record('playerpuzzle_attempts', $attempt);

            // A checkpoint with the boss already at 0 HP can only mean the phase was won and
            // the pagehide/beacon safety net (save_combat_state) persisted that instant, but
            // advance_phase was never reached to move the attempt to the next phase (e.g. the
            // student exited from the phase-complete screen before that call finished).
            // Resuming it verbatim would reopen an already-dead boss on the same phase instead
            // of a fresh fight, so treat it the same as no checkpoint at all.
            $combatstate = combat_state::decode($attempt->combatstate);
            if ($combatstate !== null && (int) ($combatstate['currentbosshp'] ?? 1) <= 0) {
                $combatstate = null;
            }

            return (object) [
                'attemptid' => (int) $attempt->id,
                'token' => $token,
                'currentlevel' => (int) $attempt->currentlevel,
                'currentphase' => (int) $attempt->currentphase,
                'difficulty' => self::clean_difficulty((string) $attempt->difficulty),
                'questionstotal' => (int) $attempt->questions_total,
                'coinsearned' => (int) $attempt->coins_earned,
                'bosscoinsearned' => (int) $attempt->boss_coins_earned,
                'coinsspent' => (int) $attempt->coins_spent,
                'combatstate' => $combatstate,
                'isdemo' => (bool) $attempt->isdemo,
                'isnew' => false,
            ];
        }

        if ($isdemo) {
            // A Demo is always a fresh Level 1/Phase 1 fight — it never inherits a resume
            // position from a prior loss, real or otherwise (determine_start_level() itself
            // also excludes Demo rows from that calculation for real attempts, see its own
            // docblock).
            $startlevel = 1;
            $startphase = 1;
        } else {
            [$startlevel, $startphase] = self::determine_start_level(
                $playerpuzzleid,
                $userid,
                max(1, $maxlevels)
            );
        }

        $token = self::generate_attempt_token(
            $playerpuzzleid,
            $userid,
            $difficulty,
            $startlevel,
            $startphase,
            $isdemo
        );
        $newrow = $DB->get_record('playerpuzzle_attempts', ['token' => $token], 'id, isdemo', MUST_EXIST);

        return (object) [
            'attemptid' => (int) $newrow->id,
            'token' => $token,
            'currentlevel' => $startlevel,
            'currentphase' => $startphase,
            'difficulty' => self::clean_difficulty($difficulty),
            'questionstotal' => 0,
            'coinsearned' => 0,
            'bosscoinsearned' => 0,
            'coinsspent' => 0,
            'combatstate' => null,
            'isdemo' => (bool) $newrow->isdemo,
            'isnew' => true,
        ];
    }

    /**
     * Determines the level/phase a brand new attempt should start on. Looks at the most
     * recently *finished* attempt for this user/instance, regardless of its status: if it
     * was 'lost' (or any other non-'won' final status, e.g. a future 'timeout'/'abandoned'),
     * the new attempt resumes on that same level/phase; if it was 'won' (the student cleared
     * the whole campaign) or there is no prior finished attempt at all, it starts fresh at
     * 1/1. Deliberately looks at the single most recent finished attempt, not the most recent
     * 'lost' one — a student who lost once, retried, and this time won the whole campaign must
     * start the next attempt fresh, not jump back to that earlier loss.
     *
     * Only ever considers real (non-Demo) attempts: a Demo match is a disposable practice
     * fight, and a Demo loss must never change where a student's real Campaign run resumes.
     *
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @param int $maxlevels The instance's current level count, already clamped to at least 1.
     * @return array{0: int, 1: int} [$level, $phase].
     */
    public static function determine_start_level(int $playerpuzzleid, int $userid, int $maxlevels): array {
        global $DB;

        // Ordered by id, not just timecreated: two attempts finished within the same
        // second (routine in a fast test run, and not impossible in real play either) sort
        // in a database-dependent order on timecreated alone — MariaDB and PostgreSQL were
        // observed to disagree on the tie, picking a different row as "most recent". The
        // id, being a real auto-increment insertion order, breaks the tie deterministically.
        $recent = $DB->get_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0',
            ['ppid' => $playerpuzzleid, 'uid' => $userid, 'inprogress' => 'inprogress'],
            'timecreated DESC, id DESC',
            'id, status, currentlevel, currentphase',
            0,
            1
        );
        $last = reset($recent);
        if (!$last || $last->status === 'won') {
            return [1, 1];
        }

        $level = (int) $last->currentlevel;
        $phase = (int) $last->currentphase;
        if ($level > $maxlevels) {
            // The teacher has since reduced the level count below where the student had
            // reached — clamp to the new ceiling and restart that level's own Phase 1, since
            // the original phase reached no longer has a well-defined position to resume at.
            return [$maxlevels, 1];
        }

        return [$level, $phase];
    }

    /**
     * Seconds to wait for an attempt's lock before giving up. Short on purpose: every
     * critical section guarded by this lock is a handful of DB writes plus (at most) one
     * PlayerHUD call, so genuine contention resolves in milliseconds — a caller still
     * waiting after this long is racing a replay, not a slow legitimate request, and
     * should be rejected rather than left hanging.
     */
    private const LOCK_TIMEOUT_SECONDS = 5;

    /**
     * Runs a callback with exclusive access to one attempt's economy-affecting state,
     * closing the TOCTOU window a bare get_record()-then-update_record() leaves open: two
     * requests racing the same token (a double-click, or a captured request replayed in
     * parallel) can otherwise both read status = 'inprogress' before either writes,
     * letting both proceed to credit coins/items or advance a phase for what should be a
     * single win. The outer lookup below is a cheap pre-filter (an already-consumed token
     * never needs a lock at all); the re-fetch inside the lock is what actually matters,
     * since only the request that wins the lock can still see 'inprogress' there — the
     * loser's re-fetch finds the row already moved on and gets false, exactly like an
     * ordinary invalid token.
     *
     * @param string $token The token provided by the client.
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @param callable $callback Receives the locked, freshly re-fetched attempt row and
     *  returns whatever the caller wants back.
     * @return mixed|false The callback's return value, or false if no matching in-progress
     *  attempt was found, or the lock could not be acquired in time.
     */
    private static function with_locked_inprogress_attempt(
        string $token,
        int $playerpuzzleid,
        int $userid,
        callable $callback
    ) {
        global $DB;

        $params = [
            'token' => $token,
            'playerpuzzleid' => $playerpuzzleid,
            'userid' => $userid,
            'status' => 'inprogress',
        ];

        $candidate = $DB->get_record('playerpuzzle_attempts', $params);
        if (!$candidate) {
            return false;
        }

        $factory = \core\lock\lock_config::get_lock_factory('mod_playerpuzzle');
        $lock = $factory->get_lock('attempt_' . $candidate->id, self::LOCK_TIMEOUT_SECONDS);
        if (!$lock) {
            // Another request is already mutating this same attempt; whatever it decides,
            // this request loses the race and must not also apply its own effect.
            return false;
        }

        try {
            $attempt = $DB->get_record('playerpuzzle_attempts', $params);
            if (!$attempt) {
                return false;
            }

            return $callback($attempt);
        } finally {
            $lock->release();
        }
    }

    /**
     * Validates and consumes a token to prevent replay attacks.
     *
     * Moves the attempt straight to its final status in the same update that consumes the
     * token, so a second request with the same token no longer matches status = 'inprogress'
     * and is rejected. Wrapped in with_locked_inprogress_attempt() so two such requests
     * arriving genuinely in parallel cannot both observe 'inprogress' and both succeed.
     *
     * @param string $token The token provided by the client.
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @param string $finalstatus One of self::FINAL_STATUSES.
     * @return \stdClass|false The attempt record if valid, false if cheat detected.
     */
    public static function validate_and_consume_token(
        string $token,
        int $playerpuzzleid,
        int $userid,
        string $finalstatus
    ) {
        global $DB;

        if (!in_array($finalstatus, self::FINAL_STATUSES, true)) {
            throw new \coding_exception('Invalid final attempt status: ' . $finalstatus);
        }

        return self::with_locked_inprogress_attempt(
            $token,
            $playerpuzzleid,
            $userid,
            function (\stdClass $attempt) use ($DB, $finalstatus): \stdClass {
                $attempt->status = $finalstatus;
                $attempt->timefinished = time();
                $attempt->timemodified = $attempt->timefinished;
                $DB->update_record('playerpuzzle_attempts', $attempt);

                return $attempt;
            }
        );
    }

    /**
     * Runs a callback with exclusive access to the in-progress attempt matching a token,
     * without moving it to a final status — used by operations that mutate an attempt
     * while it stays 'inprogress' (advancing a Campaign phase, buying a consumable).
     * Same race-closing guarantee as validate_and_consume_token(), for callers that are
     * not consuming the token into a final state.
     *
     * @param string $token The token provided by the client.
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @param callable $callback Receives the locked, freshly re-fetched attempt row and
     *  returns whatever the caller wants back.
     * @return mixed|false The callback's return value, or false if no matching in-progress
     *  attempt was found, or the lock could not be acquired in time.
     */
    public static function with_locked_attempt(
        string $token,
        int $playerpuzzleid,
        int $userid,
        callable $callback
    ) {
        return self::with_locked_inprogress_attempt($token, $playerpuzzleid, $userid, $callback);
    }

    /**
     * Runs a callback with exclusive access to one user's loadout stock for an instance,
     * closing the same TOCTOU window with_locked_attempt() closes for an in-progress
     * attempt — but a pre-match purchase has no attempt/token to key the lock off, so this
     * locks by userid+playerpuzzleid directly instead.
     *
     * @param int $userid The user ID.
     * @param int $playerpuzzleid The instance ID.
     * @param callable $callback Returns whatever the caller wants back.
     * @return mixed|false The callback's return value, or false if the lock could not be
     *  acquired in time.
     */
    public static function with_locked_user_stock(int $userid, int $playerpuzzleid, callable $callback) {
        $factory = \core\lock\lock_config::get_lock_factory('mod_playerpuzzle');
        $lock = $factory->get_lock('stock_' . $userid . '_' . $playerpuzzleid, self::LOCK_TIMEOUT_SECONDS);
        if (!$lock) {
            return false;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
