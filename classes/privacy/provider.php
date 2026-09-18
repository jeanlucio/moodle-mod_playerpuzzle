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
 * Privacy provider implementation for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_playerpuzzle\local\sound_preferences;

/**
 * Privacy provider for mod_playerpuzzle.
 *
 * Personal data is stored only in playerpuzzle_attempts (userid, currentlevel, currentphase,
 * difficulty, bosshp_remaining, questions_correct, questions_total, score, status): one row
 * per attempt, tied to the specific activity instance the attempt was made in. PlayerPuzzle
 * keeps no currency data of its own — coins and consumable stock live in block_playerhud,
 * which declares its own personal data independently.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Returns metadata about personal data stored by this plugin.
     *
     * playerpuzzle_attempts carries four columns not listed below, each a deliberate
     * exclusion rather than an oversight: playerpuzzleid is a structural foreign key,
     * never itself exported (every export/delete call is already scoped by instance);
     * token is an opaque, unpredictable anti-replay value with no personal information
     * and is never exported; timemodified always mirrors either timecreated (set at
     * attempt creation) or timefinished (set the moment security::validate_and_
     * consume_token() moves the attempt to its final status), so it never carries
     * information beyond what timecreated/timefinished already declare; istutorial is
     * gameplay-flow metadata (whether this attempt got the Fase 9 onboarding boss-HP
     * reduction), the same category as currentlevel/currentphase, not personal data
     * about the user.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('playerpuzzle_attempts', [
            'userid'            => 'privacy:metadata:userid',
            'currentlevel'      => 'privacy:metadata:currentlevel',
            'currentphase'      => 'privacy:metadata:currentphase',
            'difficulty'        => 'privacy:metadata:difficulty',
            'bosshp_remaining'  => 'privacy:metadata:bosshp_remaining',
            'questions_correct' => 'privacy:metadata:questions_correct',
            'questions_total'   => 'privacy:metadata:questions_total',
            'coins_earned'      => 'privacy:metadata:coins_earned',
            'boss_coins_earned' => 'privacy:metadata:boss_coins_earned',
            'coins_spent'       => 'privacy:metadata:coins_spent',
            'combatstate'       => 'privacy:metadata:combatstate',
            'score'             => 'privacy:metadata:score',
            'status'            => 'privacy:metadata:status',
            'timecreated'       => 'privacy:metadata:timecreated',
            'timefinished'      => 'privacy:metadata:timefinished',
        ], 'privacy:metadata:playerpuzzle_attempts');

        // The playerpuzzle_attempt_questions table carries id (structural), attemptid
        // (structural FK, scoped by the parent attempt on every call) and questionid (a bank
        // id, not personal) — none exported. The rest is the student's own answer record.
        $collection->add_database_table('playerpuzzle_attempt_questions', [
            'attemptlevel'  => 'privacy:metadata:aq:attemptlevel',
            'attemptphase'  => 'privacy:metadata:aq:attemptphase',
            'questiontext'  => 'privacy:metadata:aq:questiontext',
            'chosenanswer'  => 'privacy:metadata:aq:chosenanswer',
            'correctanswer' => 'privacy:metadata:aq:correctanswer',
            'iscorrect'     => 'privacy:metadata:aq:iscorrect',
            'timecreated'   => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:playerpuzzle_attempt_questions');

        // Only id and attemptid (structural FK, scoped by the parent attempt) are excluded.
        $collection->add_database_table('playerpuzzle_attempt_consumables', [
            'consumabletype' => 'privacy:metadata:ac:consumabletype',
            'timesused'      => 'privacy:metadata:ac:timesused',
        ], 'privacy:metadata:playerpuzzle_attempt_consumables');

        // Only addedby identifies a person (the teacher/manager who typed the question, or
        // who triggered its AI generation) — questiontext/hint/qtype/source/
        // approved are course content authored for the activity, not personal data about
        // anyone, the same reasoning that already keeps the base playerpuzzle config table
        // out of this collection entirely. playerpuzzle_question_answers carries no personal
        // data at all (just the answer options' own text), so it is not declared here either.
        $collection->add_database_table('playerpuzzle_questions', [
            'addedby' => 'privacy:metadata:pq:addedby',
        ], 'privacy:metadata:playerpuzzle_questions');

        $collection->add_user_preference(
            sound_preferences::preference_name('music'),
            'privacy:metadata:preference:music'
        );
        $collection->add_user_preference(
            sound_preferences::preference_name('sfx'),
            'privacy:metadata:preference:sfx'
        );

        return $collection;
    }

    /**
     * Exports the Música/Efeitos preferences for the given user, when either has ever been
     * set — get_user_preferences() with no default (null) is the only way to distinguish
     * "never toggled" from "toggled back to the default enabled state", the same
     * distinction has_seen_intro()-style boolean preferences do not need to make.
     *
     * @param int $userid The user id.
     * @return void
     */
    #[\Override]
    public static function export_user_preferences(int $userid): void {
        foreach (sound_preferences::TYPES as $type) {
            $name = sound_preferences::preference_name($type);
            $value = get_user_preferences($name, null, $userid);
            if ($value === null) {
                continue;
            }

            writer::export_user_preference(
                'mod_playerpuzzle',
                $name,
                transform::yesno((bool) $value),
                get_string('privacy:metadata:preference:' . $type, 'mod_playerpuzzle')
            );
        }
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {playerpuzzle_attempts} pa
                  JOIN {playerpuzzle} pp ON pp.id = pa.playerpuzzleid
                  JOIN {modules} m ON m.name = :activityname
                  JOIN {course_modules} cm ON cm.instance = pp.id AND cm.module = m.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
                 WHERE pa.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'activityname' => 'playerpuzzle',
            'modlevel'     => CONTEXT_MODULE,
            'userid'       => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if (!is_a($context, \context_module::class)) {
            return;
        }

        $sql = "SELECT pa.userid
                  FROM {playerpuzzle_attempts} pa
                  JOIN {playerpuzzle} pp ON pp.id = pa.playerpuzzleid
                  JOIN {modules} m ON m.name = :activityname
                  JOIN {course_modules} cm ON cm.instance = pp.id AND cm.module = m.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
                 WHERE ctx.id = :contextid";
        $userlist->add_from_sql('userid', $sql, [
            'activityname' => 'playerpuzzle',
            'modlevel'     => CONTEXT_MODULE,
            'contextid'    => $context->id,
        ]);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        $contexts = array_reduce($contextlist->get_contexts(), function (array $carry, \context $context): array {
            if ($context->contextlevel == CONTEXT_MODULE) {
                $carry[$context->id] = $context;
            }
            return $carry;
        }, []);

        if (empty($contexts)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($contexts), SQL_PARAMS_NAMED, 'ctx');

        $sql = "SELECT pa.id, pa.currentlevel, pa.currentphase, pa.difficulty, pa.bosshp_remaining,
                       pa.questions_correct, pa.questions_total, pa.coins_earned,
                       pa.boss_coins_earned, pa.coins_spent, pa.combatstate, pa.score, pa.status,
                       pa.timecreated, pa.timefinished, ctx.id AS contextid
                  FROM {playerpuzzle_attempts} pa
                  JOIN {playerpuzzle} pp ON pp.id = pa.playerpuzzleid
                  JOIN {modules} m ON m.name = 'playerpuzzle'
                  JOIN {course_modules} cm ON cm.instance = pp.id AND cm.module = m.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id
                 WHERE ctx.id $insql
                   AND pa.userid = :userid";
        $records = $DB->get_recordset_sql($sql, array_merge($inparams, ['userid' => $userid]));

        $allattempts = [];
        foreach ($records as $record) {
            $allattempts[$record->contextid][] = (object) [
                'currentlevel'     => $record->currentlevel,
                'currentphase'     => $record->currentphase,
                'difficulty'       => $record->difficulty,
                'bosshpremaining'  => $record->bosshp_remaining,
                'questionscorrect' => $record->questions_correct,
                'questionstotal'   => $record->questions_total,
                'coinsearned'      => $record->coins_earned,
                'bosscoinsearned'  => $record->boss_coins_earned,
                'coinsspent'       => $record->coins_spent,
                'combatstate'      => $record->combatstate,
                'score'            => $record->score,
                'status'           => $record->status,
                'timecreated'      => transform::datetime($record->timecreated),
                'timefinished'     => $record->timefinished ? transform::datetime($record->timefinished) : null,
            ];
        }
        $records->close();

        foreach ($allattempts as $contextid => $attempts) {
            writer::with_context($contexts[$contextid])->export_data(
                [get_string('privacy:metadata:playerpuzzle_attempts', 'mod_playerpuzzle')],
                (object) ['attempts' => $attempts]
            );
        }

        $logsql = "SELECT aq.id, aq.attemptlevel, aq.attemptphase, aq.questiontext, aq.chosenanswer,
                          aq.correctanswer, aq.iscorrect, aq.timecreated, ctx.id AS contextid
                     FROM {playerpuzzle_attempt_questions} aq
                     JOIN {playerpuzzle_attempts} pa ON pa.id = aq.attemptid
                     JOIN {playerpuzzle} pp ON pp.id = pa.playerpuzzleid
                     JOIN {modules} m ON m.name = 'playerpuzzle'
                     JOIN {course_modules} cm ON cm.instance = pp.id AND cm.module = m.id
                     JOIN {context} ctx ON ctx.instanceid = cm.id
                    WHERE ctx.id $insql
                      AND pa.userid = :userid
                 ORDER BY aq.id ASC";
        $logrecords = $DB->get_recordset_sql($logsql, array_merge($inparams, ['userid' => $userid]));

        $alllog = [];
        foreach ($logrecords as $row) {
            $alllog[$row->contextid][] = (object) [
                'level'         => $row->attemptlevel,
                'phase'         => $row->attemptphase,
                'question'      => $row->questiontext,
                'chosenanswer'  => $row->chosenanswer,
                'correctanswer' => $row->correctanswer,
                'iscorrect'     => transform::yesno($row->iscorrect),
                'timecreated'   => transform::datetime($row->timecreated),
            ];
        }
        $logrecords->close();

        foreach ($alllog as $contextid => $questions) {
            writer::with_context($contexts[$contextid])->export_data(
                [get_string('privacy:metadata:playerpuzzle_attempt_questions', 'mod_playerpuzzle')],
                (object) ['questions' => $questions]
            );
        }

        $consumablesql = "SELECT ac.id, ac.consumabletype, ac.timesused, ctx.id AS contextid
                             FROM {playerpuzzle_attempt_consumables} ac
                             JOIN {playerpuzzle_attempts} pa ON pa.id = ac.attemptid
                             JOIN {playerpuzzle} pp ON pp.id = pa.playerpuzzleid
                             JOIN {modules} m ON m.name = 'playerpuzzle'
                             JOIN {course_modules} cm ON cm.instance = pp.id AND cm.module = m.id
                             JOIN {context} ctx ON ctx.instanceid = cm.id
                            WHERE ctx.id $insql
                              AND pa.userid = :userid
                         ORDER BY ac.id ASC";
        $consumablerecords = $DB->get_recordset_sql($consumablesql, array_merge($inparams, ['userid' => $userid]));

        $allconsumables = [];
        foreach ($consumablerecords as $row) {
            $allconsumables[$row->contextid][] = (object) [
                'consumabletype' => $row->consumabletype,
                'timesused'      => $row->timesused,
            ];
        }
        $consumablerecords->close();

        foreach ($allconsumables as $contextid => $consumables) {
            writer::with_context($contexts[$contextid])->export_data(
                [get_string('privacy:metadata:playerpuzzle_attempt_consumables', 'mod_playerpuzzle')],
                (object) ['consumables' => $consumables]
            );
        }
    }

    /**
     * Delete all user data for all users in the specified context.
     *
     * @param \context $context The context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('playerpuzzle', $context->instanceid);
        if (!$cm) {
            return;
        }

        self::delete_logged_questions('playerpuzzleid = :ppid', ['ppid' => (int) $cm->instance]);
        self::delete_consumable_uses('playerpuzzleid = :ppid', ['ppid' => (int) $cm->instance]);
        $DB->delete_records('playerpuzzle_attempts', ['playerpuzzleid' => $cm->instance]);
    }

    /**
     * Deletes playerpuzzle_attempt_questions rows for every attempt matching a WHERE clause
     * on playerpuzzle_attempts. Call before deleting the parent attempts.
     *
     * @param string $attemptswhere WHERE clause against {playerpuzzle_attempts}.
     * @param array $params Named parameters for the clause.
     * @return void
     */
    private static function delete_logged_questions(string $attemptswhere, array $params): void {
        global $DB;

        $DB->delete_records_select(
            'playerpuzzle_attempt_questions',
            "attemptid IN (SELECT id FROM {playerpuzzle_attempts} WHERE $attemptswhere)",
            $params
        );
    }

    /**
     * Deletes playerpuzzle_attempt_consumables rows for every attempt matching a WHERE clause
     * on playerpuzzle_attempts. Call before deleting the parent attempts.
     *
     * @param string $attemptswhere WHERE clause against {playerpuzzle_attempts}.
     * @param array $params Named parameters for the clause.
     * @return void
     */
    private static function delete_consumable_uses(string $attemptswhere, array $params): void {
        global $DB;

        $DB->delete_records_select(
            'playerpuzzle_attempt_consumables',
            "attemptid IN (SELECT id FROM {playerpuzzle_attempts} WHERE $attemptswhere)",
            $params
        );
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        $instanceids = [];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('playerpuzzle', $context->instanceid);
            if ($cm) {
                $instanceids[] = (int) $cm->instance;
            }
        }

        if (empty($instanceids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED, 'pp');
        $where = "playerpuzzleid $insql AND userid = :userid";
        $params = array_merge($inparams, ['userid' => $userid]);
        self::delete_logged_questions($where, $params);
        self::delete_consumable_uses($where, $params);
        $DB->delete_records_select('playerpuzzle_attempts', $where, $params);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        if (!is_a($context, \context_module::class)) {
            return;
        }

        $cm = get_coursemodule_from_id('playerpuzzle', $context->instanceid);
        if (!$cm) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $where = "playerpuzzleid = :playerpuzzleid AND userid $insql";
        $params = array_merge(['playerpuzzleid' => (int) $cm->instance], $inparams);
        self::delete_logged_questions($where, $params);
        self::delete_consumable_uses($where, $params);
        $DB->delete_records_select('playerpuzzle_attempts', $where, $params);
    }
}
