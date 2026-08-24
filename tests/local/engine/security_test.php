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
}
