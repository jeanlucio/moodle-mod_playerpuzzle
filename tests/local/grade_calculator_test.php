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
 * Unit tests for the grade calculator.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for grade_calculator.
 *
 * @covers \mod_playerpuzzle\local\grade_calculator
 */
final class grade_calculator_test extends \basic_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');
    }

    /**
     * Builds an instance object with sensible Campaign-mode defaults, merged with
     * per-test overrides.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    private function make_instance(array $overrides = []): \stdClass {
        return (object) array_merge([
            'gamemode'           => PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
            'grademethod'        => PLAYERPUZZLE_GRADE_HIGHEST,
            'grade'              => 100,
            'minquestions'       => 3,
            'considererrors'     => 0,
            'maxlevels'          => 1,
            'max_single_matches' => 0,
        ], $overrides);
    }

    /**
     * Builds an attempt object with sensible defaults, merged with per-test overrides.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    private function make_attempt(array $overrides = []): \stdClass {
        return (object) array_merge([
            'currentlevel'      => 1,
            'currentphase'      => 1,
            'status'            => 'inprogress',
            'questions_correct' => 0,
            'questions_total'   => 0,
            'timefinished'      => 0,
        ], $overrides);
    }

    /**
     * Tests that no attempts at all yields no grade, regardless of game mode.
     *
     * @return void
     */
    public function test_calculate_user_grade_null_when_no_attempts_at_all(): void {
        $this->assertNull(grade_calculator::calculate_user_grade($this->make_instance(), []));
    }

    /**
     * Tests that a fully completed Campaign (won on the very last configured phase)
     * grades at 100% of the configured Nota Máxima.
     *
     * @return void
     */
    public function test_campaign_grade_from_a_fully_won_campaign(): void {
        $instance = $this->make_instance(['maxlevels' => 2, 'grade' => 100]);
        $attempt = $this->make_attempt(['currentlevel' => 2, 'currentphase' => 10, 'status' => 'won']);

        $this->assertEqualsWithDelta(100.0, grade_calculator::calculate_user_grade($instance, [$attempt]), 0.001);
    }

    /**
     * Tests that an attempt still fighting (or lost on) a phase never credits that phase
     * itself — only the phases strictly before it count as won.
     *
     * @return void
     */
    public function test_campaign_grade_from_partial_progress(): void {
        $instance = $this->make_instance(['maxlevels' => 1, 'grade' => 100]);
        $attempt = $this->make_attempt(['currentlevel' => 1, 'currentphase' => 5, 'status' => 'lost']);

        // Phases 1-4 won, phase 5 lost: 4/10 = 40%.
        $this->assertEqualsWithDelta(40.0, grade_calculator::calculate_user_grade($instance, [$attempt]), 0.001);
    }

    /**
     * Tests that losing on the very first phase of the campaign grades at 0, not a
     * negative ordinal.
     *
     * @return void
     */
    public function test_campaign_grade_from_losing_the_first_phase_is_zero(): void {
        $instance = $this->make_instance(['maxlevels' => 1, 'grade' => 100]);
        $attempt = $this->make_attempt(['currentlevel' => 1, 'currentphase' => 1, 'status' => 'lost']);

        $this->assertSame(0.0, grade_calculator::calculate_user_grade($instance, [$attempt]));
    }

    /**
     * Tests that crossing a level boundary (currentphase resets to 1 on the new level)
     * still credits the whole previous level as won.
     *
     * @return void
     */
    public function test_campaign_grade_credits_a_full_level_when_crossing_into_the_next(): void {
        $instance = $this->make_instance(['maxlevels' => 2, 'grade' => 100]);
        $attempt = $this->make_attempt(['currentlevel' => 2, 'currentphase' => 1, 'status' => 'inprogress']);

        // Level 1's 10 phases all won to reach Level 2 Phase 1; that phase itself not yet
        // won: 10/20 = 50%.
        $this->assertEqualsWithDelta(50.0, grade_calculator::calculate_user_grade($instance, [$attempt]), 0.001);
    }

    /**
     * Tests that the grade reflects the furthest progress across every attempt, not just
     * the most recent one — a "Tentar Novamente" after a loss opens a new attempt row.
     *
     * @return void
     */
    public function test_campaign_grade_takes_the_max_across_multiple_attempts(): void {
        $instance = $this->make_instance(['maxlevels' => 1, 'grade' => 100]);
        $furthest = $this->make_attempt(['currentlevel' => 1, 'currentphase' => 8, 'status' => 'lost']);
        $earlier = $this->make_attempt(['currentlevel' => 1, 'currentphase' => 3, 'status' => 'lost']);

        $this->assertEqualsWithDelta(
            70.0,
            grade_calculator::calculate_user_grade($instance, [$earlier, $furthest]),
            0.001
        );
    }

    /**
     * Tests that the grade scales by the configured Nota Máxima, never a hardcoded 100.
     *
     * @return void
     */
    public function test_campaign_grade_scales_by_instance_grade(): void {
        $instance = $this->make_instance(['maxlevels' => 1, 'grade' => 10]);
        $attempt = $this->make_attempt(['currentlevel' => 1, 'currentphase' => 10, 'status' => 'won']);

        $this->assertEqualsWithDelta(10.0, grade_calculator::calculate_user_grade($instance, [$attempt]), 0.001);
    }

    /**
     * Tests that a "Sem Nota" instance (grade = 0) still computes without error, at 0 —
     * grade is a multiplier here, never a divisor, so there is no divide-by-zero risk.
     *
     * @return void
     */
    public function test_campaign_grade_with_no_grade_configured_is_zero(): void {
        $instance = $this->make_instance(['maxlevels' => 1, 'grade' => 0]);
        $attempt = $this->make_attempt(['currentlevel' => 1, 'currentphase' => 10, 'status' => 'won']);

        $this->assertSame(0.0, grade_calculator::calculate_user_grade($instance, [$attempt]));
    }

    /**
     * Tests the Considerar Erros blend: average of progress% and aggregated question
     * accuracy%, scaled by the configured grade.
     *
     * @return void
     */
    public function test_campaign_grade_with_considererrors_blends_progress_and_accuracy(): void {
        $instance = $this->make_instance([
            'maxlevels'      => 1,
            'grade'          => 100,
            'considererrors' => 1,
            'minquestions'   => 3,
        ]);
        $attempt = $this->make_attempt([
            'currentlevel'      => 1,
            'currentphase'      => 6,
            'status'            => 'lost',
            'questions_correct' => 3,
            'questions_total'   => 5,
        ]);

        // Progress: phases 1-5 won = 50%. Accuracy: 3/5 = 60%. Average = 55%.
        $this->assertEqualsWithDelta(55.0, grade_calculator::calculate_user_grade($instance, [$attempt]), 0.001);
    }

    /**
     * Tests that Considerar Erros is ignored when minquestions is below 1, even if the
     * flag is somehow set — mirrors the field's own enablement rule (mod_form only ever
     * allows turning it on when minquestions >= 1), re-checked here defensively.
     *
     * @return void
     */
    public function test_campaign_grade_ignores_considererrors_when_minquestions_is_zero(): void {
        $instance = $this->make_instance([
            'maxlevels'      => 1,
            'grade'          => 100,
            'considererrors' => 1,
            'minquestions'   => 0,
        ]);
        $attempt = $this->make_attempt([
            'currentlevel'      => 1,
            'currentphase'      => 6,
            'status'            => 'lost',
            'questions_correct' => 0,
            'questions_total'   => 5,
        ]);

        // If considererrors were honoured here, the 0% accuracy would drag this well
        // below the 50% pure-progress value.
        $this->assertEqualsWithDelta(50.0, grade_calculator::calculate_user_grade($instance, [$attempt]), 0.001);
    }

    /**
     * Tests that zero questions answered in total is treated as full accuracy rather
     * than dividing by zero — should not happen if minquestions is configured
     * correctly, but must degrade safely if it somehow does.
     *
     * @return void
     */
    public function test_campaign_grade_zero_questions_total_treated_as_full_accuracy(): void {
        $instance = $this->make_instance([
            'maxlevels'      => 1,
            'grade'          => 100,
            'considererrors' => 1,
            'minquestions'   => 3,
        ]);
        $attempt = $this->make_attempt(['currentlevel' => 1, 'currentphase' => 6, 'status' => 'lost']);

        // Progress 50%, accuracy treated as 100%: average = 75%.
        $this->assertEqualsWithDelta(75.0, grade_calculator::calculate_user_grade($instance, [$attempt]), 0.001);
    }

    /**
     * Tests PLAYERPUZZLE_GRADE_HIGHEST picks the best-scoring finished match.
     *
     * @return void
     */
    public function test_single_match_grade_highest(): void {
        $instance = $this->make_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod' => PLAYERPUZZLE_GRADE_HIGHEST,
            'grade'       => 100,
        ]);
        $attempts = [
            $this->make_attempt(['status' => 'won', 'timefinished' => 1]),
            $this->make_attempt(['status' => 'lost', 'timefinished' => 2]),
        ];

        $this->assertSame(100.0, grade_calculator::calculate_user_grade($instance, $attempts));
    }

    /**
     * Tests PLAYERPUZZLE_GRADE_AVERAGE averages only the matches actually played.
     *
     * @return void
     */
    public function test_single_match_grade_average(): void {
        $instance = $this->make_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod' => PLAYERPUZZLE_GRADE_AVERAGE,
            'grade'       => 100,
        ]);
        $attempts = [
            $this->make_attempt(['status' => 'won', 'timefinished' => 1]),
            $this->make_attempt(['status' => 'lost', 'timefinished' => 2]),
        ];

        $this->assertSame(50.0, grade_calculator::calculate_user_grade($instance, $attempts));
    }

    /**
     * Tests PLAYERPUZZLE_GRADE_FIRST always uses the earliest finished match by
     * timefinished, regardless of array order.
     *
     * @return void
     */
    public function test_single_match_grade_first(): void {
        $instance = $this->make_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod' => PLAYERPUZZLE_GRADE_FIRST,
            'grade'       => 100,
        ]);
        $attempts = [
            $this->make_attempt(['status' => 'won', 'timefinished' => 20]),
            $this->make_attempt(['status' => 'lost', 'timefinished' => 10]),
        ];

        $this->assertSame(0.0, grade_calculator::calculate_user_grade($instance, $attempts));
    }

    /**
     * Tests PLAYERPUZZLE_GRADE_LAST always uses the most recently finished match.
     *
     * @return void
     */
    public function test_single_match_grade_last(): void {
        $instance = $this->make_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod' => PLAYERPUZZLE_GRADE_LAST,
            'grade'       => 100,
        ]);
        $attempts = [
            $this->make_attempt(['status' => 'lost', 'timefinished' => 10]),
            $this->make_attempt(['status' => 'won', 'timefinished' => 20]),
        ];

        $this->assertSame(100.0, grade_calculator::calculate_user_grade($instance, $attempts));
    }

    /**
     * Tests PLAYERPUZZLE_GRADE_AVERAGE_ALL divides by the configured max_single_matches
     * — an unplayed match counts as zero, unlike a plain average.
     *
     * @return void
     */
    public function test_single_match_grade_average_all_with_configured_total(): void {
        $instance = $this->make_instance([
            'gamemode'           => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod'        => PLAYERPUZZLE_GRADE_AVERAGE_ALL,
            'grade'              => 100,
            'max_single_matches' => 4,
        ]);
        $attempts = [
            $this->make_attempt(['status' => 'won', 'timefinished' => 1]),
            $this->make_attempt(['status' => 'won', 'timefinished' => 2]),
        ];

        // 200 total points over 4 configured matches = 50, not 100 (which a plain
        // average over the 2 played would give).
        $this->assertSame(50.0, grade_calculator::calculate_user_grade($instance, $attempts));
    }

    /**
     * Tests that AVERAGE_ALL falls back to averaging over the matches actually played
     * when max_single_matches is Unlimited (0) — there is no fixed total to divide by.
     *
     * @return void
     */
    public function test_single_match_grade_average_all_falls_back_when_unlimited(): void {
        $instance = $this->make_instance([
            'gamemode'           => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod'        => PLAYERPUZZLE_GRADE_AVERAGE_ALL,
            'grade'              => 100,
            'max_single_matches' => 0,
        ]);
        $attempts = [
            $this->make_attempt(['status' => 'won', 'timefinished' => 1]),
            $this->make_attempt(['status' => 'lost', 'timefinished' => 2]),
        ];

        $this->assertSame(50.0, grade_calculator::calculate_user_grade($instance, $attempts));
    }

    /**
     * Tests that a lost match always scores 0, even with Considerar Erros on and some
     * questions answered correctly — the binary win/loss gate always applies first.
     *
     * @return void
     */
    public function test_single_match_grade_loss_scores_zero_even_with_considererrors(): void {
        $instance = $this->make_instance([
            'gamemode'       => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod'    => PLAYERPUZZLE_GRADE_HIGHEST,
            'grade'          => 100,
            'considererrors' => 1,
            'minquestions'   => 3,
        ]);
        $attempt = $this->make_attempt([
            'status' => 'lost', 'timefinished' => 1, 'questions_correct' => 5, 'questions_total' => 5,
        ]);

        $this->assertSame(0.0, grade_calculator::calculate_user_grade($instance, [$attempt]));
    }

    /**
     * Tests that Considerar Erros weights a win by that specific match's own accuracy.
     *
     * @return void
     */
    public function test_single_match_grade_win_weighted_by_accuracy_with_considererrors(): void {
        $instance = $this->make_instance([
            'gamemode'       => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod'    => PLAYERPUZZLE_GRADE_HIGHEST,
            'grade'          => 100,
            'considererrors' => 1,
            'minquestions'   => 3,
        ]);
        $attempt = $this->make_attempt([
            'status' => 'won', 'timefinished' => 1, 'questions_correct' => 3, 'questions_total' => 4,
        ]);

        $this->assertEqualsWithDelta(75.0, grade_calculator::calculate_user_grade($instance, [$attempt]), 0.001);
    }

    /**
     * Tests that an attempt still inprogress (open or abandoned, timefinished = 0) is
     * ignored entirely — it has no outcome yet to grade.
     *
     * @return void
     */
    public function test_single_match_grade_ignores_inprogress_attempts(): void {
        $instance = $this->make_instance([
            'gamemode'    => PLAYERPUZZLE_GAMEMODE_SINGLE,
            'grademethod' => PLAYERPUZZLE_GRADE_HIGHEST,
            'grade'       => 100,
        ]);
        $attempts = [
            $this->make_attempt(['status' => 'won', 'timefinished' => 1]),
            $this->make_attempt(['status' => 'inprogress', 'timefinished' => 0]),
        ];

        $this->assertSame(100.0, grade_calculator::calculate_user_grade($instance, $attempts));
    }

    /**
     * Tests that Single Match mode with only an inprogress attempt (nothing finished
     * yet) has no grade at all.
     *
     * @return void
     */
    public function test_single_match_grade_null_when_nothing_finished(): void {
        $instance = $this->make_instance(['gamemode' => PLAYERPUZZLE_GAMEMODE_SINGLE]);
        $attempt = $this->make_attempt(['status' => 'inprogress', 'timefinished' => 0]);

        $this->assertNull(grade_calculator::calculate_user_grade($instance, [$attempt]));
    }
}
