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
 * The main view page for the PlayerPuzzle activity (The Lobby/Shop).
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Get the Course Module ID from the URL.
$id = required_param('id', PARAM_INT);

// Retrieve basic Moodle data.
$cm = get_coursemodule_from_id('playerpuzzle', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

// Security: Require the user to be logged in and enrolled in the course.
require_login($course, true, $cm);

// Load the context and check permissions.
$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

// Configure the page setup.
$PAGE->set_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($playerpuzzle->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Output starts here.
echo $OUTPUT->header();

// Display the phase title.
echo $OUTPUT->heading(format_string($playerpuzzle->name));

// Display the introduction if the teacher wrote one.
if (trim($playerpuzzle->intro) !== '') {
    echo $OUTPUT->box(format_module_intro('playerpuzzle', $playerpuzzle, $cm->id), 'generalbox', 'intro');
}

$ismobile = core_useragent::is_ios() || core_useragent::is_webkit_android();
$playparams = ['id' => $cm->id];
if ($ismobile) {
    $playparams['mobile'] = 1;
}

$templatedata = [
    'welcomemsg' => get_string('lobbywelcome', 'mod_playerpuzzle'),
    'playurl' => (new moodle_url('/mod/playerpuzzle/play.php', $playparams))->out(false),
    'playtext' => get_string('playgame', 'mod_playerpuzzle'),
    'sesskey' => sesskey(),
    'ismobile' => $ismobile,
];

echo $OUTPUT->render_from_template('mod_playerpuzzle/view_lobby', $templatedata);

echo $OUTPUT->footer();
