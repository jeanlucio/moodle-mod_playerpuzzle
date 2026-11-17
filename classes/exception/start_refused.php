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
 * A new attempt that the activity's rules do not allow to start.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\exception;

use moodle_exception;
use moodle_url;

/**
 * Thrown by the gates that decide whether a new attempt may begin (attempt limits, the cooldown, the minimum of
 * questions and the retry cost). It is an expected outcome of ordinary play, not a fault: play.php catches it and
 * sends the student back to the Lobby, which says why in a dialog (see game_page_service::refusal_text()) instead of
 * an error page.
 */
class start_refused extends moodle_exception {
    /**
     * Creates the exception.
     *
     * @param string $errorcode A key of the plugin's lang strings, one of game_page_service::REFUSALS.
     * @param moodle_url $link Where the student goes back to.
     * @param mixed $a Values for the lang string.
     */
    public function __construct(string $errorcode, moodle_url $link, mixed $a = null) {
        parent::__construct($errorcode, 'mod_playerpuzzle', $link, $a);
    }
}
