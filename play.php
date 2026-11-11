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

use mod_playerpuzzle\local\constants;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('playerpuzzle', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

// Every form that reaches this page is a POST carrying the sesskey; anything else is a plain
// visit (a bookmark, a reload of the address) and goes back to the Lobby.
if (!data_submitted()) {
    redirect(new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]));
}
require_sesskey();

// The Lobby's "Play Demo" button: a disposable, on-demand practice fight
// with fixed HP, never counted for grade/coins/completion/attempt-limit — see
// game_page_service::build_game_config()'s own $isdemo branch. The attempt-limit and
// retry-cost gates of game_page_service::start_game() are for real progress only, so both are
// skipped for it entirely.
$isdemo = optional_param('isdemo', 0, PARAM_BOOL);

$returnurl = new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]);
\mod_playerpuzzle\local\game_page_service::check_guest_demo_only($isdemo, $returnurl, $context);

// A Campaign attempt needs the student's hero for that Campaign. Without one (the end screen's "Play again"
// once a Campaign ended, or a stale link) the Lobby is where the choice, and the warning a new Campaign
// comes with, belong.
if (
    !$isdemo
    && \mod_playerpuzzle\local\game_page_service::hero_stage($playerpuzzle, (int) $USER->id)
        !== \mod_playerpuzzle\local\game_page_service::HERO_NONE
) {
    redirect($returnurl);
}

// The hero a Single Match or a Demo is fought with, from the choice screen; the Campaign's hero is the
// student's own row. Only a hero from the catalog counts, and the last one chosen for a Single Match is
// remembered, never one for a Demo.
$hero = optional_param('hero', '', PARAM_ALPHANUM);
if (!\mod_playerpuzzle\local\heroes::is_valid($hero)) {
    $hero = array_key_first(\mod_playerpuzzle\local\heroes::CATALOG);
} else if (!$isdemo && $playerpuzzle->gamemode === constants::GAMEMODE_SINGLE) {
    \mod_playerpuzzle\local\last_hero::set((int) $USER->id, $hero);
}

$PAGE->set_url('/mod/playerpuzzle/play.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($playerpuzzle->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$ismobile = optional_param('mobile', 0, PARAM_INT) === 1;

// Student's difficulty choice from the Lobby. Used when a fresh attempt is created. Resuming an attempt keeps its own
// difficulty, except when the Lobby marked the value as a choice (difficultychoice) and the attempt's phase has not
// started yet; the pages that reload the game after a win or a loss send the value without the mark. Coerced to
// a known value inside security::clean_difficulty().
$difficulty = optional_param('difficulty', constants::DIFFICULTY_NORMAL, PARAM_ALPHA);
$chosendifficulty = optional_param('difficultychoice', 0, PARAM_INT) === 1 ? $difficulty : null;

// Previously switched to a chromeless 'embedded' layout for mobile devices, opened in a new
// tab by the Lobby's own form — removed because the game now always stays in the same window,
// in the normal course layout, using the same Expand/Collapse fullscreen toggle as desktop
// (see hud.js::setupButtons()) instead of a device-specific windowing trick.
// $ismobile is still passed into the JS game config below —
// it now only drives a CSS sizing tweak for the question modal's answer buttons.
$PAGE->set_pagelayout('incourse');
$PAGE->blocks->show_only_fake_blocks();

// For a real attempt this also runs the gates that decide whether a new one may begin (attempt limit,
// cooldown, retry cost), under the lock that stops two simultaneous requests from both passing them.
$jsconfig = \mod_playerpuzzle\local\game_page_service::start_game(
    $cm,
    $playerpuzzle,
    $context,
    (int) $USER->id,
    $ismobile,
    $difficulty,
    $isdemo,
    $returnurl,
    $hero,
    $chosendifficulty
);

$PAGE->requires->js_call_amd('mod_playerpuzzle/game_boot', 'init', []);

$templatedata = [
    'loadingtext' => get_string('loadinggame', 'mod_playerpuzzle'),
    'gameconfig' => json_encode($jsconfig),
    'opponenthelp' => [
        'closelabel' => get_string('close', 'mod_playerpuzzle'),
    ] + \mod_playerpuzzle\local\opponent_card::for_fight($jsconfig),
    'giveup' => \mod_playerpuzzle\local\game_page_service::giveup_dialog(!empty($jsconfig['isdemo'])),
    'howtoplay' => \mod_playerpuzzle\local\howtoplay::context($playerpuzzle, is_guest($context, (int) $USER->id)),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playerpuzzle/game_layout', $templatedata);
echo $OUTPUT->footer();
