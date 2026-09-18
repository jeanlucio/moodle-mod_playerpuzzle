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
 * Custom completion rules for the PlayerPuzzle activity.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\completion;

use core_completion\activity_custom_completion;

/**
 * Defines and evaluates the two custom completion rules: a minimum number of finished
 * attempts, and a minimum number of won attempts/matches — independent of each other, in
 * either game mode.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given custom completion rule.
     *
     * @param string $rule The rule name.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $required = (int) $this->cm->customdata['customcompletionrules'][$rule];

        if ($rule === 'completionattempts') {
            // Any finished attempt counts, regardless of outcome — mirrors
            // grade_calculator's own "finished" filter (timefinished > 0 / status <>
            // 'inprogress'). Demo attempts are excluded: an unlimited, repeatable, zero-stakes
            // practice fight must never satisfy a completion rule on its own.
            $count = $DB->count_records_select(
                'playerpuzzle_attempts',
                'playerpuzzleid = :pid AND userid = :uid AND status <> :inprogress AND isdemo = 0',
                ['pid' => $this->cm->instance, 'uid' => $this->userid, 'inprogress' => 'inprogress']
            );
        } else {
            // Status 'won' is only ever set on the attempt that finishes the whole
            // Campaign (the last phase of the last level) or a Single Match win — never a
            // mid-Campaign phase win, which leaves the attempt 'inprogress'. Demo attempts
            // excluded, same rationale as the completionattempts branch above.
            $count = $DB->count_records('playerpuzzle_attempts', [
                'playerpuzzleid' => $this->cm->instance,
                'userid'         => $this->userid,
                'status'         => 'won',
                'isdemo'         => 0,
            ]);
        }

        return $count >= $required ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Returns the list of custom completion rule names defined by this module.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionattempts', 'completionwins'];
    }

    /**
     * Returns human-readable descriptions for each custom completion rule.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $rules = $this->cm->customdata['customcompletionrules'] ?? [];
        return [
            'completionattempts' => get_string(
                'completionattempts_desc',
                'mod_playerpuzzle',
                $rules['completionattempts'] ?? 0
            ),
            'completionwins' => get_string(
                'completionwins_desc',
                'mod_playerpuzzle',
                $rules['completionwins'] ?? 0
            ),
        ];
    }

    /**
     * Returns the display order for all completion rules (core + custom).
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionusegrade',
            'completionattempts',
            'completionwins',
        ];
    }
}
