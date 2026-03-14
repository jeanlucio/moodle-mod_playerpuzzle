<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library of functions and constants for module playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Indicates API features that the playerpuzzle supports.
 *
 * @param string $feature
 * @return mixed True if yes (some features may use other values)
 */
function playerpuzzle_supports($feature) {
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
 * @param mod_playerpuzzle_mod_form $mform The form instance.
 * @return int The new instance id.
 */
function playerpuzzle_add_instance($playerpuzzle, $mform = null) {
    global $DB;

    $playerpuzzle->timecreated = time();
    $playerpuzzle->timemodified = $playerpuzzle->timecreated;

    // Save to the database securely using the API.
    return $DB->insert_record('playerpuzzle', $playerpuzzle);
}

/**
 * Updates an instance of the playerpuzzle in the database.
 *
 * @param stdClass $playerpuzzle Submitted data from the form.
 * @param mod_playerpuzzle_mod_form $mform The form instance.
 * @return bool True if successful.
 */
function playerpuzzle_update_instance($playerpuzzle, $mform = null) {
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
function playerpuzzle_delete_instance($id) {
    global $DB;

    if (!$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $id])) {
        return false;
    }

    // Delete related attempts first to maintain relational integrity.
    $DB->delete_records('playerpuzzle_attempts', ['playerpuzzleid' => $playerpuzzle->id]);

    // Delete the instance.
    $DB->delete_records('playerpuzzle', ['id' => $playerpuzzle->id]);

    return true;
}
