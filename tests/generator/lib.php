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
            'maxlevels'           => 1,
            'basestudenthp'       => 100,
            'bossavatar'          => 'slime.png',
            'basebosshp'          => 1000,
            'bossdamage'          => 10,
            'coingain'            => 10,
            // Last Moodle question bank category imported from via managequestions.php;
            // 0 = none imported yet.
            'questioncategory'    => 0,
            'timelimit'           => 0,
            'maxattempts'         => 0,
            'hud_coin_item'       => 0,
            'hud_retry_cost_item' => 0,
            'hud_retry_cost_qty'  => 1,
            'hud_win_grant_item'  => 0,
            'hud_win_grant_qty'   => 1,
            'gamemode'            => 'campaign',
            'max_single_matches'  => 0,
            'grademethod'         => 1,
            'minquestions'        => 3,
            'considererrors'      => 0,
            'show_ranking'        => 1,
            'grade'               => 100,
            'gradepass'           => 0,
        ];

        foreach ($defaults as $field => $value) {
            if (!isset($record->$field)) {
                $record->$field = $value;
            }
        }

        return parent::create_instance($record, $options);
    }

    /**
     * Credits a user's loadout stock (or PuzzleCoin balance, via consumabletype = 'coin')
     * for a playerpuzzle instance, without going through the buy_stock/save_progress web
     * services. Backs the "mod_playerpuzzle > user stocks" Behat generator step.
     *
     * @param array $record Fields: playerpuzzleid, userid, consumabletype, quantity.
     * @return void
     */
    public function create_user_stock(array $record): void {
        \mod_playerpuzzle\local\user_stock::credit(
            (int) $record['userid'],
            (int) $record['playerpuzzleid'],
            (string) $record['consumabletype'],
            (int) $record['quantity']
        );
    }

    /**
     * Creates an in-progress attempt the same way a real Play does, then pins what makes it
     * reproducible: the PRNG seed of its current phase (so the board, and every piece falling
     * afterwards, is known in advance) and, optionally, the engine version it was started
     * under, its level/phase, or a final status. Backs the "mod_playerpuzzle > attempts" Behat
     * generator step.
     *
     * @param array $record Fields: playerpuzzleid, userid, rngseed; optional engineversion,
     *  currentphase, status (anything but inprogress also stamps timefinished).
     * @return void
     */
    public function create_attempt(array $record): void {
        global $DB;

        $token = \mod_playerpuzzle\local\engine\security::generate_attempt_token(
            (int) $record['playerpuzzleid'],
            (int) $record['userid']
        );
        $update = [
            'id' => (int) $DB->get_field('playerpuzzle_attempts', 'id', ['token' => $token], MUST_EXIST),
            'rngseed' => (int) $record['rngseed'],
        ];
        foreach (['engineversion', 'currentphase'] as $field) {
            if (isset($record[$field]) && $record[$field] !== '') {
                $update[$field] = (int) $record[$field];
            }
        }
        if (!empty($record['status']) && $record['status'] !== 'inprogress') {
            $update['status'] = (string) $record['status'];
            $update['timefinished'] = time();
        }
        $DB->update_record('playerpuzzle_attempts', (object) $update);
    }
}
