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
 * Data generator for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Data generator class for the playerpuzzle activity module.
 */
class mod_playerpuzzle_generator extends testing_module_generator {
    /**
     * Creates a new instance of the playerpuzzle activity.
     *
     * @param array|\stdClass|null $record Field values for the instance.
     * @param array|null $options Module options (e.g. idnumber, section).
     * @return \stdClass Created course-module record.
     */
    public function create_instance($record = null, ?array $options = null): \stdClass {
        $record = (object) (array) $record;

        $defaults = [
            'maxlevels'        => 1,
            'basestudenthp'    => 100,
            'bossavatar'       => 'slime.png',
            'basebosshp'       => 1000,
            'bossdamage'       => 10,
            // Not a real foreign key in install.xml — tests that exercise the question
            // engine itself must override this with a genuine question_categories id.
            'questioncategory' => 0,
            'timelimit'        => 0,
            'maxattempts'      => 0,
            'hud_coin_item'    => 0,
            'hud_sword_item'   => 0,
            'hud_shield_item'  => 0,
        ];

        foreach ($defaults as $field => $value) {
            if (!isset($record->$field)) {
                $record->$field = $value;
            }
        }

        return parent::create_instance($record, $options);
    }
}
