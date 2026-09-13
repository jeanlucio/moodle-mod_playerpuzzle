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
 * Event fired when a student starts a brand new PlayerPuzzle attempt.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\event;

/**
 * Fired by game_page_service::build_game_config() the moment a new playerpuzzle_attempts
 * row is created — never when an in-progress attempt is merely resumed (a page reload, or
 * continuing a Campaign run between phases).
 *
 * Expected data:
 *   objectid  — id of the playerpuzzle_attempts record just created
 *   context   — module context
 *   other     — [
 *                 'gamemode'   => string, // 'campaign' or 'single'
 *                 'difficulty' => string, // 'easy', 'normal' or 'hard'
 *               ]
 */
class game_started extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'playerpuzzle_attempts';
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('event_game_started', 'mod_playerpuzzle');
    }

    #[\Override]
    public function get_description(): string {
        return "The user with id '{$this->userid}' started a new '{$this->other['gamemode']}' attempt " .
            "in the playerpuzzle activity with course module id '{$this->contextinstanceid}'.";
    }

    #[\Override]
    public static function get_objectid_mapping(): array {
        return ['db' => 'playerpuzzle_attempts', 'restore' => 'playerpuzzle_attempts'];
    }
}
