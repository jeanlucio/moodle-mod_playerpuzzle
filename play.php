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

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('playerpuzzle', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

$PAGE->set_url('/mod/playerpuzzle/play.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($playerpuzzle->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$ismobile = optional_param('mobile', 0, PARAM_INT) === 1;

if ($ismobile) {
    $PAGE->set_pagelayout('embedded');
} else {
    $PAGE->set_pagelayout('incourse');
    $PAGE->blocks->show_only_fake_blocks();
}

$token = \mod_playerpuzzle\local\engine\security::generate_attempt_token((int)$playerpuzzle->id, (int)$USER->id);

$categoryid = (int)$playerpuzzle->questioncategory;

$questions = \mod_playerpuzzle\local\engine\question_fetcher::get_questions_for_frontend($categoryid, $context);

$spriteurls = [];
for ($i = 0; $i < 7; $i++) {
    $spriteurls[] = $OUTPUT->image_url('sprites/item' . $i, 'mod_playerpuzzle')->out(false);
}

$bossbasename = str_replace('.png', '', $playerpuzzle->bossavatar);
$bossurl = $OUTPUT->image_url('bosses/' . $bossbasename, 'mod_playerpuzzle')->out(false);
$bgurl = $OUTPUT->image_url('bg_landscape', 'mod_playerpuzzle')->out(false);

$jsconfig = [
    'cmid'       => $cm->id,
    'token'      => $token,
    'bosshp'     => $playerpuzzle->bosshp,
    'bossdamage' => $playerpuzzle->bossdamage,
    'bossavatar' => $playerpuzzle->bossavatar,
    'bossurl'    => $bossurl,
    'bgurl'      => $bgurl,
    'spriteurls' => $spriteurls,
    'questions'  => $questions,
    'mobile'     => $ismobile,
    'viewurl'    => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]))->out(false),
];

// Phaser must be loaded globally before the AMD module initialises.
$PAGE->requires->js(new moodle_url('/mod/playerpuzzle/javascript/phaser.min.js'));
$PAGE->requires->js_call_amd('mod_playerpuzzle/game_boot', 'init', []);

$templatedata = [
    'gametitle'  => format_string($playerpuzzle->name),
    'loadingtext' => get_string('loadinggame', 'mod_playerpuzzle'),
    'gameconfig' => json_encode($jsconfig),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playerpuzzle/game_layout', $templatedata);
echo $OUTPUT->footer();
