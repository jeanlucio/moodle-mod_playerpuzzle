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
 * Teacher report: per-student progress, most missed questions, score distribution and
 * completion rate for a PlayerPuzzle activity.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_playerpuzzle\local\report_service;

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('playerpuzzle', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/playerpuzzle:viewreport', $context);

$PAGE->set_url('/mod/playerpuzzle/report.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('report_title', 'mod_playerpuzzle') . ' — ' . format_string($instance->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

$viewerid = (int) $USER->id;
$studentrows = report_service::get_student_rows($instance, $cm, $context, $viewerid);
$missedquestions = report_service::get_most_missed_questions($instance);
$distribution = report_service::get_score_distribution($instance, $cm, $context, $viewerid);
$completion = report_service::get_completion_rate($instance, $cm, $context, $viewerid);

$maxbucketcount = max(1, ...array_column($distribution, 'count'));
foreach ($distribution as &$bucket) {
    $bucket['barpercent'] = (int) round(($bucket['count'] / $maxbucketcount) * 100);
}
unset($bucket);

$templatecontext = [
    'activityname'          => format_string($instance->name, true, ['context' => $context]),
    'activityurl'           => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]))->out(false),
    'backlabel'             => get_string('report_backtoactivity', 'mod_playerpuzzle'),
    'reporttitle'           => get_string('report_title', 'mod_playerpuzzle'),
    'studentrows'           => $studentrows,
    'studentrowsempty'      => empty($studentrows),
    'missedquestions'       => $missedquestions,
    'missedquestionsempty'  => empty($missedquestions),
    'distribution'          => $distribution,
    'completedcount'        => $completion['completed'],
    'completedtotal'        => $completion['total'],
    'completedpercent'      => $completion['percent'],
    'iscampaign'            => $instance->gamemode === PLAYERPUZZLE_GAMEMODE_CAMPAIGN,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playerpuzzle/report', $templatecontext);
echo $OUTPUT->footer();
