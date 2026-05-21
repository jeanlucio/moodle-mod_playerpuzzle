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

        $this->standard_intro_elements();

        $mform->addElement('header', 'levelsandphases', get_string('levelsandphases', 'mod_playerpuzzle'));

        $leveloptions = [];
        for ($i = 1; $i <= 10; $i++) {
            $leveloptions[$i] = $i;
        }
        $mform->addElement('select', 'maxlevels', get_string('maxlevels', 'mod_playerpuzzle'), $leveloptions);
        $mform->setType('maxlevels', PARAM_INT);
        $mform->setDefault('maxlevels', 1);
        $mform->addHelpButton('maxlevels', 'maxlevels', 'mod_playerpuzzle');

        $mform->addElement('text', 'basestudenthp', get_string('basestudenthp', 'mod_playerpuzzle'));
        $mform->setType('basestudenthp', PARAM_INT);
        $mform->setDefault('basestudenthp', 100);
        $mform->addHelpButton('basestudenthp', 'basestudenthp', 'mod_playerpuzzle');

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

        $mform->addElement('header', 'rules', get_string('rules', 'mod_playerpuzzle'));

        $mform->addElement('text', 'timelimit', get_string('timelimit', 'mod_playerpuzzle'));
        $mform->setType('timelimit', PARAM_INT);
        $mform->setDefault('timelimit', 0);
        $mform->addHelpButton('timelimit', 'timelimit', 'mod_playerpuzzle');

        $mform->addElement('text', 'maxattempts', get_string('maxattempts', 'mod_playerpuzzle'));
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}
