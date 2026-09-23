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
            'hud_retry_cost_item',
            'hud_retry_cost_qty',
            'hud_win_grant_item',
            'hud_win_grant_qty',
            'gamemode',
            'max_single_matches',
            'grademethod',
            'minquestions',
            'considererrors',
            'show_ranking',
            'grade',
            'gradepass',
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
            'isdemo',
            'bosshp_remaining',
            'questions_correct',
            'questions_total',
            'coins_earned',
            'boss_coins_earned',
            'combatstate',
            'currentquestionid',
            'rngseed',
            'moveseq',
            'movelog',
            'engineversion',
            'frozenbasebosshp',
            'frozenbossdamage',
            'frozencoingain',
            'questionresults',
            'phaserestarts',
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

        // Loadout stock is keyed by userid+playerpuzzleid directly, not by attempt — a
        // sibling of attempts under the root, not a child of it.
        $stockitems = new backup_nested_element('stockitems');
        $stockitem = new backup_nested_element('stockitem', ['id'], [
            'userid',
            'consumabletype',
            'quantity',
        ]);

        // PlayerPuzzle's own question bank belongs to the activity and is always backed
        // up — course content, not user data, same rule already applied to the words in
        // mod_playerwords. Named 'bankquestion'/'bankanswer' throughout (PHP variables, XML
        // tags and restore mapping namespaces) to keep it visually distinct from the
        // 'question'/'attempt_question' elements a few lines below, which log a *reference*
        // to one of these rows from inside an attempt, a different concept entirely.
        $bankquestions = new backup_nested_element('bankquestions');
        $bankquestion = new backup_nested_element('bankquestion', ['id'], [
            'qtype',
            'questiontext',
            'questiontextformat',
            'generalfeedback',
            'hint',
            'source',
            'sourceid',
            'approved',
            'timecreated',
            'timemodified',
            'addedby',
        ]);
        $bankanswers = new backup_nested_element('bankanswers');
        $bankanswer = new backup_nested_element('bankanswer', ['id'], [
            'answertext',
            'answerformat',
            'iscorrect',
            'sortorder',
        ]);

        // Build the tree. Bank questions (and their answers) are added before attempts, so
        // the playerpuzzle_bankquestion mapping already exists — via the same-name
        // set_mapping() call in the restore step — by the time an attempt's logged question
        // row needs to remap questionid, or the attempt itself needs to remap its own
        // currentquestionid; all are children of the same activity instance in the same
        // document, so no after_execute() deferral is needed here.
        $playerpuzzle->add_child($bankquestions);
        $bankquestions->add_child($bankquestion);
        $bankquestion->add_child($bankanswers);
        $bankanswers->add_child($bankanswer);

        if ($userinfo) {
            $playerpuzzle->add_child($attempts);
            $attempts->add_child($attempt);
            $attempt->add_child($questions);
            $questions->add_child($question);
            $attempt->add_child($consumables);
            $consumables->add_child($consumable);
            $playerpuzzle->add_child($stockitems);
            $stockitems->add_child($stockitem);
        }

        // Connect elements to database tables.
        $playerpuzzle->set_source_table('playerpuzzle', ['id' => backup::VAR_ACTIVITYID]);
        $bankquestion->set_source_table(
            'playerpuzzle_questions',
            ['playerpuzzleid' => backup::VAR_ACTIVITYID]
        );
        $bankanswer->set_source_table(
            'playerpuzzle_question_answers',
            ['questionid' => backup::VAR_PARENTID]
        );

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
            $stockitem->set_source_table(
                'playerpuzzle_user_stock',
                ['playerpuzzleid' => backup::VAR_ACTIVITYID]
            );
        }

        // Annotate files embedded in the intro editor field, if any.
        $playerpuzzle->annotate_files('mod_playerpuzzle', 'intro', null);

        // Annotate files embedded in a bank question's own questiontext/answertext editors
        // — 'id' means each row's own id is the itemid, the same idiom used for a per-row
        // filearea elsewhere in Moodle (e.g. a glossary entry's attachments).
        $bankquestion->annotate_files('mod_playerpuzzle', 'questiontext', 'id');
        $bankanswer->annotate_files('mod_playerpuzzle', 'answertext', 'id');

        // Annotate IDs that reference other tables so they are remapped on restore. Hud
        // item fields are deliberately not annotated here — block_playerhud's own backup
        // task is already part of the same course backup independently of this activity,
        // and its restore step registers the playerhud_item mapping resolve_hud_item()
        // reads on the restore side, mirroring mod_playerwords' own backup step.
        $playerpuzzle->annotate_ids('question_category', 'questioncategory');

        // Addedby identifies the teacher/manager who authored or triggered the question
        // (0 for a bank-imported one) — always annotated, unconditional on $userinfo, same
        // as mod_playerwords' own words.addedby: it is content metadata, not the personal
        // data $userinfo gates, but still a real user reference that must be remapped or
        // dropped to 0 rather than restored as a raw, possibly-wrong id.
        $bankquestion->annotate_ids('user', 'addedby');

        if ($userinfo) {
            $attempt->annotate_ids('user', 'userid');
            $stockitem->annotate_ids('user', 'userid');
            // Questionid now points at this activity's own bank (see the Wave 1 revert to a
            // single question source) — never the real Moodle question bank, so it must be
            // resolved via the playerpuzzle_bankquestion mapping, not the generic 'question'
            // namespace core's own question-bank restore step registers. Getting this wrong
            // is silent: get_mappingid() on the wrong namespace never errors, it simply
            // never finds a match and always resolves to 0.
            $question->annotate_ids('playerpuzzle_bankquestion', 'questionid');
        }

        // Wrap the root in the standard activity envelope.
        return $this->prepare_activity_structure($playerpuzzle);
    }
}
