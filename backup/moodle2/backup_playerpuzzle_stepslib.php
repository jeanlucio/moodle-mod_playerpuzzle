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
 * Backup structure step for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the XML tree structure for a PlayerPuzzle backup.
 */
class backup_playerpuzzle_activity_structure_step extends backup_activity_structure_step {
    /**
     * Returns the root backup element with all nested children.
     *
     * @return backup_nested_element
     */
    protected function define_structure(): backup_nested_element {
        $userinfo = $this->get_setting_value('userinfo');

        // Root element — mirrors all columns in {playerpuzzle}.
        $playerpuzzle = new backup_nested_element('playerpuzzle', ['id'], [
            'name',
            'intro',
            'introformat',
            'maxlevels',
            'basestudenthp',
            'bossavatar',
            'basebosshp',
            'bossdamage',
            'coingain',
            'questioncategory',
            'timelimit',
            'maxattempts',
            'hud_coin_item',
            'hud_sword_item',
            'hud_shield_item',
            'hud_potion_item',
            'hud_retry_cost_item',
            'hud_retry_cost_qty',
            'hud_win_grant_item',
            'hud_win_grant_qty',
            'gamemode',
            'max_single_matches',
            'grademethod',
            'minquestions',
            'considererrors',
            'maxconsumables',
            'grade',
            'gradepass',
            'duedate',
            'completionattempts',
            'completionwins',
            'timecreated',
            'timemodified',
        ]);

        // Attempts, and their own child rows, are user data — only backed up when
        // userinfo is enabled.
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid',
            'token',
            'currentlevel',
            'currentphase',
            'difficulty',
            'bosshp_remaining',
            'questions_correct',
            'questions_total',
            'coins_earned',
            'boss_coins_earned',
            'coins_spent',
            'combatstate',
            'score',
            'status',
            'timecreated',
            'timefinished',
            'timemodified',
        ]);
        $questions = new backup_nested_element('questions');
        $question = new backup_nested_element('question', ['id'], [
            'questionid',
            'attemptlevel',
            'attemptphase',
            'questiontext',
            'chosenanswer',
            'correctanswer',
            'iscorrect',
            'timecreated',
        ]);
        $consumables = new backup_nested_element('consumables');
        $consumable = new backup_nested_element('consumable', ['id'], [
            'consumabletype',
            'timesused',
        ]);

        // Build the tree.
        if ($userinfo) {
            $playerpuzzle->add_child($attempts);
            $attempts->add_child($attempt);
            $attempt->add_child($questions);
            $questions->add_child($question);
            $attempt->add_child($consumables);
            $consumables->add_child($consumable);
        }

        // Connect elements to database tables.
        $playerpuzzle->set_source_table('playerpuzzle', ['id' => backup::VAR_ACTIVITYID]);

        if ($userinfo) {
            $attempt->set_source_table(
                'playerpuzzle_attempts',
                ['playerpuzzleid' => backup::VAR_ACTIVITYID]
            );
            $question->set_source_table(
                'playerpuzzle_attempt_questions',
                ['attemptid' => backup::VAR_PARENTID]
            );
            $consumable->set_source_table(
                'playerpuzzle_attempt_consumables',
                ['attemptid' => backup::VAR_PARENTID]
            );
        }

        // Annotate files embedded in the intro editor field, if any.
        $playerpuzzle->annotate_files('mod_playerpuzzle', 'intro', null);

        // Annotate IDs that reference other tables so they are remapped on restore. Hud
        // item fields are deliberately not annotated here — block_playerhud's own backup
        // task is already part of the same course backup independently of this activity,
        // and its restore step registers the playerhud_item mapping resolve_hud_item()
        // reads on the restore side, mirroring mod_playerwords' own backup step.
        $playerpuzzle->annotate_ids('question_category', 'questioncategory');

        if ($userinfo) {
            $attempt->annotate_ids('user', 'userid');
            $question->annotate_ids('question', 'questionid');
        }

        // Wrap the root in the standard activity envelope.
        return $this->prepare_activity_structure($playerpuzzle);
    }
}
