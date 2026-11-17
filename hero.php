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
 * The hero choice screen: the step between the Lobby's Play button and the match, for a Campaign that still
 * needs its hero, a Single Match and a Demo. The form goes on to play.php, which saves a Campaign's choice.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_playerpuzzle\local\game_page_service;
use mod_playerpuzzle\local\hero_page_service;

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

// A running cooldown is said before the hero is chosen rather than after, when the choice would be wasted.
$waits = $mode !== hero_page_service::MODE_DEMO && !$isvisitor
    && game_page_service::cooldown_remaining($playerpuzzle, $userid) > 0;
if ($waits) {
    redirect(new moodle_url($returnurl, ['refused' => game_page_service::cooldown_code($playerpuzzle)]));
}

// The error play.php sends back when what came was not one of the heroes.
$error = optional_param('heroerror', 0, PARAM_BOOL) ? 'hero_choose_error' : '';

// What the choice carries on to the next page. The difficulty and the mobile flag come from the Lobby's own
// form, and are coerced where they are used, not here.
$carry = [];
$carried = ['difficulty' => PARAM_ALPHA, 'mobile' => PARAM_INT, 'confirmnewcampaign' => PARAM_BOOL];
if ($mode !== hero_page_service::MODE_SINGLE) {
    // The mark that the difficulty was chosen on purpose: a Demo left half played is started over under it, and
    // a Campaign phase nothing has happened in yet takes it.
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
