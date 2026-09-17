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
 * Multichoice options use a growable slot count (starts at 5, "Add more" reveals 3 at a
 * time, no fixed ceiling) — mirrors question/type/multichoice/edit_multichoice_form.php's
 * own use of repeat_elements() (see lib/questionlib.php's QUESTION_NUMANS_START/_ADD), so
 * manual entry is never more limited than what the question actually needs. The AI
 * generation path has its own separate, much stricter cap
 * (mod_playerpuzzle\local\ai_question_generator::MAX_ANSWERS) purely to keep the model
 * from hallucinating an implausible option list — that is an unrelated concern from this
 * form's own capacity.
 *
 * questiontext and each multichoice option are `editor` elements (rich text + embedded
 * files), matching qtype_multichoice's own edit form. Loading/saving their draft file
 * areas is orchestrated by managequestions.php, not here — a plain moodleform has no
 * $this->context of its own, and the two-phase "insert placeholder row, then finalize once
 * the real id exists" dance belongs with the caller that owns the DB write.
 */
class question_form extends \moodleform {
    /**
     * Absolute ceiling on multichoice options this form will ever render, no matter what a
     * submitted repeat-count claims. Manual entry has no real product ceiling (see class
     * docblock), but a crafted request could otherwise set that count arbitrarily high and
     * force this form to build that many rich-text editor elements — the house rule on
     * clamping a client-supplied loop bound applies here exactly as it would to any other
     * repeat-elements control.
     */
    private const HARD_ANSWER_LIMIT = 20;

    /**
     * The number of multichoice option slots to show on a fresh (non-postback) load of this
     * form: never fewer than 5 (mirrors core's own multichoice edit form), but at least
     * enough to show every answer an existing question already has.
     *
     * @param int $existinganswercount Answers the question being edited already has (0 for
     *  a brand new question).
     * @return int
     */
    public static function initial_repeat_count(int $existinganswercount): int {
        return max(5, $existinganswercount);
    }

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

        // Multichoice: a growable list of options, radio-selects which one is correct.
        // The counting/"add more" mechanics below are the same ones repeat_elements() uses
        // internally (read the submitted repeat count, add 3 more if the no-submit "add"
        // button was clicked, lock the count against tampering via setConstants()) — called
        // directly, rather than through repeat_elements() itself, so the "Add more" button
        // can be placed after the option rows instead of before them (repeat_elements()
        // always adds it at a fixed point in the call, which would put it above the rows
        // here since $elementobjs would otherwise have to stay empty — our answer's editor
        // and its "correct" radio must stay paired in one addGroup(), which
        // qtype_multichoice's own per-option grade dropdown does not need).
        $mform->registerNoSubmitButton('option_add_fields');
        $repeats = $this->optional_param(
            'option_repeats',
            self::initial_repeat_count((int) ($this->_customdata['answercount'] ?? 0)),
            PARAM_INT
        );
        if ($this->optional_param('option_add_fields', '', PARAM_TEXT) !== '') {
            $repeats += 3;
        }
        $repeats = min($repeats, self::HARD_ANSWER_LIMIT);
        $mform->addElement('hidden', 'option_repeats', $repeats);
        $mform->setType('option_repeats', PARAM_INT);
        $mform->setConstants(['option_repeats' => $repeats]);

        for ($i = 1; $i <= $repeats; $i++) {
            $group = [
                // The radio comes first (and carries its own visible text) so it reads as
                // "mark this one correct, here is its text" instead of trailing silently
                // after the editor with no indication of what it does — the group's own
                // label stays blank (matches every other row) rather than acting as a
                // fallback accessible name for the radio.
                $mform->createElement('radio', 'mccorrect', '', get_string('markcorrect', 'mod_playerpuzzle'), $i),
                $mform->createElement('editor', "optiontext_editor[$i]", '', ['rows' => 2], $editoroptions),
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
            'submit',
            'option_add_fields',
            str_ireplace('{no}', 3, get_string('addmorealternatives', 'mod_playerpuzzle')),
            [],
            false
        );
        $mform->hideIf('option_add_fields', 'qtype', 'eq', 'truefalse');
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
