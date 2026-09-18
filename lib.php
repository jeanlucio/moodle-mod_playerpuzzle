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
 * Calendar event type: the activity's optional due date.
 */
define('PLAYERPUZZLE_EVENT_TYPE_DUE', 'due');

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
 * Tells Moodle this plugin uses a branded icon (disables purpose recolour filter).
 *
 * @return bool True, since this plugin's icon should keep its own colours.
 */
function mod_playerpuzzle_is_branded(): bool {
    return true;
}

/**
 * Indicates API features that the playerpuzzle supports.
 *
 * @param string $feature The feature to check.
 * @return mixed True if supported, a purpose string for FEATURE_MOD_PURPOSE/
 *  FEATURE_MOD_OTHERPURPOSE, null if unknown.
 */
function playerpuzzle_supports(string $feature): mixed {
    // FEATURE_MOD_OTHERPURPOSE only exists from Moodle 5.1 onwards (MDL-85598); this plugin
    // also targets Moodle 4.5, where referencing the undefined constant as a switch case
    // label would still be a fatal error, guard or not — checked ahead of the switch instead.
    // Lets the activity chooser list this activity under both its primary purpose (game/
    // interactive content) and this secondary one (it produces real grades).
    if (defined('FEATURE_MOD_OTHERPURPOSE') && $feature === FEATURE_MOD_OTHERPURPOSE) {
        return MOD_PURPOSE_ASSESSMENT;
    }

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
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_INTERACTIVECONTENT;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        default:
            return null;
    }
}

/**
 * Populates the course module info object with custom completion rule data.
 *
 * Called by Moodle when building cm_info. Stores the required attempt/win counts in
 * customdata so activity_custom_completion::get_available_custom_rules() can determine
 * whether each rule is enabled for this instance, and so
 * \mod_playerpuzzle\completion\custom_completion::get_state() can evaluate them.
 *
 * @param stdClass $coursemodule The raw course_modules row (id, instance, …).
 * @return cached_cm_info|false A populated info object, or false on failure.
 */
function playerpuzzle_get_coursemodule_info(stdClass $coursemodule): cached_cm_info|false {
    global $DB;

    $fields = 'id, name, completionattempts, completionwins';
    $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $coursemodule->instance], $fields);
    if (!$playerpuzzle) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $playerpuzzle->name;

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionattempts'] = (int) $playerpuzzle->completionattempts;
        $info->customdata['customcompletionrules']['completionwins'] = (int) $playerpuzzle->completionwins;
    }

    return $info;
}

/**
 * Describes the active custom completion rules.
 *
 * @param stdClass|cm_info $cm The course module info.
 * @return array An array of active completion rule descriptions.
 */
