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
 * Library of functions and constants for module playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Campaign game mode: levels/phases progression with server-scaled HP.
 */
define('PLAYERPUZZLE_GAMEMODE_CAMPAIGN', 'campaign');

/**
 * Single-match game mode: one self-contained, repeatable match.
 */
define('PLAYERPUZZLE_GAMEMODE_SINGLE', 'single');

/**
 * Grade method: highest score among all matches.
 */
define('PLAYERPUZZLE_GRADE_HIGHEST', 1);

/**
 * Grade method: average of the matches actually played.
 */
define('PLAYERPUZZLE_GRADE_AVERAGE', 2);

/**
 * Grade method: score of the first match only.
 */
define('PLAYERPUZZLE_GRADE_FIRST', 3);

/**
 * Grade method: score of the most recent match only.
 */
define('PLAYERPUZZLE_GRADE_LAST', 4);

/**
 * Grade method: sum of match scores divided by max_single_matches, so an unplayed match
 * counts as zero. Requires max_single_matches to not be unlimited.
 */
define('PLAYERPUZZLE_GRADE_AVERAGE_ALL', 5);

/**
 * Difficulty: halves the boss HP/damage and the coin reward.
 */
define('PLAYERPUZZLE_DIFFICULTY_EASY', 'easy');

/**
 * Difficulty: the configured boss HP/damage and coin reward, unchanged.
 */
define('PLAYERPUZZLE_DIFFICULTY_NORMAL', 'normal');

/**
 * Difficulty: doubles the boss HP/damage and triples the coin reward.
 */
define('PLAYERPUZZLE_DIFFICULTY_HARD', 'hard');

/**
 * Returns the available grading method options for Single-match mode, keyed by their
 * PLAYERPUZZLE_GRADE_* constant. Mirrors mod_playerwords/mod_playercross so the same
 * mental model applies across the Player ecosystem.
 *
 * @return array<int, string>
 */
function playerpuzzle_get_grademethod_options(): array {
    return [
        PLAYERPUZZLE_GRADE_HIGHEST     => get_string('grademethod_highest', 'mod_playerpuzzle'),
        PLAYERPUZZLE_GRADE_AVERAGE     => get_string('grademethod_average', 'mod_playerpuzzle'),
        PLAYERPUZZLE_GRADE_FIRST       => get_string('grademethod_first', 'mod_playerpuzzle'),
        PLAYERPUZZLE_GRADE_LAST        => get_string('grademethod_last', 'mod_playerpuzzle'),
        PLAYERPUZZLE_GRADE_AVERAGE_ALL => get_string('grademethod_average_all', 'mod_playerpuzzle'),
    ];
}

/**
 * Returns the difficulty options the student can pick in the Lobby, keyed by their
 * PLAYERPUZZLE_DIFFICULTY_* string constant, valued by the localised label.
 *
 * @return array
 */
function playerpuzzle_get_difficulty_options(): array {
    return [
        PLAYERPUZZLE_DIFFICULTY_EASY   => get_string('difficulty_easy', 'mod_playerpuzzle'),
        PLAYERPUZZLE_DIFFICULTY_NORMAL => get_string('difficulty_normal', 'mod_playerpuzzle'),
        PLAYERPUZZLE_DIFFICULTY_HARD   => get_string('difficulty_hard', 'mod_playerpuzzle'),
    ];
}

/**
 * Indicates API features that the playerpuzzle supports.
 *
 * @param string $feature The feature to check.
 * @return bool|null True if supported, null if unknown.
 */
function playerpuzzle_supports(string $feature): bool|null {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        // Not yet implemented: no backup/moodle2/ steplib, no custom_completion class.
        // Flip these on only alongside their real implementation.
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_HAS_RULES:
            return false;
        default:
            return null;
    }
}

/**
 * Creates or updates the grade item for a playerpuzzle instance.
 *
 * @param stdClass $playerpuzzle Activity instance (must have id, course, name, grade,
 *  gradepass).
 * @param mixed $grades Grade object(s), null to update the item only, or the literal
 *  string 'reset' to reset grades.
 * @return int GRADE_UPDATE_OK or one of grade_update()'s own error constants.
 */
function playerpuzzle_grade_item_update(stdClass $playerpuzzle, mixed $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $playerpuzzle->name,
        'idnumber' => $playerpuzzle->cmidnumber ?? '',
    ];

    if ((int) $playerpuzzle->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = (float) $playerpuzzle->grade;
        $params['grademin']  = 0.0;
    } else if ((int) $playerpuzzle->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -(int) $playerpuzzle->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    $isreset = $grades === 'reset';
    if ($isreset) {
        $params['reset'] = true;
        $grades = null;
    }

    $result = grade_update(
        'mod/playerpuzzle',
        $playerpuzzle->course,
        'mod',
        'playerpuzzle',
        $playerpuzzle->id,
        0,
        $grades,
        $params
    );

    // Grade_update() silently ignores a 'gradepass' key inside $itemdetails — its own
    // internal allow-list (lib/gradelib.php) never includes it. Applied directly on the
    // grade_item instead, mirroring mod_workshop and every other Player plugin's own
    // grade_item_update().
    if ($result === GRADE_UPDATE_OK && !$isreset && !empty($playerpuzzle->gradepass)) {
        $gradeitem = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'playerpuzzle',
            'iteminstance' => $playerpuzzle->id,
            'itemnumber'   => 0,
            'courseid'     => $playerpuzzle->course,
        ]);
        if ($gradeitem && (float) $gradeitem->gradepass !== (float) $playerpuzzle->gradepass) {
            $gradeitem->gradepass = (float) $playerpuzzle->gradepass;
            $gradeitem->update();
        }
    }

    return $result;
}

