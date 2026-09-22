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
 * Event fired when a server-side replay's own derived totals disagree with what the client
 * reported for the same phase.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\event;

/**
 * Fired by save_progress::execute()/advance_phase::execute() whenever
 * \mod_playerpuzzle\local\engine\replay::derive() reaches a conclusive result that differs
 * from the client's own claimed damage/coin totals. The replay's own value is always what
 * gets credited regardless — this event exists purely to make a systematic parity problem
 * (a balancing change that quietly desynced the JS and PHP engines, or a genuine cheating
 * attempt that the replay itself already neutralised) observable, never to flag or punish
 * the student the divergence happened for.
 *
 * Expected data:
 *   objectid  — id of the playerpuzzle_attempts record the divergence was found on
 *   context   — module context
 *   other     — [
 *                 'claimeddamage'     => int,
 *                 'deriveddamage'     => int,
 *                 'claimedplayergold' => int,
 *                 'derivedplayergold' => int,
 *                 'claimedbossgold'   => int,
 *                 'derivedbossgold'   => int,
 *               ]
 */
class replay_diverged extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'playerpuzzle_attempts';
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('event_replay_diverged', 'mod_playerpuzzle');
    }

    #[\Override]
    public function get_description(): string {
        return "The server-side replay for the playerpuzzle_attempts record with id " .
            "'{$this->objectid}' derived damage {$this->other['deriveddamage']} " .
            "(claimed {$this->other['claimeddamage']}), player gold {$this->other['derivedplayergold']} " .
            "(claimed {$this->other['claimedplayergold']}), boss gold {$this->other['derivedbossgold']} " .
            "(claimed {$this->other['claimedbossgold']}). The derived values were credited.";
    }

    #[\Override]
    public static function get_objectid_mapping(): array {
        return ['db' => 'playerpuzzle_attempts', 'restore' => 'playerpuzzle_attempts'];
    }
}
