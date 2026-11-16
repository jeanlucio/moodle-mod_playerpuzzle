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
 * Giving up a Campaign: the Lobby's button comes here for the confirmation, and the confirmed form ends it.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_playerpuzzle\local\campaign_giveup;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('playerpuzzle', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

$userid = (int) $USER->id;
$returnurl = new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]);

// A visitor has no Campaign, and nobody gives up one that is not under way: the Lobby is where they belong.
if (is_guest($context, $userid) || !campaign_giveup::is_available($playerpuzzle, $userid)) {
    redirect($returnurl);
}

// Only the confirmed form changes anything; the Lobby's own button just asks.
if (optional_param('confirm', 0, PARAM_BOOL) && data_submitted()) {
    require_sesskey();
    campaign_giveup::give_up($playerpuzzle, $cm, $userid);
    redirect($returnurl);
}

$PAGE->set_url('/mod/playerpuzzle/giveupcampaign.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($playerpuzzle->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

$title = get_string('lobby_giveupcampaign_button', 'mod_playerpuzzle');
$message = get_string(
    campaign_giveup::is_last_campaign($playerpuzzle, $userid)
        ? 'lobby_giveupcampaign_confirmlast'
        : 'lobby_giveupcampaign_confirm',
    'mod_playerpuzzle'
);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->confirm(
    $message,
    new single_button(new moodle_url('/mod/playerpuzzle/giveupcampaign.php', ['id' => $cm->id, 'confirm' => 1]), $title, 'post'),
    $returnurl
);
echo $OUTPUT->footer();
