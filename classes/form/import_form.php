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
 * Small form to trigger a question bank category import on managequestions.php.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Category picker + submit button for "Import from question bank".
 */
class import_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $categories = $this->_customdata['categories'];

        $options = [];
        foreach ($categories as $category) {
            $options[$category->id] = format_string($category->name);
        }

        $mform->addElement('select', 'categoryid', get_string('importcategory', 'mod_playerpuzzle'), $options);
        $mform->setType('categoryid', PARAM_INT);
        $mform->addElement('static', 'importnotice', '', get_string('importfilenotice', 'mod_playerpuzzle'));

        $this->add_action_buttons(false, get_string('importbutton', 'mod_playerpuzzle'));
    }
}
