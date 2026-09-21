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
 * Behat generator for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for mod_playerpuzzle, adding the "user stocks" entity: pre-seeded
 * loadout stock or PuzzleCoin balance, used to reach a shop state (owned units, spendable
 * coins) without clicking through a real purchase in a Given step.
 */
class behat_mod_playerpuzzle_generator extends behat_generator_base {
    /**
     * Gets a list of the entities that Behat can create using the generator step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'user stocks' => [
                'singular' => 'user stock',
                'datagenerator' => 'user_stock',
                'required' => ['playerpuzzle', 'user', 'consumabletype', 'quantity'],
                'switchids' => ['playerpuzzle' => 'playerpuzzleid', 'user' => 'userid'],
            ],
        ];
    }

    /**
     * Gets the playerpuzzle instance id from its activity name or idnumber.
     *
     * @param string $idnumberorname The playerpuzzle activity idnumber or name.
     * @return int The instance id.
     */
    protected function get_playerpuzzle_id(string $idnumberorname): int {
        return $this->get_cm_by_activity_name('playerpuzzle', $idnumberorname)->instance;
    }
}
