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
 * Unit tests for the anti-replay security engine.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Tests for security.
 *
 * @covers \mod_playerpuzzle\local\engine\security
 */
final class security_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that a generated token is a 64-character hex string and the attempt row it
     * creates starts life as 'inprogress'.
     *
     * @return void
     */
    public function test_generate_attempt_token_creates_inprogress_attempt(): void {
        global $DB;

        $token = security::generate_attempt_token(1, 2);

        $this->assertSame(64, strlen($token));
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $token));

        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame('inprogress', $attempt->status);
        $this->assertSame(1, (int) $attempt->playerpuzzleid);
        $this->assertSame(2, (int) $attempt->userid);
    }

    /**
     * Tests that two tokens generated back to back are never equal.
     *
     * @return void
     */
    public function test_generate_attempt_token_is_unique(): void {
        $tokena = security::generate_attempt_token(1, 2);
        $tokenb = security::generate_attempt_token(1, 2);

        $this->assertNotSame($tokena, $tokenb);
    }

    /**
     * Tests that a valid token is consumed and moved to the requested final status.
     *
     * @return void
     */
    public function test_validate_and_consume_token_happy_path(): void {
        global $DB;

        $token = security::generate_attempt_token(1, 2);

        $attempt = security::validate_and_consume_token($token, 1, 2, 'won');

        $this->assertNotFalse($attempt);
        $this->assertSame('won', $attempt->status);
        $this->assertGreaterThan(0, (int) $attempt->timefinished);

        $stored = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame('won', $stored->status);
    }

    /**
     * Tests that a second consumption of the same token is rejected — the core
     * anti-replay guarantee.
     *
     * @return void
     */
    public function test_validate_and_consume_token_rejects_replay(): void {
        $token = security::generate_attempt_token(1, 2);

        $first = security::validate_and_consume_token($token, 1, 2, 'won');
        $second = security::validate_and_consume_token($token, 1, 2, 'won');

        $this->assertNotFalse($first);
        $this->assertFalse($second);
    }

    /**
     * Tests that a token cannot be consumed by a user other than the one it was
     * issued to — cross-user isolation.
     *
     * @return void
     */
    public function test_validate_and_consume_token_rejects_wrong_user(): void {
        $token = security::generate_attempt_token(1, 2);

        $result = security::validate_and_consume_token($token, 1, 999, 'won');

        $this->assertFalse($result);
    }

    /**
     * Tests that a token cannot be consumed against a different instance than the one
     * it was issued for — cross-instance isolation.
     *
     * @return void
     */
    public function test_validate_and_consume_token_rejects_wrong_instance(): void {
        $token = security::generate_attempt_token(1, 2);

        $result = security::validate_and_consume_token($token, 999, 2, 'won');

        $this->assertFalse($result);
    }

    /**
     * Tests that an unknown token is rejected without error.
     *
     * @return void
     */
    public function test_validate_and_consume_token_rejects_unknown_token(): void {
        $result = security::validate_and_consume_token(str_repeat('a', 64), 1, 2, 'won');

        $this->assertFalse($result);
    }

    /**
     * Tests that a status outside FINAL_STATUSES is a programmer mistake, not a
     * user-reachable outcome, and throws coding_exception.
     *
     * @return void
     */
    public function test_validate_and_consume_token_rejects_invalid_status(): void {
        $token = security::generate_attempt_token(1, 2);

        $this->expectException(\coding_exception::class);
        security::validate_and_consume_token($token, 1, 2, 'finished');
    }

    /**
     * Every documented final status is actually accepted and persisted verbatim.
     *
     * @return void
     */
    public function test_all_final_statuses_are_accepted(): void {
        foreach (security::FINAL_STATUSES as $status) {
            $token = security::generate_attempt_token(1, 2);
            $attempt = security::validate_and_consume_token($token, 1, 2, $status);
            $this->assertSame($status, $attempt->status);
        }
    }

    /**
     * Tests that resuming with no existing in-progress attempt creates a brand new one
     * at Level 1, Phase 1 — the same defaults generate_attempt_token() itself leaves.
     *
     * @return void
     */
    public function test_resume_or_create_creates_fresh_attempt_when_none_inprogress(): void {
        global $DB;

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(1, $result->currentlevel);
        $this->assertSame(1, $result->currentphase);
        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $result->token], '*', MUST_EXIST);
        $this->assertSame('inprogress', $attempt->status);
    }

    /**
     * Tests that resuming an existing in-progress attempt preserves its currentlevel/
     * currentphase, rather than restarting the Campaign at Level 1, Phase 1 — an
     * attempt is a continuous winning streak, not reset by reloading play.php.
     *
     * @return void
     */
    public function test_resume_or_create_preserves_level_and_phase(): void {
        global $DB;

        $firsttoken = security::generate_attempt_token(1, 2);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 3, ['token' => $firsttoken]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 7, ['token' => $firsttoken]);

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(3, $result->currentlevel);
        $this->assertSame(7, $result->currentphase);
    }

    /**
     * Tests that resuming rotates the token — the stale one from the abandoned session
     * is invalid immediately, closing the anti-replay gap a page reload would otherwise
     * leave open.
     *
     * @return void
     */
    public function test_resume_or_create_rotates_the_token(): void {
        $oldtoken = security::generate_attempt_token(1, 2);

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertNotSame($oldtoken, $result->token);
        $this->assertFalse(security::validate_and_consume_token($oldtoken, 1, 2, 'won'));
    }

    /**
     * Tests that resuming keeps the attempt on the same row (same id), never inserting a
     * second one.
     *
     * @return void
     */
    public function test_resume_or_create_does_not_insert_a_new_row(): void {
        global $DB;

        $firsttoken = security::generate_attempt_token(1, 2);
        $originalid = $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $firsttoken]);

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(1, $DB->count_records('playerpuzzle_attempts', ['playerpuzzleid' => 1, 'userid' => 2]));
        $resumedid = $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $result->token]);
        $this->assertSame((int) $originalid, (int) $resumedid);
    }

    /**
     * Tests that resuming ignores an in-progress attempt belonging to a different
     * instance or user — cross-instance/cross-user isolation, same guarantee
     * validate_and_consume_token() already gives at submission time.
     *
     * @return void
     */
    public function test_resume_or_create_ignores_other_instance_and_user(): void {
        security::generate_attempt_token(999, 2);
        security::generate_attempt_token(1, 999);

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(1, $result->currentlevel);
        $this->assertSame(1, $result->currentphase);
    }

    /**
     * Tests that, when more than one stale in-progress row exists for the same user/
     * instance (a site upgraded from before this method existed, when every play.php
     * load inserted a fresh row), the most recently created one is resumed — the only
     * sane resolution, since get_record() alone would fatal on more than one match.
     *
     * @return void
     */
    public function test_resume_or_create_picks_most_recent_when_multiple_stale_rows_exist(): void {
        global $DB;

        $oldtoken = security::generate_attempt_token(1, 2);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 1, ['token' => $oldtoken]);
        $DB->set_field('playerpuzzle_attempts', 'timecreated', time() - 100, ['token' => $oldtoken]);

        $newtoken = security::generate_attempt_token(1, 2);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 4, ['token' => $newtoken]);

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(4, $result->currentlevel);
    }

    /**
     * Tests clean_difficulty() keeps known values and coerces anything else to normal.
     *
     * @return void
     */
    public function test_clean_difficulty(): void {
        $this->assertSame('easy', security::clean_difficulty('easy'));
        $this->assertSame('normal', security::clean_difficulty('normal'));
        $this->assertSame('hard', security::clean_difficulty('hard'));
        $this->assertSame('normal', security::clean_difficulty('impossible'));
        $this->assertSame('normal', security::clean_difficulty(''));
    }

    /**
     * Tests a fresh attempt stores the chosen difficulty, coercing an unknown value.
     *
     * @return void
     */
    public function test_generate_attempt_token_stores_difficulty(): void {
        global $DB;

        $token = security::generate_attempt_token(1, 2, 'hard');
        $this->assertSame('hard', $DB->get_field('playerpuzzle_attempts', 'difficulty', ['token' => $token]));

        $token = security::generate_attempt_token(1, 2, 'bogus');
        $this->assertSame('normal', $DB->get_field('playerpuzzle_attempts', 'difficulty', ['token' => $token]));
    }

    /**
     * Tests resume_or_create_token() returns the chosen difficulty on a fresh attempt, and
     * keeps the in-progress attempt's own locked difficulty when resuming — a later call
     * asking for a different difficulty does not change it.
     *
     * @return void
     */
    public function test_resume_or_create_locks_difficulty_on_resume(): void {
        $fresh = security::resume_or_create_attempt_token(1, 2, 'hard');
        $this->assertSame('hard', $fresh->difficulty);

        $resumed = security::resume_or_create_attempt_token(1, 2, 'easy');
        $this->assertSame('hard', $resumed->difficulty);
    }
}
