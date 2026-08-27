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
 * The gameplay controller page.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('playerpuzzle', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]));
}
require_sesskey();

$returnurl = new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]);
\mod_playerpuzzle\local\game_page_service::check_attempt_limit($playerpuzzle, (int) $USER->id, $returnurl);

$PAGE->set_url('/mod/playerpuzzle/play.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($playerpuzzle->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$ismobile = optional_param('mobile', 0, PARAM_INT) === 1;

// Previously switched to a chromeless 'embedded' layout for mobile devices, opened in a new
// tab by the Lobby's own form — removed per explicit user feedback (27/08/2026): the game now
// always stays in the same window, in the normal course layout, using the same
// Expandir/Encolher fullscreen toggle as desktop (see ui.js::setupButtons()) instead of a
// device-specific windowing trick. $ismobile is still passed into the JS game config below —
// it now only drives a CSS sizing tweak for the question modal's answer buttons.
$PAGE->set_pagelayout('incourse');
$PAGE->blocks->show_only_fake_blocks();

$jsconfig = \mod_playerpuzzle\local\game_page_service::build_game_config(
    $cm,
    $playerpuzzle,
    $context,
    (int) $USER->id,
    $ismobile
);

// Phaser itself is loaded dynamically from inside game_boot.js (mirrors
// filter_mathjaxloader's loadMathJax()), not queued here as a static <script> — see the
// loadPhaser() comment in amd/src/game_boot.js for why.
$PAGE->requires->js_call_amd('mod_playerpuzzle/game_boot', 'init', []);

$templatedata = [
    'gametitle'  => format_string($playerpuzzle->name),
    'loadingtext' => get_string('loadinggame', 'mod_playerpuzzle'),
    'gameconfig' => json_encode($jsconfig),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playerpuzzle/game_layout', $templatedata);
echo $OUTPUT->footer();
