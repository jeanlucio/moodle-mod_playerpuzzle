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
 * Behat steps for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.
require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Waits sized for a real match. The game's clock advances a fixed step per rendered frame, so
 * under a software-rendered test browser a match plays out several times slower than on a
 * student's machine — well past the few seconds the core "I wait until ..." steps allow.
 */
class behat_mod_playerpuzzle extends behat_base {
    /** @var int Seconds a match may take to reach the next state under a slow test browser. */
    private const MATCH_TIMEOUT = 180;

    /**
     * Waits until the PlayerPuzzle board has loaded and it is the student's turn.
     *
     * @Given /^I wait until the PlayerPuzzle board is ready$/
     * @return void
     */
    public function i_wait_until_the_playerpuzzle_board_is_ready(): void {
        // The turn announcement starts with a fixed text before its move count.
        $marker = '@@count@@';
        $intro = get_string('turnstart_intro', 'mod_playerpuzzle', $marker);
        $prefix = trim(substr($intro, 0, (int) strpos($intro, $marker)));

        $this->spin(
            function (behat_mod_playerpuzzle $context) use ($prefix): bool {
                $live = $context->getSession()->getPage()->find('css', '#pp-aria-live');
                return $live !== null && str_starts_with(trim($live->getText()), $prefix);
            },
            $this,
            self::MATCH_TIMEOUT
        );
    }

    /**
     * Waits until the PlayerPuzzle match has ended and the server has answered its final save,
     * whatever the outcome.
     *
     * @Given /^I wait until the PlayerPuzzle match has ended$/
     * @return void
     */
    public function i_wait_until_the_playerpuzzle_match_has_ended(): void {
        $this->spin(
            function (behat_mod_playerpuzzle $context): bool {
                $page = $context->getSession()->getPage();
                return $page->find('css', '#pp-save-status.text-success, #pp-save-status.text-danger') !== null;
            },
            $this,
            self::MATCH_TIMEOUT
        );
    }
}
