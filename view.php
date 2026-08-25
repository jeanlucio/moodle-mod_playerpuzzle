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
require_once(__DIR__ . '/lib.php');

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

// PlayerHUD balances: coins, Sword level, Shield level. Only the items the teacher actually
// configured are shown — an unconfigured item (id 0) has nothing meaningful to display.
if (\mod_playerpuzzle\local\hud_service::is_available_for_course((int) $course->id)) {
    $blockinstanceid = \mod_playerpuzzle\local\hud_service::get_block_instance_id((int) $course->id);

    if ((int) $playerpuzzle->hud_coin_item > 0) {
        $balance = \mod_playerpuzzle\local\hud_service::get_upgrade_level(
            $blockinstanceid,
            (int) $USER->id,
            (int) $playerpuzzle->hud_coin_item
        );
        $templatedata['coinstext'] = get_string('lobby_coinbalance', 'mod_playerpuzzle', $balance);
    }

    if ((int) $playerpuzzle->hud_sword_item > 0) {
        $swordlevel = \mod_playerpuzzle\local\hud_service::get_upgrade_level(
            $blockinstanceid,
            (int) $USER->id,
            (int) $playerpuzzle->hud_sword_item
        );
        $templatedata['swordtext'] = get_string('lobby_swordlevel', 'mod_playerpuzzle', $swordlevel);
    }

    if ((int) $playerpuzzle->hud_shield_item > 0) {
        $shieldlevel = \mod_playerpuzzle\local\hud_service::get_upgrade_level(
            $blockinstanceid,
            (int) $USER->id,
            (int) $playerpuzzle->hud_shield_item
        );
        $templatedata['shieldtext'] = get_string('lobby_shieldlevel', 'mod_playerpuzzle', $shieldlevel);
    }
}
$templatedata['hasstats'] = isset($templatedata['coinstext'])
    || isset($templatedata['swordtext'])
    || isset($templatedata['shieldtext']);

// Current Campaign progress: the most recently started in-progress attempt, if any. Single
// Match mode has no levels/phases to show, so this is skipped entirely for it.
if ($playerpuzzle->gamemode === PLAYERPUZZLE_GAMEMODE_CAMPAIGN) {
    $inprogress = $DB->get_records(
        'playerpuzzle_attempts',
        ['playerpuzzleid' => $playerpuzzle->id, 'userid' => $USER->id, 'status' => 'inprogress'],
        'timecreated DESC',
        'id, currentlevel, currentphase',
        0,
        1
    );
    $attempt = reset($inprogress);
    if ($attempt) {
        $templatedata['progresstext'] = get_string('lobby_currentprogress', 'mod_playerpuzzle', (object) [
            'level' => $attempt->currentlevel,
            'phase' => $attempt->currentphase,
        ]);
    }
}

// Minimum Questions notice (§4.6): the match can require a number of answered questions
// before it can end in victory, reviving the boss if needed — this must be visible to the
// student before they start, or the revive would look like a bug.
if ((int) $playerpuzzle->minquestions > 0) {
    $templatedata['minquestionstext'] = get_string(
        'lobby_minquestions_notice',
        'mod_playerpuzzle',
        (int) $playerpuzzle->minquestions
    );
}

echo $OUTPUT->render_from_template('mod_playerpuzzle/view_lobby', $templatedata);

echo $OUTPUT->footer();
