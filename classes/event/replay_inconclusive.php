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
 * Event fired when a finished match could not be verified by the server-side replay.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\event;

/**
 * Fired by save_progress::execute()/advance_phase::execute() whenever
 * \mod_playerpuzzle\local\engine\replay::derive() cannot reach a conclusive result for a
 * real (non-Demo) match, so the client's own claim is what gets credited, clamped by the
 * boss-HP cap and the coin ceiling. Purely observational: it measures how often honest play
 * still ends up unverified, before verification is ever made mandatory for a match to count.
 *
 * Expected data:
 *   objectid  — id of the playerpuzzle_attempts record that could not be verified
 *   context   — module context
 *   other     — [
 *                 'claimeddamage'     => int,
 *                 'claimedplayergold' => int,
 *                 'claimedbossgold'   => int,
 *               ]
 */
class replay_inconclusive extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'playerpuzzle_attempts';
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('event_replay_inconclusive', 'mod_playerpuzzle');
    }

    #[\Override]
    public function get_description(): string {
        return "The server-side replay could not verify the playerpuzzle_attempts record with id " .
            "'{$this->objectid}'. The claimed values (damage {$this->other['claimeddamage']}, " .
            "player gold {$this->other['claimedplayergold']}, boss gold {$this->other['claimedbossgold']}) " .
            "were credited, within the usual caps.";
    }

    #[\Override]
    public static function get_objectid_mapping(): array {
        return ['db' => 'playerpuzzle_attempts', 'restore' => 'playerpuzzle_attempts'];
    }
}
