<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The main module configuration form.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
    public function definition() {
        global $DB, $COURSE;

        $mform = $this->_form;

        // General settings header.
        $mform->addElement('header', 'general', get_string('general', 'mod_playerpuzzle'));

        $mform->addElement('text', 'name', get_string('name', 'mod_playerpuzzle'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        // Boss settings header.
        $mform->addElement('header', 'bosssettings', get_string('bosssettings', 'mod_playerpuzzle'));

        // Native library of bosses.
        $bossoptions = [
            'slime.png'  => 'Slime',
            'goblin.png' => 'Goblin',
            'dragon.png' => 'Dragon',
        ];
        $mform->addElement('select', 'bossavatar', get_string('bossavatar', 'mod_playerpuzzle'), $bossoptions);
        $mform->setType('bossavatar', PARAM_FILE);
        $mform->setDefault('bossavatar', 'slime.png');

        $mform->addElement('text', 'bosshp', get_string('bosshp', 'mod_playerpuzzle'));
        $mform->setType('bosshp', PARAM_INT);
        $mform->setDefault('bosshp', 1000);

        $mform->addElement('text', 'bossdamage', get_string('bossdamage', 'mod_playerpuzzle'));
        $mform->setType('bossdamage', PARAM_INT);
        $mform->setDefault('bossdamage', 10);

        // SELEÇÃO DE CATEGORIA DE QUESTÕES (Moodle 4.0+).
        $mform->addElement('header', 'questionsettings', get_string('questionsettings', 'mod_playerpuzzle'));

        $categories = [];
        $coursecontext = \context_course::instance($COURSE->id);
        $contextstocheck = [];

        // 1. Contextos Pais (Sistema, Categoria, Curso)
        foreach ($coursecontext->get_parent_contexts(true) as $ctx) {
            $contextstocheck[$ctx->id] = $ctx;
        }

        // 2. Contextos de Módulos dentro do Curso
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
            $sql = "SELECT id, name, contextid
                      FROM {question_categories}
                     WHERE contextid $insql
                  ORDER BY contextid, name ASC";
            $dbcategories = $DB->get_records_sql($sql, $params);

            if ($dbcategories) {
                foreach ($dbcategories as $cat) {
                    try {
                        $catcontext = \context::instance_by_id($cat->contextid);
                        $contextname = $catcontext->get_context_name(false, true);
                        $categories[$cat->id] = format_string($cat->name) . ' (' . $contextname . ')';
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        if (empty($categories)) {
            // Caso não encontre nada, mostra o aviso.
            $categories[0] = 'Nenhuma categoria de questões encontrada.';
        }

        $mform->addElement('select', 'questioncategory', get_string('questioncategory', 'mod_playerpuzzle'), $categories);
        $mform->setType('questioncategory', PARAM_INT);
        $mform->addRule('questioncategory', null, 'required', null, 'client');
        // ------------------------------------------------------

        // Game rules header.
        $mform->addElement('header', 'rules', get_string('rules', 'mod_playerpuzzle'));

        $mform->addElement('text', 'timelimit', get_string('timelimit', 'mod_playerpuzzle'));
        $mform->setType('timelimit', PARAM_INT);
        $mform->setDefault('timelimit', 0);
        $mform->addHelpButton('timelimit', 'timelimit', 'mod_playerpuzzle');

        $mform->addElement('text', 'maxattempts', get_string('maxattempts', 'mod_playerpuzzle'));
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0); // 0 equals unlimited.

        // Standard Moodle module elements (Grade, Common module settings, Restrict access).
        $this->standard_coursemodule_elements();

        // Add standard action buttons (Save, Cancel).
        $this->add_action_buttons();
    }
}
