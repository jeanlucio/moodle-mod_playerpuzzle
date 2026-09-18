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
 * External function to validate a player answer during combat.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_playerpuzzle\local\attempt_questions;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\question_fetcher;
use moodle_exception;

/**
 * Validates whether the answer submitted by the player is correct.
 */
class validate_answer extends external_api {
    /**
     * Returns the parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT, 'Course module ID'),
            'token'    => new external_value(PARAM_ALPHANUM, 'Anti-replay token of the in-progress attempt'),
            'answerid' => new external_value(PARAM_INT, 'Answer ID submitted by the player; ignored for the boss'),
            'forwhom'  => new external_value(
                PARAM_ALPHA,
                'Whose answer this is: "player" validates the submitted answer, "boss" draws the boss guess server-side',
                VALUE_DEFAULT,
                'player'
            ),
        ]);
    }

    /**
     * Validates a player's answer, or draws the boss's guess server-side with the
     * difficulty-weighted precision, and returns the outcome. The boss draw never happens
     * on the client, so the correct answer is never revealed to it (Blind JSON).
     *
     * Always operates on the attempt's own currentquestionid (set by draw_question.php) —
     * never a client-supplied question id. A client-chosen question id here would let a
     * caller invoke forwhom=boss for any approved question of the instance, at will, turning
     * the endpoint into a free oracle for the correct answer: on Hard difficulty the boss's
     * guess is correct with probability 1.0, so a single call would already reveal it. Tying
     * the question to server state is what prevents the client from choosing which question
     * to probe.
     *
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token of the in-progress attempt.
     * @param int $answerid Answer ID submitted (player only).
     * @param string $forwhom "player" or "boss".
     * @return array Result matrix.
     */
    public static function execute(
        int $cmid,
        string $token,
        int $answerid,
        string $forwhom = 'player'
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'token'    => $token,
            'answerid' => $answerid,
            'forwhom'  => $forwhom,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/playerpuzzle:view', $context);

        $cm = get_coursemodule_from_id('playerpuzzle', $params['cmid'], 0, false, MUST_EXIST);
        $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

        $attempt = $DB->get_record('playerpuzzle_attempts', [
            'token'          => $params['token'],
            'playerpuzzleid' => (int) $playerpuzzle->id,
            'userid'         => (int) $USER->id,
            'status'         => 'inprogress',
        ]);
        if (!$attempt) {
            throw new moodle_exception('invalidattempttoken', 'mod_playerpuzzle');
        }

        $questionid = (int) $attempt->currentquestionid;

        // Instance isolation: the question must belong to this instance and be approved,
        // never validated by isolated PK. Also covers the case of no question ever drawn
        // (currentquestionid = 0) or one that vanished (deleted/disapproved) since the draw.
        $valid = $questionid > 0 && $DB->record_exists('playerpuzzle_questions', [
            'id'             => $questionid,
            'playerpuzzleid' => (int) $playerpuzzle->id,
            'approved'       => 1,
        ]);
        if (!$valid) {
            self::consume_current_question($attempt);
            return ['correct' => false];
        }

        if ($params['forwhom'] === 'boss') {
            $result = self::draw_boss_guess($questionid, (string) $attempt->difficulty);
            self::consume_current_question($attempt);
            return $result;
        }

        $correct = question_fetcher::is_answer_correct($questionid, $params['answerid']);
        $correctanswerid = question_fetcher::get_correct_answer_id($questionid);

        // Server-side source of truth for how many questions this attempt has answered so
        // far (right or wrong): the boss-revive rule and the "Perguntas: X/N" HUD counter
        // both trust only this number, never a count the client keeps on its own.
        $attempt->questions_total = (int) $attempt->questions_total + 1;
        if ($correct) {
            $attempt->questions_correct = (int) $attempt->questions_correct + 1;
        }
        self::consume_current_question($attempt);

        // Log the student's answer for the post-game review — a text snapshot, so it still
        // reads correctly if the source question is later edited or removed.
        attempt_questions::record(
            (int) $attempt->id,
            $questionid,
            (int) $attempt->currentlevel,
            (int) $attempt->currentphase,
            question_fetcher::get_question_text($questionid, $context),
            question_fetcher::get_answer_text($params['answerid'], $questionid, $context),
            $correctanswerid !== null ? question_fetcher::get_answer_text($correctanswerid, $questionid, $context) : '',
            $correct
        );

        $result = ['correct' => $correct, 'questionstotal' => (int) $attempt->questions_total];
        if (!$correct && $correctanswerid !== null) {
            $result['correctanswerid'] = $correctanswerid;
        }

        return $result;
    }

    /**
     * Clears currentquestionid and persists the attempt — called on every path out of
     * execute() once a question has been resolved (answered, guessed, or found invalid), so
     * a repeated call without a fresh draw_question.php call always finds none open. This is
     * the actual anti-replay guarantee for the question-draw flow, mirroring
     * security::validate_and_consume_token()'s own consume-on-use pattern.
     *
     * @param \stdClass $attempt The attempt row, with questions_total/questions_correct
     *  already updated by the caller if applicable — persisted here in the same update.
     * @return void
     */
    private static function consume_current_question(\stdClass $attempt): void {
        global $DB;

        $attempt->currentquestionid = 0;
        $attempt->timemodified = time();
        $DB->update_record('playerpuzzle_attempts', $attempt);
    }

    /**
     * Draws the boss's answer for a question: with the difficulty/qtype-weighted probability
     * it lands on the correct answer, otherwise on a random wrong one. Returns which answer
     * it picked so the client can render it, but never which one was right.
     *
     * @param int $questionid Question ID.
     * @param string $difficulty The attempt's current difficulty.
     * @return array {correct: bool, pickedanswerid: int}
     */
    private static function draw_boss_guess(int $questionid, string $difficulty): array {
        $qtype = question_fetcher::get_question_type($questionid) ?? 'multichoice';
        $correctid = question_fetcher::get_correct_answer_id($questionid);
        $answerids = question_fetcher::get_answer_ids($questionid);

        $probability = combat::boss_guess_probability($difficulty, $qtype);
        $hitscorrect = $correctid !== null && (mt_rand() / mt_getrandmax()) < $probability;

        if ($hitscorrect) {
            $pickedid = $correctid;
        } else {
            $wrongids = array_values(array_filter($answerids, fn($id) => $id !== $correctid));
            if (!empty($wrongids)) {
                $pickedid = $wrongids[array_rand($wrongids)];
            } else {
                $pickedid = $correctid ?? (empty($answerids) ? 0 : (int) reset($answerids));
            }
        }

        return [
            'correct'        => $pickedid === $correctid,
            'pickedanswerid' => (int) $pickedid,
        ];
    }

    /**
     * Returns the return value definitions.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'correct'         => new external_value(PARAM_BOOL, 'Whether the answer is correct'),
            'correctanswerid' => new external_value(
                PARAM_INT,
                'Correct answer ID for post-answer feedback (player path only, on a wrong answer)',
                VALUE_OPTIONAL
            ),
            'pickedanswerid'  => new external_value(
                PARAM_INT,
                'The answer the boss picked (boss path only)',
                VALUE_OPTIONAL
            ),
            'questionstotal'  => new external_value(
                PARAM_INT,
                'Questions answered so far this attempt, server-counted (player path only)',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
