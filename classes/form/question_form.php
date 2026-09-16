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

use mod_playerpuzzle\local\question_editor_files;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for adding/editing one playerpuzzle_questions row.
 *
 * A fixed 5-slot layout for multichoice (only the first 2 required) rather than a
 * repeat_elements() "add more" control — mirrors mod_playerland\form\question_form's
 * fixed-4-slot pattern already shipped in this ecosystem, and 5 is the same ceiling
 * question_fetcher.php already presents for a core_question multichoice.
 *
 * questiontext and each multichoice option are `editor` elements (rich text + embedded
 * files), matching qtype_multichoice's own edit form. Loading/saving their draft file
 * areas is orchestrated by managequestions.php, not here — a plain moodleform has no
 * $this->context of its own, and the two-phase "insert placeholder row, then finalize once
 * the real id exists" dance belongs with the caller that owns the DB write.
 */
class question_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $editoroptions = question_editor_files::editor_options($this->_customdata['context']);

        $mform->addElement('hidden', 'qid', 0);
        $mform->setType('qid', PARAM_INT);

        $mform->addElement('hidden', 'cmid', 0);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('select', 'qtype', get_string('questiontype', 'mod_playerpuzzle'), [
            'multichoice' => get_string('qtype_multichoice', 'mod_playerpuzzle'),
            'truefalse' => get_string('qtype_truefalse', 'mod_playerpuzzle'),
        ]);
        $mform->setType('qtype', PARAM_ALPHA);

        $mform->addElement(
            'editor',
            'questiontext_editor',
            get_string('questiontext', 'mod_playerpuzzle'),
            ['rows' => 5],
            $editoroptions
        );
        $mform->setType('questiontext_editor', PARAM_RAW);

        $mform->addElement('textarea', 'hint', get_string('hint', 'mod_playerpuzzle'), ['rows' => 2]);
        $mform->setType('hint', PARAM_TEXT);
        $mform->addHelpButton('hint', 'hint', 'mod_playerpuzzle');

        // True/False: only which side is correct is asked — the answer text itself is
        // always the two fixed core strings (qtype_truefalse's own "True"/"False"), never
        // rich text, so no editor/file area applies to either side.
        $mform->addElement('radio', 'tfcorrect', '', get_string('true', 'qtype_truefalse'), 'true');
        $mform->addElement('radio', 'tfcorrect', '', get_string('false', 'qtype_truefalse'), 'false');
        $mform->setDefault('tfcorrect', 'true');
        $mform->hideIf('tfcorrect', 'qtype', 'eq', 'multichoice');

        // Multichoice: up to 5 options, radio-selects which one is correct. Matches
        // qtype_multichoice's own single-answer model (fraction >= 1.0 check).
        for ($i = 1; $i <= 5; $i++) {
            $group = [
                $mform->createElement('editor', "optiontext_editor[$i]", '', ['rows' => 2], $editoroptions),
                $mform->createElement('radio', 'mccorrect', '', '', $i),
            ];
            $mform->addGroup(
                $group,
                "optiongroup_$i",
                get_string('optionnum', 'mod_playerpuzzle', $i),
                [' '],
                false
            );
            $mform->setType("optiontext_editor[$i]", PARAM_RAW);
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

        if (self::editor_text_is_empty($data['questiontext_editor'] ?? null)) {
            $errors['questiontext_editor'] = get_string('required');
        }

        if ($data['qtype'] === 'multichoice') {
            $filled = 0;
            foreach ($data['optiontext_editor'] as $editorvalue) {
                if (!self::editor_text_is_empty($editorvalue)) {
                    $filled++;
                }
            }
            if ($filled < 2) {
                $errors['optiongroup_1'] = get_string('error_atleasttwooptions', 'mod_playerpuzzle');
            }
            if (self::editor_text_is_empty($data['optiontext_editor'][$data['mccorrect']] ?? null)) {
                $errors['optiongroup_' . $data['mccorrect']] = get_string('error_correctoptionempty', 'mod_playerpuzzle');
            }
        }

        return $errors;
    }

    /**
     * Checks whether an editor element's submitted value has no real text — an embedded
     * image with no caption still counts as content, so this strips tags rather than just
     * checking for an empty string.
     *
     * @param array|null $editorvalue The editor element's submitted ['text' => ..., ...]
     *  value, or null if the field was not present at all.
     * @return bool
     */
    private static function editor_text_is_empty(?array $editorvalue): bool {
        $text = (string) ($editorvalue['text'] ?? '');

        return trim(strip_tags($text)) === '' && !str_contains($text, '<img');
    }
}
