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
        // Not yet implemented: no backup/moodle2/ steplib, no custom_completion class, no
        // grade column/grade_item_update. Flip these on only alongside their real implementation.
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_COMPLETION_HAS_RULES:
            return false;
        default:
            return null;
    }
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

    return $DB->insert_record('playerpuzzle', $playerpuzzle);
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

    return $DB->update_record('playerpuzzle', $playerpuzzle);
}

/**
 * Deletes an instance of the playerpuzzle from the database.
 *
 * @param int $id ID of the module instance.
 * @return bool True if successful.
 */
function playerpuzzle_delete_instance(int $id): bool {
    global $DB;

    $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $id]);
    if (!$playerpuzzle) {
        return false;
    }

    $DB->delete_records_select(
        'playerpuzzle_attempt_questions',
        'attemptid IN (SELECT id FROM {playerpuzzle_attempts} WHERE playerpuzzleid = :ppid)',
        ['ppid' => $playerpuzzle->id]
    );
    $DB->delete_records('playerpuzzle_attempts', ['playerpuzzleid' => $playerpuzzle->id]);
    $DB->delete_records('playerpuzzle', ['id' => $playerpuzzle->id]);

    return true;
}
