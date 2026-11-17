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
 * Whether a student shows their name in the class ranking.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * A student may choose to appear as an anonymous student in the class ranking, which names every student to their
 * classmates. They keep their place and their own row, only the name the others read changes. Kept in a user
 * preference across every activity, like last_hero: db/uninstall.php already clears every mod_playerpuzzle_
 * preference. Only the choice to be anonymous is stored, so a student who never chose has no row.
 */
class ranking_preferences {
    /** @var string The user preference, set to 1 for a student who does not show their name. */
    public const PREFERENCE = 'mod_playerpuzzle_rankinganonymous';

    /**
     * Whether a student hides their name in the ranking.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function is_anonymous(int $userid): bool {
        return (bool) get_user_preferences(self::PREFERENCE, 0, $userid);
    }

    /**
     * Which of some students hide their name, in one query.
     *
     * @param int[] $userids User ids.
     * @return array The ids of the ones who do, as keys.
     */
    public static function anonymous_among(array $userids): array {
        global $DB;

        if ($userids === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $params['name'] = self::PREFERENCE;
        $params['value'] = '1';
        $rows = $DB->get_records_select(
            'user_preferences',
            "name = :name AND value = :value AND userid $insql",
            $params,
            '',
            'userid'
        );

        return array_fill_keys(array_map('intval', array_keys($rows)), true);
    }

    /**
     * Records the choice. Showing the name is the default, so choosing it again removes the preference.
     *
     * @param int $userid User id.
     * @param bool $anonymous Whether to hide the name.
     * @return void
     */
    public static function set_anonymous(int $userid, bool $anonymous): void {
        if ($anonymous) {
            set_user_preference(self::PREFERENCE, 1, $userid);
        } else {
            unset_user_preference(self::PREFERENCE, $userid);
        }
    }
}
