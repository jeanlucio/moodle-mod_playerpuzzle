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
        global $COURSE;

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

        $mform->addElement('header', 'gameplayheader', get_string('gameplayheader', 'mod_playerpuzzle'));
        $mform->setExpanded('gameplayheader');

        $mform->addElement('text', 'basestudenthp', get_string('basestudenthp', 'mod_playerpuzzle'));
        $mform->setType('basestudenthp', PARAM_INT);
        $mform->setDefault('basestudenthp', 100);
        $mform->addHelpButton('basestudenthp', 'basestudenthp', 'mod_playerpuzzle');

        $leveloptions = [];
        for ($i = 1; $i <= 10; $i++) {
            $leveloptions[$i] = $i;
        }
        $mform->addElement('select', 'maxlevels', get_string('maxlevels', 'mod_playerpuzzle'), $leveloptions);
        $mform->setType('maxlevels', PARAM_INT);
        $mform->setDefault('maxlevels', 1);
        $mform->addHelpButton('maxlevels', 'maxlevels', 'mod_playerpuzzle');
        $mform->hideIf('maxlevels', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_SINGLE);

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
        $mform->addHelpButton('max_single_matches', 'max_single_matches', 'mod_playerpuzzle');
        $mform->hideIf('max_single_matches', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_CAMPAIGN);

        $cooldowngroup = [];
        $cooldowngroup[] = $mform->createElement('text', 'cooldown_amount', '', ['size' => 5]);
        $cooldowngroup[] = $mform->createElement(
            'select',
            'cooldown_unit',
            '',
            [
                'minutes' => get_string('cooldown_unit_minutes', 'mod_playerpuzzle'),
                'hours'   => get_string('cooldown_unit_hours', 'mod_playerpuzzle'),
                'days'    => get_string('cooldown_unit_days', 'mod_playerpuzzle'),
            ]
        );
        $mform->addGroup(
            $cooldowngroup,
            'cooldowngroup',
            get_string('cooldown_label', 'mod_playerpuzzle'),
            [' '],
            false
        );
        $mform->setType('cooldown_amount', PARAM_INT);
        $mform->setType('cooldown_unit', PARAM_ALPHA);
        $mform->setDefault('cooldown_amount', 0);
        $mform->setDefault('cooldown_unit', 'minutes');
        $mform->hideIf('cooldowngroup', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_CAMPAIGN);

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
        $mform->setDefault('basebosshp', 100);

        $mform->addElement('text', 'bossdamage', get_string('bossdamage', 'mod_playerpuzzle'));
        $mform->setType('bossdamage', PARAM_INT);
        $mform->setDefault('bossdamage', 10);
        $mform->addHelpButton('bossdamage', 'bossdamage', 'mod_playerpuzzle');

        $mform->addElement('text', 'coingain', get_string('coingain', 'mod_playerpuzzle'));
        $mform->setType('coingain', PARAM_INT);
        $mform->setDefault('coingain', 10);
        $mform->addHelpButton('coingain', 'coingain', 'mod_playerpuzzle');

        // Which Moodle question bank category (if any) to import from lives on
        // managequestions.php now, not here — this field only keeps the last-imported
        // category id around between imports (see db/install.xml's comment on the column).
        $mform->addElement('hidden', 'questioncategory', 0);
        $mform->setType('questioncategory', PARAM_INT);

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

        $mform->addElement('text', 'timelimit', get_string('timelimit', 'mod_playerpuzzle'));
        $mform->setType('timelimit', PARAM_INT);
        $mform->setDefault('timelimit', 0);
        $mform->addHelpButton('timelimit', 'timelimit', 'mod_playerpuzzle');

        $mform->addElement('text', 'maxattempts', get_string('maxattempts', 'mod_playerpuzzle'));
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);
        $mform->addHelpButton('maxattempts', 'maxattempts', 'mod_playerpuzzle');
        $mform->hideIf('maxattempts', 'gamemode', 'eq', PLAYERPUZZLE_GAMEMODE_SINGLE);

        $mform->addElement('advcheckbox', 'show_ranking', get_string('show_ranking', 'mod_playerpuzzle'));
        $mform->setType('show_ranking', PARAM_INT);
        $mform->setDefault('show_ranking', 1);
        $mform->addHelpButton('show_ranking', 'show_ranking', 'mod_playerpuzzle');

        $mform->addElement('advcheckbox', 'hints_enabled', get_string('hints_enabled', 'mod_playerpuzzle'));
        $mform->setType('hints_enabled', PARAM_INT);
        $mform->setDefault('hints_enabled', 1);
        $mform->addHelpButton('hints_enabled', 'hints_enabled', 'mod_playerpuzzle');

        $this->add_hud_elements($mform, (int) $COURSE->id);

        $this->standard_grading_coursemodule_elements();

        // Placed here, right after the grade elements, mirroring mod_quiz's own
        // "Grading method" field — the same concept (combining several attempts/matches
        // into one final grade) belongs in the Grade section, not among the gameplay
        // settings above.
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
        $mform->hideIf('grademethod', 'grade[modgrade_type]', 'eq', 'none');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Adds the PlayerHUD integration section: which item a student can transfer from into
     * PuzzleCoin, plus the retry-cost and win-grant items. Only rendered when a
     * block_playerhud instance exists in this course. All fields list the same set of
     * items — PlayerHUD's own auto-generated PlayerCoin item shows up as an ordinary option
     * here, like any other item the teacher could pick or create.
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

        if (!empty($data['completionattemptsenabled']) && (int) $data['completionattempts'] < 1) {
            $errors['completionattemptsgroup'] = get_string('error_completionattempts', 'mod_playerpuzzle');
        }

        if (!empty($data['completionwinsenabled']) && (int) $data['completionwins'] < 1) {
            $errors['completionwinsgroup'] = get_string('error_completionwins', 'mod_playerpuzzle');
        }

        if ((int) ($data['cooldown_amount'] ?? 0) < 0) {
            $errors['cooldowngroup'] = get_string('error_cooldown', 'mod_playerpuzzle');
        }

        return $errors;
    }

    /**
     * Adds custom completion rules to Moodle completion section.
     *
     * @return array
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        $attemptsgroup = [];
        $attemptsgroup[] = $mform->createElement('checkbox', 'completionattemptsenabled', '', '');
        $attemptsgroup[] = $mform->createElement('text', 'completionattempts', '', ['size' => 3]);
        $mform->addGroup(
            $attemptsgroup,
            'completionattemptsgroup',
            get_string('completionattemptsgroup', 'mod_playerpuzzle'),
            [' '],
            false
        );
        $mform->setType('completionattempts', PARAM_INT);
        $mform->setDefault('completionattempts', 1);
        $mform->disabledIf('completionattempts', 'completionattemptsenabled', 'notchecked');

        $winsgroup = [];
        $winsgroup[] = $mform->createElement('checkbox', 'completionwinsenabled', '', '');
        $winsgroup[] = $mform->createElement('text', 'completionwins', '', ['size' => 3]);
        $mform->addGroup(
            $winsgroup,
            'completionwinsgroup',
            get_string('completionwinsgroup', 'mod_playerpuzzle'),
            [' '],
            false
        );
        $mform->setType('completionwins', PARAM_INT);
        $mform->setDefault('completionwins', 1);
        $mform->disabledIf('completionwins', 'completionwinsenabled', 'notchecked');

        return ['completionattemptsgroup', 'completionwinsgroup'];
    }

    /**
     * Returns whether at least one completion rule is enabled.
     *
     * @param array $data Form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        return (!empty($data['completionattemptsenabled']) && (int) $data['completionattempts'] > 0)
            || (!empty($data['completionwinsenabled']) && (int) $data['completionwins'] > 0);
    }

    /**
     * Pre-checks the completion rule checkboxes when the stored instance already has a
     * value configured for them, mirroring how Moodle core does this for its own
     * completionusegrade/completionpassgrade checkboxes.
     *
     * @param array $defaultvalues Default form values.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
        parent::data_preprocessing($defaultvalues);

        if (!empty($defaultvalues['completionattempts'])) {
            $defaultvalues['completionattemptsenabled'] = 1;
        }
        if (!empty($defaultvalues['completionwins'])) {
            $defaultvalues['completionwinsenabled'] = 1;
        }

        if (isset($defaultvalues['cooldown_seconds'])) {
            $seconds = (int) $defaultvalues['cooldown_seconds'];
            if ($seconds === 0) {
                $defaultvalues['cooldown_amount'] = 0;
                $defaultvalues['cooldown_unit']   = 'minutes';
            } else if ($seconds % 86400 === 0) {
                $defaultvalues['cooldown_amount'] = $seconds / 86400;
                $defaultvalues['cooldown_unit']   = 'days';
            } else if ($seconds % 3600 === 0) {
                $defaultvalues['cooldown_amount'] = $seconds / 3600;
                $defaultvalues['cooldown_unit']   = 'hours';
            } else {
                $defaultvalues['cooldown_amount'] = max(1, (int) round($seconds / 60));
                $defaultvalues['cooldown_unit']   = 'minutes';
            }
        }
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
