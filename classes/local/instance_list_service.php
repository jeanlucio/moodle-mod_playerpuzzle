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
 * Builds the data for the course-level list of PlayerPuzzle activities.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use moodle_url;
use stdClass;

/**
 * Lists the activities of a course the current user can see.
 */
class instance_list_service {
    /**
     * Builds the template context for the course index page.
     *
     * get_all_instances_in_course() already leaves out the activities the current user may
     * not see, and a visible one in a hidden section is shown dimmed to those who may.
     *
     * @param stdClass $course Course record.
     * @return array Template context for mod_playerpuzzle/instance_list.
     */
    public static function build_page_data(stdClass $course): array {
        $instances = [];
        foreach (get_all_instances_in_course('playerpuzzle', $course) as $instance) {
            $instances[] = [
                'name' => text::plain_string($instance->name),
                'url' => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $instance->coursemodule]))->out(false),
                'section' => get_section_name($course, $instance->section),
                'dimmed' => empty($instance->visible),
            ];
        }

        return [
            'hasinstances' => !empty($instances),
            'instances' => $instances,
            'emptytext' => get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_playerpuzzle')),
        ];
    }
}