function playerpuzzle_get_completion_active_rule_descriptions(stdClass|cm_info $cm): array {
    $descriptions = [];

    $rules = $cm->customdata['customcompletionrules'] ?? [];
    if (!empty($rules['completionattempts'])) {
        $descriptions[] = get_string('completionattempts_desc', 'mod_playerpuzzle', $rules['completionattempts']);
    }
    if (!empty($rules['completionwins'])) {
        $descriptions[] = get_string('completionwins_desc', 'mod_playerpuzzle', $rules['completionwins']);
    }

    return $descriptions;
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

    // Demo attempts (fixed HP, disposable, repeatable at will) are excluded: they must never
    // contribute to a real grade — see security.php/game_page_service.php's own isdemo
    // exclusions for the same rule applied elsewhere.
    $sql = "SELECT a.id, a.userid, a.currentlevel, a.currentphase, a.status,
                   a.questions_correct, a.questions_total, a.timefinished
              FROM {playerpuzzle_attempts} a
             WHERE a.playerpuzzleid = :instanceid AND a.isdemo = 0";
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
 * Creates, updates, or deletes the due-date calendar event for a playerpuzzle instance —
 * purely informational, never a play-time restriction, so a single event is enough (unlike
 * an open/close window, there is no "opens" counterpart to track).
 *
 * @param stdClass $playerpuzzle Activity instance (id, course, name, duedate; coursemodule
 *  looked up when not already present, mirroring mod_choice's own choice_set_events()).
 * @return void
 */
function playerpuzzle_set_events(stdClass $playerpuzzle): void {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/calendar/lib.php');

    $eventid = $DB->get_field('event', 'id', [
        'modulename' => 'playerpuzzle',
        'instance'   => $playerpuzzle->id,
        'eventtype'  => PLAYERPUZZLE_EVENT_TYPE_DUE,
    ]);

    if (empty($playerpuzzle->duedate)) {
        if ($eventid) {
            calendar_event::load($eventid)->delete();
        }
        return;
    }

    // Only looked up once there is actually an event to create/update — the coursemodule
    // row may not exist yet by this point for a caller that never sets it (a raw unit test
    // constructing its own stdClass, unlike the real add_moduleinfo() flow, which always
    // sets it before calling *_add_instance()).
    if (!isset($playerpuzzle->coursemodule)) {
        $cm = get_coursemodule_from_instance('playerpuzzle', $playerpuzzle->id, $playerpuzzle->course);
        $playerpuzzle->coursemodule = $cm->id;
    }

    $event = new stdClass();
    $event->name         = get_string('calendardue', 'mod_playerpuzzle', $playerpuzzle->name);
    $event->description  = format_module_intro('playerpuzzle', $playerpuzzle, $playerpuzzle->coursemodule, false);
    $event->format       = FORMAT_HTML;
    $event->type         = CALENDAR_EVENT_TYPE_ACTION;
    $event->eventtype    = PLAYERPUZZLE_EVENT_TYPE_DUE;
    $event->timestart    = $playerpuzzle->duedate;
    $event->timesort     = $playerpuzzle->duedate;
    $event->timeduration = 0;
    $event->visible      = instance_is_visible('playerpuzzle', $playerpuzzle);

    if ($eventid) {
        calendar_event::load($eventid)->update($event, false);
        return;
    }

    $event->courseid   = $playerpuzzle->course;
    $event->groupid    = 0;
    $event->userid     = 0;
    $event->modulename = 'playerpuzzle';
    $event->instance   = $playerpuzzle->id;
    calendar_event::create($event, false);
}

/**
 * Updates the calendar events for one playerpuzzle instance, several (by course), or every
 * one on the site. This is the hook core discovers by name
 * (course_module_calendar_event_update_process(), the daily refresh_mod_calendar_events_task)
 * to bulk-refresh every activity's own calendar events.
 *
 * @param int $courseid Course ID to refresh, or 0 for every course.
 * @param stdClass|int|null $instance Activity instance (or its id) to refresh alone.
 * @param stdClass|int|null $cm Course module (or its id), to avoid a lookup when already known.
 * @return bool True.
 */
function playerpuzzle_refresh_events($courseid = 0, $instance = null, $cm = null): bool {
    global $DB;

    if ($instance !== null) {
        if (!is_object($instance)) {
            $instance = $DB->get_record('playerpuzzle', ['id' => $instance], '*', MUST_EXIST);
        }
        if ($cm !== null) {
            $instance->coursemodule = is_object($cm) ? $cm->id : $cm;
        }
        playerpuzzle_set_events($instance);
        return true;
    }

    $instances = $courseid
        ? $DB->get_records('playerpuzzle', ['course' => $courseid])
        : $DB->get_records('playerpuzzle');
    foreach ($instances as $eachinstance) {
        playerpuzzle_set_events($eachinstance);
    }

    return true;
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
    // The gradepass element added by standard_grading_coursemodule_elements() submits null
    // (not an empty string) when left blank in the form, which the 'gradepass' column
    // (NOTNULL) rejects outright.
    $playerpuzzle->gradepass = isset($playerpuzzle->gradepass) ? (float) $playerpuzzle->gradepass : 0.0;

    $playerpuzzle->id = $DB->insert_record('playerpuzzle', $playerpuzzle);
    playerpuzzle_grade_item_update($playerpuzzle);
    playerpuzzle_set_events($playerpuzzle);

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
    // Same null-to-zero normalization as playerpuzzle_add_instance() — see its own comment.
    $playerpuzzle->gradepass = isset($playerpuzzle->gradepass) ? (float) $playerpuzzle->gradepass : 0.0;

    $result = $DB->update_record('playerpuzzle', $playerpuzzle);
    playerpuzzle_grade_item_update($playerpuzzle);
    playerpuzzle_set_events($playerpuzzle);

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
    $DB->delete_records_select(
        'playerpuzzle_question_answers',
        'questionid IN (SELECT id FROM {playerpuzzle_questions} WHERE playerpuzzleid = :ppid)',
        ['ppid' => $playerpuzzle->id]
    );
    $DB->delete_records('playerpuzzle_questions', ['playerpuzzleid' => $playerpuzzle->id]);
    $DB->delete_records('playerpuzzle', ['id' => $playerpuzzle->id]);
    $DB->delete_records('event', ['modulename' => 'playerpuzzle', 'instance' => $playerpuzzle->id]);

    return true;
}

/**
 * Adds a link to the teacher report in the activity administration navigation.
 *
 * @param settings_navigation $settings The settings navigation object.
 * @param navigation_node $puzzlenode The node to add the link to.
 * @return void
 */
function playerpuzzle_extend_settings_navigation(settings_navigation $settings, navigation_node $puzzlenode): void {
    if (has_capability('mod/playerpuzzle:viewreport', $settings->get_page()->cm->context)) {
        $puzzlenode->add(
            get_string('report_title', 'mod_playerpuzzle'),
            new moodle_url('/mod/playerpuzzle/report.php', ['id' => $settings->get_page()->cm->id])
        );
    }
}

/**
 * Serves a file embedded in a question's text or an answer's text — the two fileareas
 * question_form.php's editors write into (manual entries) and question_bank_sync.php
 * copies into (bank imports).
 *
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param context $context Module context.
 * @param string $filearea File area.
 * @param array $args Extra arguments; $args[0] is the item id (question id or answer id).
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional options.
 * @return bool False if the file was not found or is not servable.
 */
function playerpuzzle_pluginfile(
    stdClass $course,
    stdClass $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    global $DB;

    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    if (!has_capability('mod/playerpuzzle:view', $context)) {
        return false;
    }

    if ($filearea !== 'questiontext' && $filearea !== 'answertext') {
        return false;
    }

    $itemid = (int) array_shift($args);

    // The item id must belong to this instance — never trust a raw itemid on its own, the
    // same instance-isolation rule applied everywhere else a client-supplied id is used.
    if ($filearea === 'questiontext') {
        $owns = $DB->record_exists('playerpuzzle_questions', ['id' => $itemid, 'playerpuzzleid' => $cm->instance]);
    } else {
        $owns = $DB->record_exists_sql(
            "SELECT 1
               FROM {playerpuzzle_question_answers} a
               JOIN {playerpuzzle_questions} q ON q.id = a.questionid
              WHERE a.id = :itemid AND q.playerpuzzleid = :ppid",
            ['itemid' => $itemid, 'ppid' => $cm->instance]
        );
    }
    if (!$owns) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_playerpuzzle', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
    return true;
}
