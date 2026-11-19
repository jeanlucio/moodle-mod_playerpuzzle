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
 * The version of the combat rules.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * The version of the rules a phase is fought and replayed under, kept apart from the plugin's version.
 *
 * Every phase records the version it started under (playerpuzzle_attempts.engineversion), and the server only
 * replays a phase under the same one: a phase started under other rules restarts when the student resumes it. Tied to
 * the plugin's version, every update would restart every phase under way, even one that only fixed a text; tied to
 * this one, only an update that changes how a fight plays out does.
 *
 * Raise it, to the date of the change, whenever the outcome of a logged fight could change: the board and combat
 * rules on either side (amd/src/engine/, classes/local/engine/), the opponents' values and powers
 * (classes/local/opponents.php), how the move log is read (classes/local/move_log.php), the items' per-phase limits
 * (classes/local/attempt_consumables.php) or the replay itself. engine_version_test.php fails when those files change
 * and this does not; a new file the replay comes to depend on must join its list.
 */
final class engine_version {
    /** @var int The current rules, as the date they last changed (YYYYMMDDXX). */
    public const CURRENT = 2026112003;
}
