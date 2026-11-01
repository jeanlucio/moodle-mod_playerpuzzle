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
 * External function tests for draw_question.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use mod_playerpuzzle\local\engine\question_fetcher;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\move_log;
use mod_playerpuzzle\local\questions_repository;

/**
 * Tests for the mod_playerpuzzle_draw_question web service.
 *
 * @covers \mod_playerpuzzle\external\draw_question
 */
final class draw_question_test extends \advanced_testcase {
    /** @var array The first event of a seed-56 phase: a match that opens the player's question. */
    private const DUE_MOVE = ['type' => 'move', 'r1' => 0, 'c1' => 7, 'r2' => 1, 'c2' => 7];

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
     * Creates a playerpuzzle instance.
     *
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_instance(array $overrides = []): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        return $generator->create_instance(array_merge(['course' => $this->course->id], $overrides));
    }

    /**
     * Creates one approved multichoice question.
     *
     * @param int $playerpuzzleid The instance id.
     * @param string $hint Optional hint text.
     * @return int The new question id.
     */
    private function make_question(int $playerpuzzleid, string $hint = ''): int {
        return questions_repository::add_question(
            $playerpuzzleid,
            'multichoice',
            'One of four?',
            $hint,
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
     * Calls the mod_playerpuzzle_draw_question web service through the real dispatch path.
     *
     * @param array $args Web service arguments.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call_draw_question(array $args): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_draw_question', $args);
    }

    /**
     * Creates an attempt whose replayed log opens a player question: with this seed, swapping
     * the cells below is a match that fills the player's mana meter (see DUE_MOVE).
     *
     * @param int $playerpuzzleid The instance id.
     * @param bool $isdemo Whether the attempt is a Demo.
     * @return string The attempt token.
     */
    private function make_attempt_token(int $playerpuzzleid, bool $isdemo = false): string {
        global $DB;

        $token = security::generate_attempt_token($playerpuzzleid, (int) $this->student->id, 'normal', 1, 1, $isdemo);
        $DB->set_field('playerpuzzle_attempts', 'rngseed', 56, ['token' => $token]);
        $DB->set_field('playerpuzzle_attempts', 'frozenbasebosshp', 100000, ['token' => $token]);

        return $token;
    }

    /**
     * Calls validate_answer through the real dispatch path.
     *
     * @param int $cmid Course module id.
     * @param string $token Attempt token.
     * @param int $answerid Chosen answer id.
     * @param string $forwhom 'player' or 'boss'.
     * @return array The dispatch response.
     */
    private function call_validate_answer(int $cmid, string $token, int $answerid, string $forwhom): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_validate_answer', [
            'cmid' => $cmid,
            'token' => $token,
            'answerid' => $answerid,
            'forwhom' => $forwhom,
        ]);
    }

    /**
     * Tests that a fresh attempt with no question open yet draws one, persisting it as the
     * attempt's own currentquestionid.
     *
     * @return void
     */
    public function test_draws_a_fresh_question_and_persists_it(): void {
        global $DB;

        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = $this->make_attempt_token((int) $instance->id);

        $result = $this->call_draw_question([
            'cmid' => $instance->cmid,
            'token' => $token,
            'movelog' => [self::DUE_MOVE],
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['available']);
        $this->assertSame($questionid, $result['data']['id']);
        $this->assertNotEmpty($result['data']['options']);
        $this->assertSame(
            $questionid,
            (int) $DB->get_field('playerpuzzle_attempts', 'currentquestionid', ['token' => $token])
        );
    }

    /**
     * Tests that a returned option never carries any correctness signal — the Blind JSON
     * contract.
     *
     * @return void
     */
    public function test_never_leaks_correctness(): void {
        $instance = $this->make_instance();
        $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = $this->make_attempt_token((int) $instance->id);

        $result = $this->call_draw_question([
            'cmid' => $instance->cmid,
            'token' => $token,
            'movelog' => [self::DUE_MOVE],
        ]);

        $this->assertNotEmpty($result['data']['options']);
        foreach ($result['data']['options'] as $option) {
            $this->assertSame(['id', 'text'], array_keys($option));
        }
    }

    /**
     * Tests that calling draw_question again while a question is still open re-serves the
     * exact same one, rather than drawing a fresh random question — the guarantee that keeps
     * a "draw, peek via forwhom=boss, draw again" loop from bulk-harvesting the bank: a new
     * draw is only ever granted once the open one is actually consumed by validate_answer.
     *
     * @return void
     */
    public function test_reserves_the_same_open_question_until_consumed(): void {
        $instance = $this->make_instance();
        for ($i = 0; $i < 5; $i++) {
            $this->make_question((int) $instance->id);
        }

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $first = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);
        for ($i = 0; $i < 10; $i++) {
            $again = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);
            $this->assertSame($first['data']['id'], $again['data']['id']);
        }
    }

    /**
     * Tests that once the open question is answered, no further question is drawn until the
     * replayed log reaches another one: answering is what consumes the draw, and the log — not
     * the client's say-so — decides when the next is due.
     *
     * @return void
     */
    public function test_does_not_draw_again_until_the_log_reaches_another_question(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = $this->make_attempt_token((int) $instance->id);

        $drawn = $this->call_draw_question([
            'cmid' => $instance->cmid,
            'token' => $token,
            'movelog' => [self::DUE_MOVE],
        ]);
        $this->assertTrue($drawn['data']['available']);
        $answerid = (int) question_fetcher::get_correct_answer_id((int) $drawn['data']['id']);
        $answered = $this->call_validate_answer((int) $instance->cmid, $token, $answerid, 'player');
        $this->assertTrue($answered['data']['correct']);

        $again = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token, 'eventoffset' => 1]);

        $this->assertFalse($again['error']);
        $this->assertFalse($again['data']['available']);
        $this->assertSame(
            0,
            (int) $DB->get_field('playerpuzzle_attempts', 'currentquestionid', ['token' => $token])
        );
    }

    /**
     * Tests that calling draw_question and validate_answer in a loop, with no combat played,
     * cannot raise the question counters that feed the grade: no question is due until the
     * replayed log says so.
     *
     * @return void
     */
    public function test_a_draw_validate_loop_without_playing_cannot_inflate_the_counters(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = $this->make_attempt_token((int) $instance->id);

        for ($i = 0; $i < 5; $i++) {
            $drawn = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);
            $this->assertFalse($drawn['data']['available']);
            $this->call_validate_answer((int) $instance->cmid, $token, 0, 'player');
        }

        $attempt = $DB->get_record('playerpuzzle_attempts', ['token' => $token], '*', MUST_EXIST);
        $this->assertSame(0, (int) $attempt->questions_total);
        $this->assertSame(0, (int) $attempt->questions_correct);
    }

    /**
     * Tests that peeking at the open question with a boss validation, which stores an outcome
     * on the wrong side, does not leave the player's question looking open for a second draw:
     * the loop would otherwise raise the counters one pass at a time.
     *
     * @return void
     */
    public function test_a_stray_boss_outcome_does_not_keep_the_question_due(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = $this->make_attempt_token((int) $instance->id);

        $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token, 'movelog' => [self::DUE_MOVE]]);
        $this->call_validate_answer((int) $instance->cmid, $token, 0, 'boss');
        $second = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token, 'eventoffset' => 1]);

        $this->assertFalse($second['data']['available']);
        $this->assertSame(
            0,
            (int) $DB->get_field('playerpuzzle_attempts', 'questions_total', ['token' => $token])
        );
    }

    /**
     * Tests that a Demo attempt keeps drawing freely: it counts for nothing, and its replay is
     * not trusted at all.
     *
     * @return void
     */
    public function test_a_demo_attempt_draws_without_a_due_question(): void {
        $instance = $this->make_instance();
        $questionid = $this->make_question((int) $instance->id);

        $this->setUser($this->student);
        $token = $this->make_attempt_token((int) $instance->id, true);

        $result = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);

        $this->assertTrue($result['data']['available']);
        $this->assertSame($questionid, $result['data']['id']);
    }

    /**
     * Tests that a question's own hint is never offered when hints_enabled is off, even
     * though the question genuinely has one — the client-side "Use Hint" button renders
     * purely off this flag, so forcing it false here is what hides it, with no changes
     * needed on the JS side.
     *
     * @return void
     */
    public function test_hashint_is_false_when_hints_disabled(): void {
        $instance = $this->make_instance(['hints_enabled' => 0]);
        $this->make_question((int) $instance->id, 'A real hint.');

        $this->setUser($this->student);
        $token = $this->make_attempt_token((int) $instance->id);

        $result = $this->call_draw_question([
            'cmid' => $instance->cmid,
            'token' => $token,
            'movelog' => [self::DUE_MOVE],
        ]);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['available']);
        $this->assertFalse($result['data']['hashint']);
    }

    /**
     * Tests that an instance with no approved questions at all reports unavailable, rather
     * than a coding error.
     *
     * @return void
     */
    public function test_unavailable_without_any_approved_question(): void {
        $instance = $this->make_instance();

        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $result = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => $token]);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['available']);
        $this->assertSame(0, $result['data']['id']);
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
        $result = $this->call_draw_question(['cmid' => $instance->cmid, 'token' => 'deadbeef']);

        $this->assertTrue($result['error']);
        $this->assertSame('invalidattempttoken', $result['exception']->errorcode);
    }

    /**
     * Tests that the mod/playerpuzzle:view capability is actually enforced.
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
        draw_question::execute($instance->cmid, 'anytoken');
    }

    /**
     * Tests that the events still pending on the client (the move that triggered the
     * question) are stored before the question is drawn, and the reply says where the client
     * continues from.
     *
     * @return void
     */
    public function test_stores_the_pending_events_before_drawing(): void {
        global $DB;

        $instance = $this->make_instance();
        $this->make_question((int) $instance->id);
        $this->setUser($this->student);
        $token = security::generate_attempt_token((int) $instance->id, (int) $this->student->id);

        $move = ['type' => 'move', 'r1' => 2, 'c1' => 3, 'r2' => 2, 'c2' => 4];
        $result = $this->call_draw_question([
            'cmid' => $instance->cmid,
            'token' => $token,
            'eventoffset' => 0,
            'movelog' => [$move],
        ]);

        $this->assertFalse($result['error']);
        $this->assertSame(1, $result['data']['eventcount']);
        $stored = move_log::decode($DB->get_field('playerpuzzle_attempts', 'movelog', ['token' => $token]));
        $this->assertSame([$move], $stored);
    }

    /**
     * Tests that a phase started under an earlier engine version (the plugin was upgraded
     * meanwhile) gets no question, since the replay cannot check one is due, and that resuming
     * the attempt starts the phase again on today's engine, where questions are drawn again —
     * keeping the level, phase, questions answered and the restart count.
     *
     * @return void
     */
    public function test_a_phase_from_an_earlier_engine_is_restarted_so_questions_come_back(): void {
        global $DB;

        $instance = $this->make_instance(['gamemode' => 'campaign', 'maxlevels' => 1]);
        $this->make_question((int) $instance->id);
        $this->setUser($this->student);
        $token = $this->make_attempt_token((int) $instance->id);
        $attemptid = (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token]);
        $DB->update_record('playerpuzzle_attempts', (object) [
            'id' => $attemptid,
            'engineversion' => (int) get_config('mod_playerpuzzle', 'version') - 1,
            'currentphase' => 2,
            'questions_total' => 8,
            'coins_earned' => 30,
            'phaserestarts' => 1,
        ]);

        $before = $this->call_draw_question([
            'cmid' => $instance->cmid, 'token' => $token, 'eventoffset' => 0, 'movelog' => [self::DUE_MOVE],
        ]);
        $this->assertFalse($before['error']);
        $this->assertFalse($before['data']['available'], 'the old phase cannot be replayed, so no question is due');

        $resumed = security::resume_or_create_attempt_token((int) $instance->id, (int) $this->student->id, 'normal', 1);
        $row = $DB->get_record('playerpuzzle_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $this->assertSame((int) get_config('mod_playerpuzzle', 'version'), (int) $row->engineversion);
        $this->assertSame(0, (int) $row->moveseq);
        $this->assertNull($row->movelog);
        $this->assertSame(0, (int) $row->coins_earned);
        $this->assertSame('goblin', $row->frozenopponent);
        $this->assertSame(2, (int) $row->currentphase);
        $this->assertSame(8, (int) $row->questions_total);
        $this->assertSame(1, (int) $row->phaserestarts, 'not counted: the student did nothing to cause it');
        $this->assertSame(0, $resumed->moveseq);
        $this->assertSame((int) $row->rngseed, $resumed->rngseed);

        // The same opening match on the restarted phase now opens a question that is drawn.
        $DB->set_field('playerpuzzle_attempts', 'rngseed', 56, ['id' => $attemptid]);
        $DB->set_field('playerpuzzle_attempts', 'frozenbasebosshp', 100000, ['id' => $attemptid]);
        $after = $this->call_draw_question([
            'cmid' => $instance->cmid, 'token' => $resumed->token, 'eventoffset' => 0, 'movelog' => [self::DUE_MOVE],
        ]);
        $this->assertFalse($after['error']);
        $this->assertTrue($after['data']['available']);
    }
}
