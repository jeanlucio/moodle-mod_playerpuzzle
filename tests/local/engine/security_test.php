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
     * Tests that generate_attempt_token() flags a Demo request, both on the persisted row
     * and (via generate) never as a side effect of prior attempt history.
     *
     * @return void
     */
    public function test_generate_attempt_token_flags_a_demo_request(): void {
        global $DB;

        $token = security::generate_attempt_token(1, 2, 'normal', 1, 1, true);

        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(1, (int) $attempt->isdemo);
    }

    /**
     * Tests that a real (non-Demo) request never flags isdemo, regardless of prior attempt
     * history — unlike the old istutorial mechanism, isdemo depends only on the caller's own
     * request, never on "ausência de registros".
     *
     * @return void
     */
    public function test_generate_attempt_token_does_not_flag_a_real_attempt(): void {
        global $DB;

        $token = security::generate_attempt_token(1, 2);

        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(0, (int) $attempt->isdemo);
    }

    /**
     * Tests that resume_or_create_attempt_token() surfaces isdemo for a brand new attempt,
     * both for a real request and a Demo one.
     *
     * @return void
     */
    public function test_resume_or_create_surfaces_isdemo_for_a_new_attempt(): void {
        $real = security::resume_or_create_attempt_token(1, 2);
        $this->assertFalse($real->isdemo);

        $demo = security::resume_or_create_attempt_token(1, 3, 'normal', 10, true);
        $this->assertTrue($demo->isdemo);
    }

    /**
     * Tests that resuming an in-progress Demo attempt keeps isdemo true, read from the
     * persisted row.
     *
     * @return void
     */
    public function test_resume_or_create_keeps_isdemo_true_on_resume(): void {
        security::generate_attempt_token(1, 2, 'normal', 1, 1, true);

        $result = security::resume_or_create_attempt_token(1, 2, 'normal', 10, true);

        $this->assertTrue($result->isdemo);
    }

    /**
     * Tests that a Demo request never resumes a real in-progress attempt, and vice versa —
     * the two are entirely separate resume namespaces, so a lingering one never intercepts
     * the other.
     *
     * @return void
     */
    public function test_resume_or_create_isdemo_is_a_separate_resume_namespace(): void {
        $realtoken = security::resume_or_create_attempt_token(1, 2)->token;
        $demoresult = security::resume_or_create_attempt_token(1, 2, 'normal', 10, true);
        $this->assertNotSame($realtoken, $demoresult->token);
        $this->assertTrue($demoresult->isdemo);

        $realagain = security::resume_or_create_attempt_token(1, 2);
        $this->assertFalse($realagain->isdemo);
    }

    /**
     * Tests that a Demo attempt always starts at Level 1/Phase 1, ignoring any inherited
     * resume position a real attempt might otherwise carry from a prior loss.
     *
     * @return void
     */
    public function test_resume_or_create_demo_always_starts_at_level_one_phase_one(): void {
        global $DB;

        $realtoken = security::generate_attempt_token(1, 2, 'normal', 3, 5);
        security::validate_and_consume_token($realtoken, 1, 2, 'lost');

        $demo = security::resume_or_create_attempt_token(1, 2, 'normal', 10, true);

        $this->assertSame(1, $demo->currentlevel);
        $this->assertSame(1, $demo->currentphase);
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
     * Tests resume_or_create_token() applies the chosen difficulty on a fresh attempt, and
     * on resume returns the in-progress attempt's own stored difficulty unchanged — the
     * requested value is not applied (advance_phase is what changes difficulty between
     * Campaign phases).
     *
     * @return void
     */
    public function test_resume_or_create_keeps_attempt_difficulty_on_resume(): void {
        $fresh = security::resume_or_create_attempt_token(1, 2, 'hard');
        $this->assertSame('hard', $fresh->difficulty);

        $resumed = security::resume_or_create_attempt_token(1, 2, 'easy');
        $this->assertSame('hard', $resumed->difficulty);
    }

    /**
     * Tests a brand new attempt starts questionstotal at 0.
     *
     * @return void
     */
    public function test_resume_or_create_starts_questionstotal_at_zero(): void {
        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(0, $result->questionstotal);
    }

    /**
     * Tests resuming an in-progress attempt carries forward the questions already answered
     * in earlier phases — the minimum-questions counter is cumulative for the whole
     * attempt and never resets on a page reload.
     *
     * @return void
     */
    public function test_resume_or_create_carries_forward_questionstotal(): void {
        global $DB;

        $firsttoken = security::generate_attempt_token(1, 2);
        $DB->set_field('playerpuzzle_attempts', 'questions_total', 5, ['token' => $firsttoken]);

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(5, $result->questionstotal);
    }

    /**
     * Tests that a fresh attempt starts with no combat checkpoint — there is
     * no fight yet for board.js/combat.js to resume.
     *
     * @return void
     */
    public function test_resume_or_create_starts_combatstate_at_null(): void {
        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertNull($result->combatstate);
    }

    /**
     * Tests that resuming an in-progress attempt decodes a previously saved checkpoint into
     * the result, for board.js/combat.js to rebuild the exact fight in progress.
     *
     * @return void
     */
    public function test_resume_or_create_decodes_a_saved_combatstate(): void {
        global $DB;

        $firsttoken = security::generate_attempt_token(1, 2);
        $DB->set_field(
            'playerpuzzle_attempts',
            'combatstate',
            '{"boardgrid":[1,2,3],"currentturn":"boss"}',
            ['token' => $firsttoken]
        );

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame([1, 2, 3], $result->combatstate['boardgrid']);
        $this->assertSame('boss', $result->combatstate['currentturn']);
    }

    /**
     * Tests that a checkpoint left with the boss already at 0 HP is discarded on resume,
     * instead of reopening an already-won fight. This is the state a student can be left in
     * if they exit from the phase-complete screen before advance_phase runs: the pagehide
     * safety net (save_combat_state) still persists the just-finished (HP-0) snapshot for the
     * phase that was never actually advanced.
     *
     * @return void
     */
    public function test_resume_or_create_discards_a_checkpoint_with_dead_boss(): void {
        global $DB;

        $token = security::generate_attempt_token(1, 2);
        $DB->set_field(
            'playerpuzzle_attempts',
            'combatstate',
            '{"boardgrid":[1,2,3],"currentturn":"boss","currentbosshp":0}',
            ['token' => $token]
        );

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertNull($result->combatstate);
    }

    /**
     * Tests that a brand new attempt resumes on the level/phase of the student's most
     * recently finished attempt when that attempt was lost — the original "an attempt is a
     * continuous winning streak" design (only a loss should send the student back to
     * re-fight the phase they lost, never the whole campaign from Level 1).
     *
     * @return void
     */
    public function test_resume_or_create_resumes_level_and_phase_after_a_loss(): void {
        $lost = security::generate_attempt_token(1, 2);
        global $DB;
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 3, ['token' => $lost]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 7, ['token' => $lost]);
        security::validate_and_consume_token($lost, 1, 2, 'lost');

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertTrue($result->isnew);
        $this->assertSame(3, $result->currentlevel);
        $this->assertSame(7, $result->currentphase);
    }

    /**
     * Tests that winning the whole campaign still starts the next attempt fresh at Level 1,
     * Phase 1 — finishing the campaign is completing it, not a loss to re-fight.
     *
     * @return void
     */
    public function test_resume_or_create_starts_fresh_after_winning_the_campaign(): void {
        $won = security::generate_attempt_token(1, 2);
        global $DB;
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 10, ['token' => $won]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 10, ['token' => $won]);
        security::validate_and_consume_token($won, 1, 2, 'won');

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(1, $result->currentlevel);
        $this->assertSame(1, $result->currentphase);
    }

    /**
     * Tests that only the most recently *finished* attempt matters, not the most recent
     * loss: a student who lost, retried, and this time won the whole campaign must get a
     * fresh Level 1/Phase 1 attempt next, never jump back to the earlier loss.
     *
     * @return void
     */
    public function test_resume_or_create_ignores_an_older_loss_after_a_later_win(): void {
        global $DB;

        $lost = security::generate_attempt_token(1, 2);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 3, ['token' => $lost]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 7, ['token' => $lost]);
        security::validate_and_consume_token($lost, 1, 2, 'lost');

        $won = security::generate_attempt_token(1, 2);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 10, ['token' => $won]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 10, ['token' => $won]);
        security::validate_and_consume_token($won, 1, 2, 'won');

        $result = security::resume_or_create_attempt_token(1, 2);

        $this->assertSame(1, $result->currentlevel);
        $this->assertSame(1, $result->currentphase);
    }

    /**
     * Tests that an inherited level beyond the instance's current level count (the teacher
     * reduced it after the student had reached further) is clamped down to that ceiling's
     * own Phase 1, rather than resuming on a level/phase pair that no longer exists.
     *
     * @return void
     */
    public function test_resume_or_create_clamps_inherited_level_to_reduced_maxlevels(): void {
        $lost = security::generate_attempt_token(1, 2);
        global $DB;
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 8, ['token' => $lost]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 4, ['token' => $lost]);
        security::validate_and_consume_token($lost, 1, 2, 'lost');

        $result = security::resume_or_create_attempt_token(1, 2, 'normal', 5);

        $this->assertSame(5, $result->currentlevel);
        $this->assertSame(1, $result->currentphase);
    }

    /**
     * Tests determine_start_level() directly for the no-prior-attempt case, since
     * resume_or_create_attempt_token() alone cannot distinguish "no prior attempt" from
     * "prior attempt already at 1/1" in its return value.
     *
     * @return void
     */
    public function test_determine_start_level_with_no_prior_attempt(): void {
        [$level, $phase] = security::determine_start_level(1, 2, 10);

        $this->assertSame(1, $level);
        $this->assertSame(1, $phase);
    }

    /**
     * Tests that determine_start_level() ignores a finished Demo attempt entirely, even
     * when it is the most recent finished row — a Demo loss must never change where a
     * student's real Campaign run resumes.
     *
     * @return void
     */
    public function test_determine_start_level_ignores_demo_attempts(): void {
        $demotoken = security::generate_attempt_token(1, 2, 'normal', 1, 1, true);
        security::validate_and_consume_token($demotoken, 1, 2, 'lost');

        [$level, $phase] = security::determine_start_level(1, 2, 10);

        $this->assertSame(1, $level);
        $this->assertSame(1, $phase);
    }

    /**
     * Tests that has_inprogress_attempt() reflects a genuine in-progress row, is false
     * before one exists, and ignores other users/instances/final statuses.
     *
     * @return void
     */
    public function test_has_inprogress_attempt(): void {
        $this->assertFalse(security::has_inprogress_attempt(1, 2));

        security::generate_attempt_token(1, 2);
        $this->assertTrue(security::has_inprogress_attempt(1, 2));

        $this->assertFalse(security::has_inprogress_attempt(1, 3), 'Different user.');
        $this->assertFalse(security::has_inprogress_attempt(9, 2), 'Different instance.');
    }

    /**
     * Tests that has_inprogress_attempt() ignores an in-progress Demo attempt — it must
     * never be mistaken for a real attempt to resume.
     *
     * @return void
     */
    public function test_has_inprogress_attempt_ignores_demo_attempts(): void {
        security::generate_attempt_token(1, 2, 'normal', 1, 1, true);

        $this->assertFalse(security::has_inprogress_attempt(1, 2));
    }

    /**
     * Tests that has_inprogress_attempt() is false once the attempt has reached a final
     * status — a finished attempt is not something to resume.
     *
     * @return void
     */
    public function test_has_inprogress_attempt_ignores_final_statuses(): void {
        $token = security::generate_attempt_token(1, 2);
        security::validate_and_consume_token($token, 1, 2, 'won');

        $this->assertFalse(security::has_inprogress_attempt(1, 2));
    }
}
