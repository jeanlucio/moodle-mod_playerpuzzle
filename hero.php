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
 * The hero choice screen: the first screen of a Campaign until the student has a hero, and the step between
 * the Lobby's Play button and the match for a Single Match or a Demo.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_playerpuzzle\local\game_page_service;
use mod_playerpuzzle\local\hero_page_service;
use mod_playerpuzzle\local\heroes;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', hero_page_service::MODE_CAMPAIGN, PARAM_ALPHA);

$cm = get_coursemodule_from_id('playerpuzzle', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

$returnurl = new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]);

// A visitor only plays the Demo, and nobody picks a hero that is not theirs to pick: in the wrong game mode,
// or after the Campaign already has one. The Lobby is where those students belong.
$userid = (int) $USER->id;
$isvisitor = is_guest($context, $userid);
$allowed = in_array($mode, hero_page_service::MODES, true)
    && (!$isvisitor || $mode === hero_page_service::MODE_DEMO)
    && hero_page_service::is_available($playerpuzzle, $userid, $mode);
if (!$allowed) {
    redirect($returnurl);
}

// A visit is a plain page; only a form that carries the sesskey does anything.
$posted = data_submitted() && confirm_sesskey();
$hero = optional_param('hero', '', PARAM_ALPHANUM);
$confirmed = optional_param('confirmnewcampaign', 0, PARAM_BOOL);
$error = '';

if ($posted && $mode === hero_page_service::MODE_CAMPAIGN && $hero !== '') {
    if (!heroes::is_valid($hero)) {
        $error = 'hero_choose_error';
    } else if (
        game_page_service::hero_stage($playerpuzzle, $userid) === game_page_service::HERO_NEW
        && !$confirmed
    ) {
        // A new Campaign empties the student's PuzzleCoin and stock, so it is only started from the
        // Lobby's own button, after its warning.
        redirect($returnurl);
    } else {
        game_page_service::choose_campaign_hero($playerpuzzle, $userid, $hero);
        redirect($returnurl);
    }
}

// What the choice carries on to the next page. The difficulty and the mobile flag come from the Lobby's own
// form, and are coerced where they are used, not here.
$carry = [];
$carried = ['difficulty' => PARAM_ALPHA, 'mobile' => PARAM_INT, 'confirmnewcampaign' => PARAM_BOOL];
if ($mode === hero_page_service::MODE_DEMO) {
    // The mark that the difficulty was chosen on purpose: a Demo left half played is started over under it.
    $carried['difficultychoice'] = PARAM_INT;
}
foreach ($carried as $name => $type) {
    $value = optional_param($name, null, $type);
    if ($value !== null && $value !== '') {
        $carry[$name] = $value;
    }
}

$PAGE->set_url('/mod/playerpuzzle/hero.php', ['id' => $cm->id, 'mode' => $mode]);
$PAGE->set_title(format_string($playerpuzzle->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'mod_playerpuzzle/hero_select',
    hero_page_service::build_page_data($cm, $playerpuzzle, $userid, $mode, $carry, $error)
);
echo $OUTPUT->footer();