/**
 * Updates gradebook grades for one or all users of a playerpuzzle instance, from their own
 * attempts — see grade_calculator::calculate_user_grade() for the actual formulas.
 *
 * @param stdClass $playerpuzzle Activity instance.
 * @param int $userid User id, 0 to update every user with at least one attempt.
 * @return void
 */
function playerpuzzle_update_grades(stdClass $playerpuzzle, int $userid = 0): void {
    global $DB;

    $sql = "SELECT a.id, a.userid, a.currentlevel, a.currentphase, a.status,
                   a.questions_correct, a.questions_total, a.timefinished
              FROM {playerpuzzle_attempts} a
             WHERE a.playerpuzzleid = :instanceid";
    $params = ['instanceid' => $playerpuzzle->id];

    if ($userid > 0) {
        $sql .= ' AND a.userid = :userid';
        $params['userid'] = $userid;
    }

    $attempts = $DB->get_records_sql($sql, $params);

    if (empty($attempts)) {
        // A specific $userid with no attempts left (their last one was just deleted) must
        // have their stale grade actually cleared — passing no $grades here would only
        // touch the grade_item's own settings, leaving the old value stuck in the
        // gradebook. A global recompute ($userid == 0, e.g. after a grading-setting
        // change) has no single user to clear, so it keeps the item-only update.
        if ($userid > 0) {
            $grade = new stdClass();
            $grade->userid = $userid;
            $grade->rawgrade = null;
            playerpuzzle_grade_item_update($playerpuzzle, [$userid => $grade]);
        } else {
            playerpuzzle_grade_item_update($playerpuzzle);
        }
        return;
    }

    $userattempts = [];
    foreach ($attempts as $attempt) {
        $userattempts[$attempt->userid][] = $attempt;
    }

    $grades = [];
    foreach ($userattempts as $uid => $userattemptlist) {
        $rawgrade = \mod_playerpuzzle\local\grade_calculator::calculate_user_grade($playerpuzzle, $userattemptlist);
        if ($rawgrade === null) {
            // Single Match mode with no finished match yet for this user — nothing to grade.
            continue;
        }
        $grade = new stdClass();
        $grade->userid = $uid;
        $grade->rawgrade = $rawgrade;
        $grades[$uid] = $grade;
    }

    if (empty($grades)) {
        playerpuzzle_grade_item_update($playerpuzzle);
        return;
    }

    playerpuzzle_grade_item_update($playerpuzzle, $grades);
}

/**
 * Saves a new instance of the playerpuzzle into the database.
 *
 * @param stdClass $playerpuzzle Submitted data from the form.
 * @param ?moodleform $mform The form instance.
 * @return int The new instance id.
 */
function playerpuzzle_add_instance(stdClass $playerpuzzle, ?moodleform $mform = null): int {
    global $DB;

    $playerpuzzle->timecreated = time();
    $playerpuzzle->timemodified = $playerpuzzle->timecreated;

    $playerpuzzle->id = $DB->insert_record('playerpuzzle', $playerpuzzle);
    playerpuzzle_grade_item_update($playerpuzzle);

    return $playerpuzzle->id;
}

/**
 * Updates an instance of the playerpuzzle in the database.
 *
 * @param stdClass $playerpuzzle Submitted data from the form.
 * @param ?moodleform $mform The form instance.
 * @return bool True if successful.
 */
function playerpuzzle_update_instance(stdClass $playerpuzzle, ?moodleform $mform = null): bool {
    global $DB;

    $playerpuzzle->timemodified = time();
    $playerpuzzle->id = $playerpuzzle->instance;

    $result = $DB->update_record('playerpuzzle', $playerpuzzle);
    playerpuzzle_grade_item_update($playerpuzzle);

    return $result;
}

/**
 * Deletes an instance of the playerpuzzle from the database.
 *
 * @param int $id ID of the module instance.
 * @return bool True if successful.
 */
function playerpuzzle_delete_instance(int $id): bool {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $id]);
    if (!$playerpuzzle) {
        return false;
    }

    grade_update('mod/playerpuzzle', $playerpuzzle->course, 'mod', 'playerpuzzle', $id, 0, null, ['deleted' => 1]);

    $DB->delete_records_select(
        'playerpuzzle_attempt_questions',
        'attemptid IN (SELECT id FROM {playerpuzzle_attempts} WHERE playerpuzzleid = :ppid)',
        ['ppid' => $playerpuzzle->id]
    );
    $DB->delete_records_select(
        'playerpuzzle_attempt_consumables',
        'attemptid IN (SELECT id FROM {playerpuzzle_attempts} WHERE playerpuzzleid = :ppid)',
        ['ppid' => $playerpuzzle->id]
    );
    $DB->delete_records('playerpuzzle_attempts', ['playerpuzzleid' => $playerpuzzle->id]);
    $DB->delete_records('playerpuzzle', ['id' => $playerpuzzle->id]);

    return true;
}
