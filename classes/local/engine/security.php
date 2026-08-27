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
     * @return string The generated secure token.
     */
    public static function generate_attempt_token(
        int $playerpuzzleid,
        int $userid,
        string $difficulty = 'normal'
    ): string {
        global $DB;

        // Generate a secure 64-character hex token using PHP 7+ random_bytes.
        $token = bin2hex(random_bytes(32));

        $attempt = new \stdClass();
        $attempt->playerpuzzleid = $playerpuzzleid;
        $attempt->userid = $userid;
        $attempt->token = $token;
        $attempt->difficulty = self::clean_difficulty($difficulty);
        $attempt->status = 'inprogress';
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
     * Resumes the most recent in-progress attempt for this user/instance, if one exists,
     * or creates a brand new one otherwise. Resuming rotates the token (the old one, tied
     * to whatever session left the attempt in progress, becomes invalid immediately) but
     * preserves currentlevel/currentphase, so a Campaign student who left mid-campaign
     * continues where they stopped instead of restarting at Level 1, Phase 1 — an
     * attempt is a continuous winning streak, not reset by simply reloading the page.
     *
     * Uses get_records() rather than get_record(): a site upgraded from before this method
     * existed may already have more than one stale in-progress row for the same user/
     * instance (every play.php load used to insert a fresh one). Picking the most recently
     * created one is the only sane resolution; the older rows are left as harmless clutter,
     * never resumable again since they no longer hold the current token.
     *
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @param string $difficulty Student-chosen difficulty for a fresh attempt; ignored when
     *  resuming, since a run's difficulty is locked once it is in progress.
     * @return \stdClass Object with ->token, ->currentlevel, ->currentphase, ->difficulty.
     */
    public static function resume_or_create_attempt_token(
        int $playerpuzzleid,
        int $userid,
        string $difficulty = 'normal'
    ): \stdClass {
        global $DB;

        $existing = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $playerpuzzleid, 'userid' => $userid, 'status' => 'inprogress'],
            'timecreated DESC',
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

            return (object) [
                'token' => $token,
                'currentlevel' => (int) $attempt->currentlevel,
                'currentphase' => (int) $attempt->currentphase,
                'difficulty' => self::clean_difficulty((string) $attempt->difficulty),
            ];
        }

        return (object) [
            'token' => self::generate_attempt_token($playerpuzzleid, $userid, $difficulty),
            'currentlevel' => 1,
            'currentphase' => 1,
            'difficulty' => self::clean_difficulty($difficulty),
        ];
    }

    /**
     * Validates and consumes a token to prevent replay attacks.
     *
     * Moves the attempt straight to its final status in the same update that consumes the
     * token, so a second request with the same token no longer matches status = 'inprogress'
     * and is rejected.
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

        $params = [
            'token' => $token,
            'playerpuzzleid' => $playerpuzzleid,
            'userid' => $userid,
            'status' => 'inprogress',
        ];

        // Fetch the attempt safely using exact parameters.
        $attempt = $DB->get_record('playerpuzzle_attempts', $params);

        if (!$attempt) {
            // Token not found, already used, or mismatched user/instance. Cheat attempt detected!
            return false;
        }

        // Consume the token so it cannot be used again (Anti-Replay).
        $attempt->status = $finalstatus;
        $attempt->timefinished = time();
        $attempt->timemodified = $attempt->timefinished;
        $DB->update_record('playerpuzzle_attempts', $attempt);

        return $attempt;
    }
}
