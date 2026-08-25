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
 * The main module configuration form.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once(__DIR__ . '/lib.php');

/**
 * Module instance settings form.
 */
class mod_playerpuzzle_mod_form extends moodleform_mod {
    /**
     * Defines forms elements.
     */
    public function definition(): void {
        global $DB, $COURSE;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'mod_playerpuzzle'));

        $mform->addElement('text', 'name', get_string('name', 'mod_playerpuzzle'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement(
            'select',
            'gamemode',
            get_string('gamemode', 'mod_playerpuzzle'),
            [
                PLAYERPUZZLE_GAMEMODE_CAMPAIGN => get_string('gamemode_campaign', 'mod_playerpuzzle'),
                PLAYERPUZZLE_GAMEMODE_SINGLE   => get_string('gamemode_single', 'mod_playerpuzzle'),
            ]
        );
        $mform->setType('gamemode', PARAM_ALPHA);
        $mform->setDefault('gamemode', PLAYERPUZZLE_GAMEMODE_CAMPAIGN);
        $mform->addHelpButton('gamemode', 'gamemode', 'mod_playerpuzzle');

        $this->standard_intro_elements();

        $mform->addElement('header', 'studentsettings', get_string('studentsettings', 'mod_playerpuzzle'));

        $mform->addElement('text', 'basestudenthp', get_string('basestudenthp', 'mod_playerpuzzle'));
        $mform->setType('basestudenthp', PARAM_INT);
        $mform->setDefault('basestudenthp', 100);
        $mform->addHelpButton('basestudenthp', 'basestudenthp', 'mod_playerpuzzle');

        $mform->addElement('header', 'levelsandphases', get_string('levelsandphases', 'mod_playerpuzzle'));
        $mform->hideIf('levelsandphases', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_SINGLE);

        $leveloptions = [];
        for ($i = 1; $i <= 10; $i++) {
            $leveloptions[$i] = $i;
        }
        $mform->addElement('select', 'maxlevels', get_string('maxlevels', 'mod_playerpuzzle'), $leveloptions);
        $mform->setType('maxlevels', PARAM_INT);
        $mform->setDefault('maxlevels', 1);
        $mform->addHelpButton('maxlevels', 'maxlevels', 'mod_playerpuzzle');
        $mform->hideIf('maxlevels', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_SINGLE);

        $mform->addElement('header', 'singlematchheader', get_string('singlematchheader', 'mod_playerpuzzle'));
        $mform->hideIf('singlematchheader', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_CAMPAIGN);

        $singlematchoptions = [0 => get_string('unlimited', 'mod_playerpuzzle')];
        for ($i = 1; $i <= 10; $i++) {
            $singlematchoptions[$i] = $i;
        }
        $mform->addElement(
            'select',
            'max_single_matches',
            get_string('max_single_matches', 'mod_playerpuzzle'),
            $singlematchoptions
        );
        $mform->setType('max_single_matches', PARAM_INT);
        $mform->setDefault('max_single_matches', 0);
        $mform->hideIf('max_single_matches', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_CAMPAIGN);

        $mform->addElement(
            'select',
            'grademethod',
            get_string('grademethod', 'mod_playerpuzzle'),
            playerpuzzle_get_grademethod_options()
        );
        $mform->setType('grademethod', PARAM_INT);
        $mform->setDefault('grademethod', PLAYERPUZZLE_GRADE_HIGHEST);
        $mform->addHelpButton('grademethod', 'grademethod', 'mod_playerpuzzle');
        $mform->hideIf('grademethod', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_CAMPAIGN);

        $mform->addElement('header', 'bosssettings', get_string('bosssettings', 'mod_playerpuzzle'));

        $bossoptions = [
            'slime.png'  => 'Slime',
            'goblin.png' => 'Goblin',
            'dragon.png' => 'Dragon',
        ];
        $mform->addElement('select', 'bossavatar', get_string('bossavatar', 'mod_playerpuzzle'), $bossoptions);
        $mform->setType('bossavatar', PARAM_FILE);
        $mform->setDefault('bossavatar', 'slime.png');

        $mform->addElement('text', 'basebosshp', get_string('basebosshp', 'mod_playerpuzzle'));
        $mform->setType('basebosshp', PARAM_INT);
        $mform->setDefault('basebosshp', 1000);

        $mform->addElement('text', 'bossdamage', get_string('bossdamage', 'mod_playerpuzzle'));
        $mform->setType('bossdamage', PARAM_INT);
        $mform->setDefault('bossdamage', 10);
        $mform->addHelpButton('bossdamage', 'bossdamage', 'mod_playerpuzzle');

        $mform->addElement('text', 'coingain', get_string('coingain', 'mod_playerpuzzle'));
        $mform->setType('coingain', PARAM_INT);
        $mform->setDefault('coingain', 10);
        $mform->addHelpButton('coingain', 'coingain', 'mod_playerpuzzle');

        $mform->addElement('header', 'questionsettings', get_string('questionsettings', 'mod_playerpuzzle'));

        $categories = [];
        $coursecontext = \context_course::instance($COURSE->id);
        $contextstocheck = [];

        // Collect parent contexts (system, category, course) and all module contexts.
        foreach ($coursecontext->get_parent_contexts(true) as $ctx) {
            $contextstocheck[$ctx->id] = $ctx;
        }

        $modinfo = get_fast_modinfo($COURSE);
        foreach ($modinfo->cms as $cm) {
            $modcontext = \context_module::instance($cm->id);
            $contextstocheck[$modcontext->id] = $modcontext;
        }

        $validcontextids = [];
        foreach ($contextstocheck as $ctx) {
            try {
                if (has_capability('moodle/question:useall', $ctx) || has_capability('moodle/question:usemine', $ctx)) {
                    $validcontextids[] = $ctx->id;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!empty($validcontextids)) {
            [$insql, $params] = $DB->get_in_or_equal($validcontextids, SQL_PARAMS_NAMED);
            $sql = "SELECT qc.id, qc.name, qc.contextid, COUNT(qv.id) AS questioncount
                      FROM {question_categories} qc
                      JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
                      JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id AND qv.status = 'ready'
                     WHERE qc.contextid $insql
                  GROUP BY qc.id, qc.name, qc.contextid
                    HAVING COUNT(qv.id) > 0
                  ORDER BY qc.contextid, qc.name ASC";
            $dbcategories = $DB->get_records_sql($sql, $params);

            if ($dbcategories) {
                \context_helper::preload_contexts_by_id(array_column($dbcategories, 'contextid'));

                foreach ($dbcategories as $cat) {
                    try {
                        $catcontext = \context::instance_by_id($cat->contextid);
                        $contextname = $catcontext->get_context_name(false, true);
                        $categories[$cat->id] = format_string($cat->name) .
                            ' (' . $cat->questioncount . ') (' . $contextname . ')';
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        if (empty($categories)) {
            $categories[0] = get_string('nocategories', 'mod_playerpuzzle');
        }

        $mform->addElement('select', 'questioncategory', get_string('questioncategory', 'mod_playerpuzzle'), $categories);
        $mform->setType('questioncategory', PARAM_INT);
        $mform->addRule('questioncategory', null, 'required', null, 'client');

        $minquestionsoptions = [];
        for ($i = 0; $i <= 10; $i++) {
            $minquestionsoptions[$i] = $i;
        }
        $mform->addElement(
            'select',
            'minquestions',
            get_string('minquestions', 'mod_playerpuzzle'),
            $minquestionsoptions
        );
        $mform->setType('minquestions', PARAM_INT);
        $mform->setDefault('minquestions', 3);
        $mform->addHelpButton('minquestions', 'minquestions', 'mod_playerpuzzle');

        $mform->addElement('advcheckbox', 'considererrors', get_string('considererrors', 'mod_playerpuzzle'));
        $mform->setType('considererrors', PARAM_INT);
        $mform->setDefault('considererrors', 0);
        $mform->addHelpButton('considererrors', 'considererrors', 'mod_playerpuzzle');
        $mform->disabledIf('considererrors', 'minquestions', 'eq', 0);

        $mform->addElement('header', 'rules', get_string('rules', 'mod_playerpuzzle'));

        $mform->addElement('text', 'timelimit', get_string('timelimit', 'mod_playerpuzzle'));
        $mform->setType('timelimit', PARAM_INT);
        $mform->setDefault('timelimit', 0);
        $mform->addHelpButton('timelimit', 'timelimit', 'mod_playerpuzzle');

        $mform->addElement('text', 'maxattempts', get_string('maxattempts', 'mod_playerpuzzle'));
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);
        $mform->hideIf('maxattempts', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_SINGLE);

        $this->add_hud_elements($mform, (int) $COURSE->id);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Adds the PlayerHUD integration section: which items stand in for coins, the sword
     * upgrade level, and the shield upgrade level. Only rendered when a block_playerhud
     * instance exists in this course. All three fields list the same set of items — PlayerHUD's
     * own auto-generated PlayerCoin item shows up as an ordinary option here, like any other
     * item the teacher could pick or create.
     *
     * @param MoodleQuickForm $mform The form being built.
     * @param int $courseid Current course ID.
     */
    private function add_hud_elements(MoodleQuickForm $mform, int $courseid): void {
        if (!\mod_playerpuzzle\local\hud_service::is_available_for_course($courseid)) {
            return;
        }

        $blockinstanceid = \mod_playerpuzzle\local\hud_service::get_block_instance_id($courseid);
        $huditems = \mod_playerpuzzle\local\hud_service::get_items_for_block($blockinstanceid);
        $itemoptions = [0 => get_string('hud_noitem', 'mod_playerpuzzle')];
        foreach ($huditems as $item) {
            $itemoptions[$item->id] = format_string($item->name);
        }

        $mform->addElement('header', 'hudheader', get_string('hud_header', 'mod_playerpuzzle'));

        $mform->addElement(
            'select',
            'hud_coin_item',
            get_string('hud_coin_item', 'mod_playerpuzzle'),
            $this->add_stale_hud_item_option($itemoptions, $blockinstanceid, 'hud_coin_item')
        );
        $mform->setType('hud_coin_item', PARAM_INT);
        $mform->setDefault('hud_coin_item', 0);
        $mform->addHelpButton('hud_coin_item', 'hud_coin_item', 'mod_playerpuzzle');

        $mform->addElement(
            'select',
            'hud_sword_item',
            get_string('hud_sword_item', 'mod_playerpuzzle'),
            $this->add_stale_hud_item_option($itemoptions, $blockinstanceid, 'hud_sword_item')
        );
        $mform->setType('hud_sword_item', PARAM_INT);
        $mform->setDefault('hud_sword_item', 0);
        $mform->addHelpButton('hud_sword_item', 'hud_sword_item', 'mod_playerpuzzle');

        $mform->addElement(
            'select',
            'hud_shield_item',
            get_string('hud_shield_item', 'mod_playerpuzzle'),
            $this->add_stale_hud_item_option($itemoptions, $blockinstanceid, 'hud_shield_item')
        );
        $mform->setType('hud_shield_item', PARAM_INT);
        $mform->setDefault('hud_shield_item', 0);
        $mform->addHelpButton('hud_shield_item', 'hud_shield_item', 'mod_playerpuzzle');

        $mform->addElement(
            'select',
            'hud_potion_item',
            get_string('hud_potion_item', 'mod_playerpuzzle'),
            $this->add_stale_hud_item_option($itemoptions, $blockinstanceid, 'hud_potion_item')
        );
        $mform->setType('hud_potion_item', PARAM_INT);
        $mform->setDefault('hud_potion_item', 0);
        $mform->addHelpButton('hud_potion_item', 'hud_potion_item', 'mod_playerpuzzle');

        $mform->addElement(
            'select',
            'hud_retry_cost_item',
            get_string('hud_retry_cost_item', 'mod_playerpuzzle'),
            $this->add_stale_hud_item_option($itemoptions, $blockinstanceid, 'hud_retry_cost_item')
        );
        $mform->setType('hud_retry_cost_item', PARAM_INT);
        $mform->setDefault('hud_retry_cost_item', 0);
        $mform->addHelpButton('hud_retry_cost_item', 'hud_retry_cost_item', 'mod_playerpuzzle');

        $mform->addElement('text', 'hud_retry_cost_qty', get_string('hud_retry_cost_qty', 'mod_playerpuzzle'));
        $mform->setType('hud_retry_cost_qty', PARAM_INT);
        $mform->setDefault('hud_retry_cost_qty', 1);
        $mform->hideIf('hud_retry_cost_qty', 'hud_retry_cost_item', 'eq', 0);

        $mform->addElement(
            'select',
            'hud_win_grant_item',
            get_string('hud_win_grant_item', 'mod_playerpuzzle'),
            $this->add_stale_hud_item_option($itemoptions, $blockinstanceid, 'hud_win_grant_item')
        );
        $mform->setType('hud_win_grant_item', PARAM_INT);
        $mform->setDefault('hud_win_grant_item', 0);
        $mform->addHelpButton('hud_win_grant_item', 'hud_win_grant_item', 'mod_playerpuzzle');

        $mform->addElement('text', 'hud_win_grant_qty', get_string('hud_win_grant_qty', 'mod_playerpuzzle'));
        $mform->setType('hud_win_grant_qty', PARAM_INT);
        $mform->setDefault('hud_win_grant_qty', 1);
        $mform->hideIf('hud_win_grant_qty', 'hud_win_grant_item', 'eq', 0);
    }

    /**
     * Custom validation for PlayerPuzzle settings.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array Validation errors, keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (!empty($data['hud_retry_cost_item']) && (int) $data['hud_retry_cost_qty'] < 1) {
            $errors['hud_retry_cost_qty'] = get_string('error_hud_cost_qty', 'mod_playerpuzzle');
        }

        if (!empty($data['hud_win_grant_item']) && (int) $data['hud_win_grant_qty'] < 1) {
            $errors['hud_win_grant_qty'] = get_string('error_hud_cost_qty', 'mod_playerpuzzle');
        }

        return $errors;
    }

    /**
     * Keeps a previously configured item selectable even if it was disabled or deleted since,
     * so an existing instance's settings page never silently drops the stored value.
     *
     * @param array $options Item id => display name options built so far.
     * @param int $blockinstanceid Block instance ID the stored item is expected to belong to.
     * @param string $field Form field name to read the currently stored value from.
     * @return array
     */
    private function add_stale_hud_item_option(array $options, int $blockinstanceid, string $field): array {
        $storedid = (int) ($this->current->{$field} ?? 0);
        if ($storedid <= 0 || isset($options[$storedid])) {
            return $options;
        }

        $itemname = \mod_playerpuzzle\local\hud_service::get_item_name($blockinstanceid, $storedid);
        $options[$storedid] = ($itemname !== '')
            ? get_string('hud_item_disabled', 'mod_playerpuzzle', $itemname)
            : get_string('hud_item_deleted', 'mod_playerpuzzle');

        return $options;
    }
}
