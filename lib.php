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
 * Indicates API features that the playerpuzzle supports.
 *
 * @param string $feature
 * @return bool|null True if yes, null if unknown.
 */
function playerpuzzle_supports(string $feature): bool|null {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
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

    if (!$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('playerpuzzle_attempts', ['playerpuzzleid' => $playerpuzzle->id]);
    $DB->delete_records('playerpuzzle', ['id' => $playerpuzzle->id]);

    return true;
}
