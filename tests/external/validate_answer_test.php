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
 * External function tests for validate_answer.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\questions_repository;

/**
 * Tests for the mod_playerpuzzle_validate_answer web service.
 *
 * @covers \mod_playerpuzzle\external\validate_answer
 * @covers \mod_playerpuzzle\local\engine\question_fetcher
 */
final class validate_answer_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a plain playerpuzzle instance.
     *
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        return $generator->create_instance(['course' => $this->course->id]);
    }

    /**
     * Creates one approved multichoice question, with "One" as the correct answer.
     *
     * @param int $playerpuzzleid The instance id.
     * @return int The new question id.
     */
    private function make_question(int $playerpuzzleid): int {
        return questions_repository::add_question(
            $playerpuzzleid,
            'multichoice',
            'One of four?',
            '',
            [
                ['text' => 'One', 'iscorrect' => true],
                ['text' => 'Two', 'iscorrect' => false],
                ['text' => 'Three', 'iscorrect' => false],
                ['text' => 'Four', 'iscorrect' => false],
            ],
            2
        );
    }

    /**
     * Creates an approved true/false question whose correct answer is "True".
     *
     * @param int $playerpuzzleid The instance id.
     * @return int The new question id.
     */
    private function make_truefalse_question(int $playerpuzzleid): int {
        return questions_repository::add_question(
            $playerpuzzleid,
            'truefalse',
            'The sky is blue.',
            '',
            [
                ['text' => get_string('true', 'qtype_truefalse'), 'iscorrect' => true],
                ['text' => get_string('false', 'qtype_truefalse'), 'iscorrect' => false],
            ],
            2
        );
    }

    /**
     * Finds the id of the answer with the given text for a question.
     *
     * @param int $questionid Question ID.
     * @param int $playerpuzzleid The instance the question belongs to.
     * @param string $text Exact answer text to look for.
     * @return int The matching answer ID.
     */
    private function find_answer_id(int $questionid, int $playerpuzzleid, string $text): int {
        $question = questions_repository::get_question($questionid, $playerpuzzleid);
        foreach ($question->answers as $answer) {
            if ($answer->answertext === $text) {
                return (int) $answer->id;
            }
        }

        $this->fail("No answer with text '$text' found for question $questionid.");
    }

    /**
     * Finds the id of the answer marked correct for a question.
     *
     * @param int $questionid Question ID.
     * @param int $playerpuzzleid The instance the question belongs to.
     * @return int The correct answer ID.
     */
    private function find_correct_answer_id(int $questionid, int $playerpuzzleid): int {
        $question = questions_repository::get_question($questionid, $playerpuzzleid);
        $correct = array_values(array_filter($question->answers, fn($a) => (int) $a->iscorrect === 1));

        return (int) $correct[0]->id;
    }

    /**
     * Sets the question currently "open" for an attempt directly on the row — mirrors what
     * draw_question.php would have stored, without needing a real Ajax round trip in every
     * test. validate_answer.php trusts only this column, never a client-supplied questionid
     * (security audit finding, Fase 9).
     *
     * @param string $token The attempt's token.
     * @param int $questionid Question id to mark as currently open.
     * @return void
     */
    private function put_question_open(string $token, int $questionid): void {
        global $DB;
        $DB->set_field('playerpuzzle_attempts', 'currentquestionid', $questionid, ['token' => $token]);
    }

    /**
     * Calls the mod_playerpuzzle_validate_answer web service through the real dispatch
     * path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_validate_answer(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_validate_answer', $args);
    }

    /**
     * Tests that a correct answer is reported correct.
     *
     * @return void
     */
    public function test_correct_answer_returns_true(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);
        $correctid = $this->find_answer_id($questionid, (int) $instance->id, 'One');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->put_question_open($token, $questionid);
        $result = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => $correctid,
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['correct']);
    }

    /**
     * Tests that a wrong answer is reported incorrect and carries the real correct
     * answer id for post-submission feedback.
     *
     * @return void
     */
    public function test_wrong_answer_returns_false_with_correct_id(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);
        $wrongid = $this->find_answer_id($questionid, (int) $instance->id, 'Two');
        $correctid = $this->find_answer_id($questionid, (int) $instance->id, 'One');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->put_question_open($token, $questionid);
        $result = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => $wrongid,
        ]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['correct']);
        $this->assertSame($correctid, (int) $result['data']['correctanswerid']);
    }

    /**
     * Tests that no open question at all (a fresh attempt that never called
     * draw_question.php) is treated the same as an invalid one — never a coding error.
     *
     * @return void
     */
    public function test_no_question_open_returns_false(): void {
        $instance = $this->make_instance();

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $result = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => 1,
        ]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['correct']);
    }

    /**
     * Tests that a question belonging to a different instance is rejected — never
     * validated, even if the answer id supplied really is that question's correct one.
     * This is the instance-isolation guard validate_answer.php enforces. Simulates a
     * corrupted/forged currentquestionid rather than a client-supplied one, since the
     * client can no longer name a questionid at all (security audit fix, Fase 9) — the
     * check still matters as defense in depth.
     *
     * @return void
     */
    public function test_question_from_other_instance_is_rejected(): void {
        $instance = $this->make_instance();
        $otherinstance = $this->make_instance();
        $foreignquestionid = $this->make_question((int) $otherinstance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->put_question_open($token, $foreignquestionid);
        $result = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => 1,
        ]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['correct']);
    }

    /**
     * Tests that an unapproved question (e.g. AI-generated, pending review) is rejected
     * even if its id genuinely belongs to this instance — a student must never be able to
     * answer a question the teacher has not approved yet. draw_question.php never draws an
     * unapproved question in the first place; this covers one becoming unapproved between
     * the draw and the answer (e.g. a teacher editing the bank mid-match).
     *
     * @return void
     */
    public function test_unapproved_question_is_rejected(): void {
        $instance = $this->make_instance();
        $questionid = questions_repository::add_question(
            (int) $instance->id,
            'multichoice',
            'Pending AI question?',
            '',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2,
            'ai',
            false
        );

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->put_question_open($token, $questionid);
        $result = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => 1,
        ]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['correct']);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced, not just
     * declared — a role with it explicitly prohibited is denied. cm_info's own
     * visibility computation reads this exact capability (is_user_access_restricted_
     * by_capability(), core/classes/cm_info.php), so the module becomes uservisible =
     * false and validate_context()'s require_login() call rejects the request before
     * execute()'s own require_capability() line is ever reached.
     *
     * @return void
     */
    public function test_requires_view_capability(): void {
        $instance = $this->make_instance();
        $modcontext = context_module::instance($instance->cmid);

        $prohibitedrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/playerpuzzle:view', CAP_PROHIBIT, $prohibitedrole, $modcontext);
        role_assign($prohibitedrole, $this->student->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->student);
        $this->expectException(\core\exception\require_login_exception::class);
        validate_answer::execute($instance->cmid, 'anytoken', 1);
    }

    /**
     * Tests that a token not matching an in-progress attempt for this user/instance is
     * rejected with the dedicated exception, never silently accepted.
     *
     * @return void
     */
    public function test_unknown_token_is_rejected(): void {
        $instance = $this->make_instance();

        $this->setUser($this->student);
        $result = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => 'deadbeef',
            'answerid' => 1,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that the web service no longer accepts a questionid argument at all — the
     * parameter was removed, not merely ignored, so a client attempting to supply one is
     * rejected by Moodle's own parameter validation before execute() ever runs. This is the
     * actual close of the security audit finding: there is no longer any shape of request
     * that lets the client name which question to probe.
     *
     * @return void
     */
    public function test_a_client_supplied_questionid_is_rejected_as_an_unknown_parameter(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->put_question_open($token, $questionid);

        $result = $this->call_validate_answer([
            'cmid'       => $instance->cmid,
            'token'      => $token,
            'questionid' => $questionid,
            'answerid'   => 1,
        ]);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidparameter', $result['exception']->errorcode);
    }

    /**
     * Tests that a player answer is logged for the post-game review — a text snapshot of
     * the question, the chosen answer and the correct one, with the outcome.
     *
     * @return void
     */
    public function test_player_answer_is_logged(): void {
        global $DB;

        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);
        $wrongid = $this->find_answer_id($questionid, (int) $instance->id, 'Two');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $DB->set_field('playerpuzzle_attempts', 'currentlevel', 3, ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'currentphase', 7, ['token' => $token]);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $this->put_question_open($token, $questionid);
        $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => $wrongid,
        ]);

        $rows = $DB->get_records('playerpuzzle_attempt_questions', ['attemptid' => $attemptid]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(3, (int) $row->attemptlevel);
        $this->assertSame(7, (int) $row->attemptphase);
        $this->assertSame(0, (int) $row->iscorrect);
        $this->assertStringContainsString('Two', $row->chosenanswer);
        $this->assertStringContainsString('One', $row->correctanswer);

        // The boss path is never logged — only the student's own answers. A fresh draw is
        // required first, since the player call above already consumed the open question.
        $this->put_question_open($token, $questionid);
        $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => 0,
            'forwhom'  => 'boss',
        ]);
        $this->assertCount(1, $DB->get_records('playerpuzzle_attempt_questions', ['attemptid' => $attemptid]));
    }

    /**
     * Tests that on Hard the boss's server-drawn guess always lands on the correct answer
     * (100% precision), for both question types, and that the submitted answerid is ignored.
     * Also proves the exploit the security audit flagged is closed structurally, not just
     * behaviourally: even though a single call already reveals the answer (100% precision on
     * Hard is an intentional difficulty feature, not the bug), the client can no longer name
     * *which* question to probe — see test_a_client_supplied_questionid_is_rejected_as_an_
     * unknown_parameter() for that half of the fix.
     *
     * @return void
     */
    public function test_boss_guess_on_hard_is_always_correct(): void {
        $instance = $this->make_instance();
        $mcid = $this->make_question((int) $instance->id);
        $tfid = $this->make_truefalse_question((int) $instance->id);
        $mccorrect = $this->find_correct_answer_id($mcid, (int) $instance->id);
        $tfcorrect = $this->find_correct_answer_id($tfid, (int) $instance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'hard');

        foreach ([[$mcid, $mccorrect], [$tfid, $tfcorrect]] as [$qid, $correctid]) {
            for ($i = 0; $i < 10; $i++) {
                // Each call consumes the open question, so it is re-opened every iteration —
                // mirrors draw_question.php drawing fresh once the previous one is spent.
                $this->put_question_open($token, $qid);
                $result = $this->call_validate_answer([
                    'cmid'     => $instance->cmid,
                    'token'    => $token,
                    'answerid' => 999999,
                    'forwhom'  => 'boss',
                ]);
                $this->assertFalse($result['error']);
                $this->assertTrue($result['data']['correct']);
                $this->assertSame($correctid, (int) $result['data']['pickedanswerid']);
            }
        }
    }

    /**
     * Tests that on Easy the boss's multichoice guess is correct roughly a third of the
     * time — a statistical check with generous bounds around the 0.33 target, never the
     * near-certainty a higher difficulty would give.
     *
     * @return void
     */
    public function test_boss_guess_on_easy_multichoice_is_roughly_one_third(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'easy');

        $runs = 400;
        $hits = 0;
        for ($i = 0; $i < $runs; $i++) {
            $this->put_question_open($token, $questionid);
            $result = $this->call_validate_answer([
                'cmid'     => $instance->cmid,
                'token'    => $token,
                'answerid' => 0,
                'forwhom'  => 'boss',
            ]);
            $hits += $result['data']['correct'] ? 1 : 0;
        }

        $rate = $hits / $runs;
        $this->assertGreaterThan(0.20, $rate);
        $this->assertLessThan(0.47, $rate);
    }

    /**
     * Tests that a boss guess consumes the currently open question — a second call with no
     * fresh draw_question.php call in between finds nothing open. This is the actual
     * anti-replay guarantee for the fix: even the one legitimate question a real boss turn
     * may reveal can only ever be spent once, never re-queried.
     *
     * @return void
     */
    public function test_boss_guess_consumes_the_current_question(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id, 'hard');
        $this->put_question_open($token, $questionid);

        $first = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => 0,
            'forwhom'  => 'boss',
        ]);
        $this->assertTrue($first['data']['correct']);

        $second = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => 0,
            'forwhom'  => 'boss',
        ]);
        $this->assertFalse($second['data']['correct']);
    }

    /**
     * Tests that a player answer likewise consumes the currently open question.
     *
     * @return void
     */
    public function test_player_answer_consumes_the_current_question(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);
        $correctid = $this->find_answer_id($questionid, (int) $instance->id, 'One');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $this->put_question_open($token, $questionid);

        $first = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => $correctid,
        ]);
        $this->assertTrue($first['data']['correct']);

        $second = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => $correctid,
        ]);
        $this->assertFalse($second['data']['correct']);
    }

    /**
     * Tests that a player answer increments the attempt's questions_total (right or
     * wrong) and questions_correct (only when right), and that the response carries the
     * server-counted total back — the source of truth the client mirrors for the
     * boss-revive rule and the "Perguntas: X/N" HUD counter.
     *
     * @return void
     */
    public function test_player_answer_increments_question_counters(): void {
        global $DB;

        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);
        $correctid = $this->find_answer_id($questionid, (int) $instance->id, 'One');
        $wrongid = $this->find_answer_id($questionid, (int) $instance->id, 'Two');

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $this->put_question_open($token, $questionid);
        $result = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => $wrongid,
        ]);
        $this->assertSame(1, $result['data']['questionstotal']);
        $this->assertSame(1, (int) $DB->get_field('playerpuzzle_attempts', 'questions_total', ['id' => $attemptid]));
        $this->assertSame(0, (int) $DB->get_field('playerpuzzle_attempts', 'questions_correct', ['id' => $attemptid]));

        $this->put_question_open($token, $questionid);
        $result = $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => $correctid,
        ]);
        $this->assertSame(2, $result['data']['questionstotal']);
        $this->assertSame(2, (int) $DB->get_field('playerpuzzle_attempts', 'questions_total', ['id' => $attemptid]));
        $this->assertSame(1, (int) $DB->get_field('playerpuzzle_attempts', 'questions_correct', ['id' => $attemptid]));
    }

    /**
     * Tests that the boss's own guess never touches the attempt's question counters —
     * only the student's own answers count toward the minimum-questions requirement.
     *
     * @return void
     */
    public function test_boss_guess_does_not_increment_question_counters(): void {
        global $DB;

        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);

        $this->put_question_open($token, $questionid);
        $this->call_validate_answer([
            'cmid'     => $instance->cmid,
            'token'    => $token,
            'answerid' => 0,
            'forwhom'  => 'boss',
        ]);

        $this->assertSame(0, (int) $DB->get_field('playerpuzzle_attempts', 'questions_total', ['id' => $attemptid]));
    }
}
