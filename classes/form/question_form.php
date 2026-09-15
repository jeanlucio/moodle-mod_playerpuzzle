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
 * Manual question entry/edit form for PlayerPuzzle's own question bank.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for adding/editing one playerpuzzle_questions row.
 *
 * A fixed 5-slot layout for multichoice (only the first 2 required) rather than a
 * repeat_elements() "add more" control — mirrors mod_playerland\form\question_form's
 * fixed-4-slot pattern already shipped in this ecosystem, and 5 is the same ceiling
 * question_fetcher.php already presents for a core_question multichoice.
 */
class question_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'qid', 0);
        $mform->setType('qid', PARAM_INT);

        $mform->addElement('hidden', 'cmid', 0);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('select', 'qtype', get_string('questiontype', 'mod_playerpuzzle'), [
            'multichoice' => get_string('qtype_multichoice', 'mod_playerpuzzle'),
            'truefalse' => get_string('qtype_truefalse', 'mod_playerpuzzle'),
        ]);
        $mform->setType('qtype', PARAM_ALPHA);

        $mform->addElement('textarea', 'questiontext', get_string('questiontext', 'mod_playerpuzzle'), ['rows' => 3]);
        $mform->setType('questiontext', PARAM_TEXT);
        $mform->addRule('questiontext', null, 'required', null, 'client');

        $mform->addElement('textarea', 'hint', get_string('hint', 'mod_playerpuzzle'), ['rows' => 2]);
        $mform->setType('hint', PARAM_TEXT);
        $mform->addHelpButton('hint', 'hint', 'mod_playerpuzzle');

        // True/False: only which side is correct is asked — the answer text itself is
        // always the two fixed core strings (qtype_truefalse's own "True"/"False"), so a
        // truefalse question from this bank reads identically to one from the Moodle
        // question bank once question_fetcher.php reads from both sources.
        $mform->addElement('radio', 'tfcorrect', '', get_string('true', 'qtype_truefalse'), 'true');
        $mform->addElement('radio', 'tfcorrect', '', get_string('false', 'qtype_truefalse'), 'false');
        $mform->setDefault('tfcorrect', 'true');
        $mform->hideIf('tfcorrect', 'qtype', 'eq', 'multichoice');

        // Multichoice: up to 5 options, radio-selects which one is correct. Matches
        // qtype_multichoice's own single-answer model (fraction >= 1.0 check).
        for ($i = 1; $i <= 5; $i++) {
            $group = [
                $mform->createElement('text', "optiontext[$i]", '', ['size' => '50']),
                $mform->createElement('radio', 'mccorrect', '', '', $i),
            ];
            $mform->addGroup(
                $group,
                "optiongroup_$i",
                get_string('optionnum', 'mod_playerpuzzle', $i),
                [' '],
                false
            );
            $mform->setType("optiontext[$i]", PARAM_TEXT);
            $mform->hideIf("optiongroup_$i", 'qtype', 'eq', 'truefalse');
        }
        $mform->setDefault('mccorrect', 1);
        $mform->addElement(
            'static',
            'mccorrecthint',
            '',
            get_string('correctanswerhint', 'mod_playerpuzzle')
        );
        $mform->hideIf('mccorrecthint', 'qtype', 'eq', 'truefalse');

        $this->add_action_buttons(true, get_string('savechanges', 'core'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if ($data['qtype'] === 'multichoice') {
            $filled = 0;
            foreach ($data['optiontext'] as $index => $text) {
                if (trim((string) $text) !== '') {
                    $filled++;
                }
            }
            if ($filled < 2) {
                $errors['optiongroup_1'] = get_string('error_atleasttwooptions', 'mod_playerpuzzle');
            }
            $correcttext = trim((string) ($data['optiontext'][$data['mccorrect']] ?? ''));
            if ($correcttext === '') {
                $errors['optiongroup_' . $data['mccorrect']] = get_string('error_correctoptionempty', 'mod_playerpuzzle');
            }
        }

        return $errors;
    }
}
