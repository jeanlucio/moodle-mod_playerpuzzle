<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Security and anti-cheat engine for PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Security class to handle tokens and anti-cheat validations.
 */
class security {
    /**
     * Generates a unique token for a new game attempt.
     *
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @return string The generated secure token.
     */
    public static function generate_attempt_token(int $playerpuzzleid, int $userid): string {
        global $DB;

        // Generate a secure 64-character hex token using PHP 7+ random_bytes.
        $token = bin2hex(random_bytes(32));

        $attempt = new \stdClass();
        $attempt->playerpuzzleid = $playerpuzzleid;
        $attempt->userid = $userid;
        $attempt->token = $token;
        $attempt->status = 'inprogress';
        $attempt->timecreated = time();
        $attempt->timemodified = $attempt->timecreated;

        $DB->insert_record('playerpuzzle_attempts', $attempt);

        return $token;
    }

    /**
     * Validates and consumes a token to prevent replay attacks.
     *
     * @param string $token The token provided by the client.
     * @param int $playerpuzzleid The instance ID.
     * @param int $userid The user ID.
     * @return \stdClass|false The attempt record if valid, false if cheat detected.
     */
    public static function validate_and_consume_token(string $token, int $playerpuzzleid, int $userid) {
        global $DB;

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
        $attempt->status = 'finished';
        $attempt->timemodified = time();
        $DB->update_record('playerpuzzle_attempts', $attempt);

        return $attempt;
    }
}
