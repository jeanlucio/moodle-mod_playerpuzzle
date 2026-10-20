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
 * Event fired when a user opens the PlayerPuzzle activity page (the Lobby).
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\event;

/**
 * Fired by view.php. The name, description and URL come from the core event it extends.
 *
 * Expected data:
 *   objectid  — id of the playerpuzzle record
 *   context   — module context
 */
class course_module_viewed extends \core\event\course_module_viewed {
    #[\Override]
    protected function init(): void {
        parent::init();
        $this->data['objecttable'] = 'playerpuzzle';
    }

    #[\Override]
    public static function get_objectid_mapping(): array {
        return ['db' => 'playerpuzzle', 'restore' => 'playerpuzzle'];
    }
}
