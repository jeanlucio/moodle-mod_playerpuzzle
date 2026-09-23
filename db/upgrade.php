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
 * Plugin upgrade steps.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executes mod_playerpuzzle upgrade steps from the given old version.
 *
 * @param int $oldversion Version number we are upgrading from.
 * @return bool True if upgrade succeeded.
 */
function xmldb_playerpuzzle_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026052101) {
        $table = new xmldb_table('playerpuzzle');

        // Rename bosshp -> basebosshp.
        $field = new xmldb_field('bosshp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1000');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'basebosshp');
        }

        // Add maxlevels.
        $field = new xmldb_field('maxlevels', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add basestudenthp.
        $field = new xmldb_field('basestudenthp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $attemptstable = new xmldb_table('playerpuzzle_attempts');

        // Add currentlevel.
        $field = new xmldb_field('currentlevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        // Add currentphase.
        $field = new xmldb_field('currentphase', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        // Add timefinished.
        $field = new xmldb_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        upgrade_mod_savepoint(true, 2026052101, 'playerpuzzle');
    }

    if ($oldversion < 2026082201) {
        $table = new xmldb_table('playerpuzzle');

        // Add hud_sword_item.
        $field = new xmldb_field('hud_sword_item', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add hud_shield_item.
        $field = new xmldb_field('hud_shield_item', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Drop playerpuzzle_inventory: coins and upgrade levels are no longer stored locally.
        // Coins are banked into block_playerhud's own PlayerCoin item and upgrade levels are
        // read from teacher-picked block_playerhud items (see hud_service); with no PlayerHUD
        // installed there is no permanent progression at all.
        $inventorytable = new xmldb_table('playerpuzzle_inventory');
        if ($dbman->table_exists($inventorytable)) {
            $dbman->drop_table($inventorytable);
        }

        upgrade_mod_savepoint(true, 2026082201, 'playerpuzzle');
    }

    if ($oldversion < 2026082202) {
        $table = new xmldb_table('playerpuzzle');

        // Add hud_coin_item.
        $field = new xmldb_field('hud_coin_item', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026082202, 'playerpuzzle');
    }

    if ($oldversion < 2026082401) {
        $table = new xmldb_table('playerpuzzle');

        // Add hud_potion_item.
        $field = new xmldb_field('hud_potion_item', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add hud_retry_cost_item.
        $field = new xmldb_field('hud_retry_cost_item', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add hud_retry_cost_qty.
        $field = new xmldb_field('hud_retry_cost_qty', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add hud_win_grant_item.
        $field = new xmldb_field('hud_win_grant_item', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add hud_win_grant_qty.
        $field = new xmldb_field('hud_win_grant_qty', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add gamemode.
        $field = new xmldb_field('gamemode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'campaign');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add max_single_matches.
        $field = new xmldb_field('max_single_matches', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add grademethod.
        $field = new xmldb_field('grademethod', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add minquestions.
        $field = new xmldb_field('minquestions', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add considererrors.
        $field = new xmldb_field('considererrors', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026082401, 'playerpuzzle');
    }

    if ($oldversion < 2026082502) {
        $table = new xmldb_table('playerpuzzle');

        // Add coingain.
        $field = new xmldb_field('coingain', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '10');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026082502, 'playerpuzzle');
    }

    if ($oldversion < 2026082504) {
        $table = new xmldb_table('playerpuzzle');

        // Lower the default basebosshp from 1000 to 100: at Level 1 Phase 1 (before any
        // level/phase scaling), a 10x HP ratio against the default 100 student HP made the
        // boss effectively unbeatable out of the box.
        $field = new xmldb_field('basebosshp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
        }

        upgrade_mod_savepoint(true, 2026082504, 'playerpuzzle');
    }

    if ($oldversion < 2026082704) {
        $attemptstable = new xmldb_table('playerpuzzle_attempts');

        // Add difficulty: the student's Easy/Normal/Hard choice for the run, made in the
        // Lobby and locked once the attempt is in progress. Scales the boss HP/damage and
        // the coin reward; never the grade.
        $field = new xmldb_field('difficulty', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'normal', 'currentphase');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        upgrade_mod_savepoint(true, 2026082704, 'playerpuzzle');
    }

    if ($oldversion < 2026082801) {
        // Add playerpuzzle_attempt_questions: one row per question the student answered,
        // for the post-game review and the teacher report.
        $table = new xmldb_table('playerpuzzle_attempt_questions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('attemptlevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('attemptphase', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('chosenanswer', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('correctanswer', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('iscorrect', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('attemptid', XMLDB_KEY_FOREIGN, ['attemptid'], 'playerpuzzle_attempts', ['id']);
            $table->add_index('attemptid-phase', XMLDB_INDEX_NOTUNIQUE, ['attemptid', 'attemptlevel', 'attemptphase']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026082801, 'playerpuzzle');
    }

    if ($oldversion < 2026082902) {
        $table = new xmldb_table('playerpuzzle');

        // Add maxconsumables.
        $field = new xmldb_field('maxconsumables', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $attemptstable = new xmldb_table('playerpuzzle_attempts');

        // Add coins_earned.
        $field = new xmldb_field('coins_earned', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        // Add boss_coins_earned.
        $field = new xmldb_field('boss_coins_earned', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        // Add coins_spent.
        $field = new xmldb_field('coins_spent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        // Add playerpuzzle_attempt_consumables: counts consumable uses per attempt, for the
        // maxconsumables limit.
        $consumablestable = new xmldb_table('playerpuzzle_attempt_consumables');
        if (!$dbman->table_exists($consumablestable)) {
            $consumablestable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $consumablestable->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $consumablestable->add_field('consumabletype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
            $consumablestable->add_field('timesused', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $consumablestable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $consumablestable->add_key('attemptid', XMLDB_KEY_FOREIGN, ['attemptid'], 'playerpuzzle_attempts', ['id']);
            $consumablestable->add_index('attemptid-type', XMLDB_INDEX_UNIQUE, ['attemptid', 'consumabletype']);
            $dbman->create_table($consumablestable);
        }

        upgrade_mod_savepoint(true, 2026082902, 'playerpuzzle');
    }

    if ($oldversion < 2026091201) {
        $attemptstable = new xmldb_table('playerpuzzle_attempts');

        // Add combatstate: JSON snapshot of the in-progress board/combat, so a reload can
        // resume the fight in place instead of always starting the phase fresh.
        $field = new xmldb_field('combatstate', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        upgrade_mod_savepoint(true, 2026091201, 'playerpuzzle');
    }

    if ($oldversion < 2026091401) {
        $table = new xmldb_table('playerpuzzle');

        // Add grade: the maximum grade configured via standard_grading_coursemodule_elements()
        // (None / Point / Scale), scaling both grade formulas.
        $field = new xmldb_field('grade', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '100.00000');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add gradepass.
        $field = new xmldb_field('gradepass', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0.00000');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026091401, 'playerpuzzle');
    }

    if ($oldversion < 2026091501) {
        $table = new xmldb_table('playerpuzzle');

        // Add duedate: an optional deadline shown as a calendar event
        // (playerpuzzle_refresh_events()), 0 meaning no deadline configured.
        $field = new xmldb_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026091501, 'playerpuzzle');
    }

    if ($oldversion < 2026091601) {
        $table = new xmldb_table('playerpuzzle');

        // Add completionattempts: custom completion rule, minimum finished attempts.
        $field = new xmldb_field('completionattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add completionwins: custom completion rule, minimum won attempts/matches.
        $field = new xmldb_field('completionwins', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026091601, 'playerpuzzle');
    }

    if ($oldversion < 2026091606) {
        $table = new xmldb_table('playerpuzzle');

        // Add sources: question-source bitmask (1=Moodle question bank category,
        // 2=PlayerPuzzle's own bank). Defaults to 1 so every existing instance keeps reading
        // from its already-configured questioncategory exactly as before.
        $field = new xmldb_field('sources', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add playerpuzzle_questions: PlayerPuzzle's own question bank (manual and/or
        // AI-generated), per instance.
        $questionstable = new xmldb_table('playerpuzzle_questions');
        if (!$dbman->table_exists($questionstable)) {
            $questionstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $questionstable->add_field('playerpuzzleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $questionstable->add_field('qtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
            $questionstable->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $questionstable->add_field('generalfeedback', XMLDB_TYPE_TEXT, null, null, null);
            $questionstable->add_field('hint', XMLDB_TYPE_TEXT, null, null, null);
            $questionstable->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
            $questionstable->add_field('approved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $questionstable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $questionstable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $questionstable->add_field('addedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $questionstable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $questionstable->add_key('playerpuzzleid', XMLDB_KEY_FOREIGN, ['playerpuzzleid'], 'playerpuzzle', ['id']);
            $questionstable->add_key('addedby', XMLDB_KEY_FOREIGN, ['addedby'], 'user', ['id']);
            $questionstable->add_index('approved', XMLDB_INDEX_NOTUNIQUE, ['approved']);
            $dbman->create_table($questionstable);
        }

        // Add playerpuzzle_question_answers: answer options for a playerpuzzle_questions row.
        $answerstable = new xmldb_table('playerpuzzle_question_answers');
        if (!$dbman->table_exists($answerstable)) {
            $answerstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $answerstable->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $answerstable->add_field('answertext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $answerstable->add_field('iscorrect', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $answerstable->add_field('sortorder', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $answerstable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $answerstable->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'playerpuzzle_questions', ['id']);
            $dbman->create_table($answerstable);
        }

        upgrade_mod_savepoint(true, 2026091606, 'playerpuzzle');
    }

    if ($oldversion < 2026091607) {
        // Drop sources: the dual-source design (Moodle question bank + PlayerPuzzle's own
        // bank, unioned at read time) was superseded by a single-source design where the own
        // bank is the only place questions are read from at runtime, and the Moodle question
        // bank is only ever imported into it.
        $table = new xmldb_table('playerpuzzle');
        $field = new xmldb_field('sources', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Change questioncategory's default: it now means "last category imported from"
        // rather than "category to read from every match", and can legitimately stay 0
        // (nothing imported yet) for the lifetime of an instance, so it needs a real default
        // instead of relying on the form to always supply a value.
        $field = new xmldb_field('questioncategory', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
        }

        // Add questiontextformat/answerformat: both tables only ever stored PARAM_TEXT plain
        // text so far, but the columns are needed now so a future editor upgrade (rich text +
        // files) does not need its own schema change on top of this one.
        $questionstable = new xmldb_table('playerpuzzle_questions');
        $field = new xmldb_field('questiontextformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'questiontext');
        if (!$dbman->field_exists($questionstable, $field)) {
            $dbman->add_field($questionstable, $field);
        }

        $answerstable = new xmldb_table('playerpuzzle_question_answers');
        $field = new xmldb_field('answerformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'answertext');
        if (!$dbman->field_exists($answerstable, $field)) {
            $dbman->add_field($answerstable, $field);
        }

        upgrade_mod_savepoint(true, 2026091607, 'playerpuzzle');
    }

    if ($oldversion < 2026091608) {
        // Add sourceid: matches a bank-imported question back to its question_bank_entries
        // row on re-sync, so re-importing a category updates the same rows instead of
        // duplicating them.
        $table = new xmldb_table('playerpuzzle_questions');
        $field = new xmldb_field('sourceid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'source');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026091608, 'playerpuzzle');
    }

    if ($oldversion < 2026091803) {
        // Add istutorial: flags an attempt as the user's first-ever one at this instance,
        // decided once at creation — first-match tutorial, reduced boss HP at Level
        // 1/Phase 1 only.
        $table = new xmldb_table('playerpuzzle_attempts');
        $field = new xmldb_field('istutorial', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'difficulty');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026091803, 'playerpuzzle');
    }

    if ($oldversion < 2026091804) {
        // Replace istutorial with isdemo: the automatic first-attempt tutorial gate is
        // replaced by an on-demand, repeatable "Play Demo" button on the Lobby — never
        // counted for grade/coins/completion/attempt-limit, fixed HP on both sides.
        $table = new xmldb_table('playerpuzzle_attempts');

        $oldfield = new xmldb_field('istutorial');
        if ($dbman->field_exists($table, $oldfield)) {
            $dbman->drop_field($table, $oldfield);
        }

        $newfield = new xmldb_field('isdemo', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'difficulty');
        if (!$dbman->field_exists($table, $newfield)) {
            $dbman->add_field($table, $newfield);
        }

        upgrade_mod_savepoint(true, 2026091804, 'playerpuzzle');
    }

    if ($oldversion < 2026091805) {
        // Server-tracked "current open question" per attempt: a client-supplied questionid
        // on validate_answer.php's forwhom=boss path would let a caller probe any approved
        // question of the instance for its correct answer. The client no longer chooses
        // which question is in play; the server draws it and validate_answer only ever
        // operates on this column, never a client-supplied id.
        $table = new xmldb_table('playerpuzzle_attempts');
        $field = new xmldb_field('currentquestionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'combatstate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026091805, 'playerpuzzle');
    }

    if ($oldversion < 2026092001) {
        // Add playerpuzzle_user_stock: consumable stock a user has bought for an instance's
        // pre-match loadout, persistent across attempts — separate from
        // playerpuzzle_attempt_consumables (which counts uses, not stock, and is scoped to a
        // single attempt).
        $table = new xmldb_table('playerpuzzle_user_stock');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('playerpuzzleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('consumabletype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
            $table->add_field('quantity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('playerpuzzleid', XMLDB_KEY_FOREIGN, ['playerpuzzleid'], 'playerpuzzle', ['id']);
            $table->add_index('userid-playerpuzzleid-type', XMLDB_INDEX_UNIQUE, ['userid', 'playerpuzzleid', 'consumabletype']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026092001, 'playerpuzzle');
    }

    if ($oldversion < 2026092103) {
        // Drop maxconsumables (replaced by attempt_consumables::PHASE_LIMITS, a fixed
        // per-type limit rather than a single teacher-configurable number) and
        // hud_sword_item/hud_shield_item/hud_potion_item (the per-type PlayerHUD stock
        // items are retired in favour of PuzzleCoin, the plugin's own persistent balance).
        // Safe to drop outright rather than deprecate: the plugin has not been published
        // yet, so there is no real installation with a configured value to preserve.
        $table = new xmldb_table('playerpuzzle');
        foreach (['maxconsumables', 'hud_sword_item', 'hud_shield_item', 'hud_potion_item'] as $fieldname) {
            $field = new xmldb_field($fieldname);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        // Drop coins_spent: with buying moved entirely out of a match, nothing debits it
        // any more, and coin_ledger::spendable() (the only reader) is retired alongside it.
        $attemptstable = new xmldb_table('playerpuzzle_attempts');
        $coinsspentfield = new xmldb_field('coins_spent');
        if ($dbman->field_exists($attemptstable, $coinsspentfield)) {
            $dbman->drop_field($attemptstable, $coinsspentfield);
        }

        upgrade_mod_savepoint(true, 2026092103, 'playerpuzzle');
    }

    if ($oldversion < 2026092202) {
        // Add the deterministic-replay fields: a PRNG seed and move log (for a future
        // server-side re-simulation to verify a match instead of trusting the client's own
        // reported totals), plus the engine version and instance config frozen at the time
        // they were captured (so a later plugin upgrade or teacher edit mid-match cannot
        // desync a replay of an already-played phase). All reset on every new attempt/phase,
        // same lifecycle as combatstate.
        $table = new xmldb_table('playerpuzzle_attempts');

        $field = new xmldb_field('rngseed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'currentquestionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('moveseq', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'rngseed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('movelog', XMLDB_TYPE_TEXT, null, null, null, null, null, 'moveseq');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('engineversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'movelog');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('frozenbasebosshp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'engineversion');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('frozenbossdamage', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'frozenbasebosshp');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('frozencoingain', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'frozenbossdamage');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026092202, 'playerpuzzle');
    }

    if ($oldversion < 2026092300) {
        // Server-decided question outcomes for the current phase, so the replay stops taking
        // "was this answer right?" from the client's own event log.
        $table = new xmldb_table('playerpuzzle_attempts');
        $field = new xmldb_field('questionresults', XMLDB_TYPE_TEXT, null, null, null, null, null, 'frozencoingain');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026092300, 'playerpuzzle');
    }

    if ($oldversion < 2026092301) {
        // Restarts of the current phase after a victory the replay could not verify.
        $table = new xmldb_table('playerpuzzle_attempts');
        $field = new xmldb_field('phaserestarts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'questionresults');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026092301, 'playerpuzzle');
    }

    if ($oldversion < 2026092302) {
        // Lets the teacher hide the student-facing ranking; on by default.
        $table = new xmldb_table('playerpuzzle');
        $field = new xmldb_field('show_ranking', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'considererrors');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026092302, 'playerpuzzle');
    }

    return true;
}
