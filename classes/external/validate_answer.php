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
            'cmid'       => new external_value(PARAM_INT, 'Course module ID'),
            'token'      => new external_value(PARAM_ALPHANUM, 'Anti-replay token of the in-progress attempt'),
            'questionid' => new external_value(PARAM_INT, 'Question ID'),
            'answerid'   => new external_value(PARAM_INT, 'Answer ID submitted by the player; ignored for the boss'),
            'forwhom'    => new external_value(
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
     * @param int $cmid Course module ID.
     * @param string $token Anti-replay token of the in-progress attempt.
     * @param int $questionid Question ID.
     * @param int $answerid Answer ID submitted (player only).
     * @param string $forwhom "player" or "boss".
     * @return array Result matrix.
     */
    public static function execute(
        int $cmid,
        string $token,
        int $questionid,
        int $answerid,
        string $forwhom = 'player'
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'token'      => $token,
            'questionid' => $questionid,
            'answerid'   => $answerid,
            'forwhom'    => $forwhom,
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

        // Instance isolation: the question must belong to this instance's own category,
        // never validated by isolated PK.
        $sql = "SELECT 1
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                 WHERE qv.questionid = :qid
                   AND qbe.questioncategoryid = :catid";
        $valid = $DB->record_exists_sql($sql, [
            'qid'   => $params['questionid'],
            'catid' => (int) $playerpuzzle->questioncategory,
        ]);
        if (!$valid) {
            return ['correct' => false];
        }

        if ($params['forwhom'] === 'boss') {
            return self::draw_boss_guess($params['questionid'], (string) $attempt->difficulty);
        }

        $correct = question_fetcher::is_answer_correct($params['questionid'], $params['answerid']);
        $correctanswerid = question_fetcher::get_correct_answer_id($params['questionid']);

        // Log the student's answer for the post-game review — a text snapshot, so it still
        // reads correctly if the source question is later edited or removed.
        attempt_questions::record(
            (int) $attempt->id,
            $params['questionid'],
            (int) $attempt->currentlevel,
            (int) $attempt->currentphase,
            question_fetcher::get_question_text($params['questionid'], $context),
            question_fetcher::get_answer_text($params['answerid'], $context),
            $correctanswerid !== null ? question_fetcher::get_answer_text($correctanswerid, $context) : '',
            $correct
        );

        $result = ['correct' => $correct];
        if (!$correct && $correctanswerid !== null) {
            $result['correctanswerid'] = $correctanswerid;
        }

        return $result;
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
        ]);
    }
}
