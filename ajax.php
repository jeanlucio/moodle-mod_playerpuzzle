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
 * AJAX endpoint for PlayerPuzzle game actions (answer validation and progress saving).
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$action = optional_param('action', 'saveprogress', PARAM_ALPHA);

if ($action === 'validateanswer') {
    $cmid       = required_param('cmid', PARAM_INT);
    $questionid = required_param('questionid', PARAM_INT);
    $answerid   = required_param('answerid', PARAM_INT);

    $cm = get_coursemodule_from_id('playerpuzzle', $cmid, 0, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    require_capability('mod/playerpuzzle:view', $context);

    // Verify the question belongs to the category configured for this instance.
    $playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);
    $valid = $DB->record_exists_sql(
        "SELECT 1
           FROM {question_bank_entries} qbe
           JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
          WHERE qv.questionid = :qid
            AND qbe.questioncategoryid = :catid",
        ['qid' => $questionid, 'catid' => $playerpuzzle->questioncategory]
    );

    $correct = $valid && \mod_playerpuzzle\local\engine\question_fetcher::is_answer_correct(
        $questionid,
        $answerid
    );

    header('Content-Type: application/json');
    echo json_encode(['correct' => $correct]);
    exit;
}

$cmid      = required_param('cmid', PARAM_INT);
$coins     = required_param('gold', PARAM_INT);
$isvictory = required_param('victory', PARAM_INT);
$damage    = required_param('damage', PARAM_INT);

$cm = get_coursemodule_from_id('playerpuzzle', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

$inventory = $DB->get_record('playerpuzzle_inventory', ['userid' => $USER->id]);

if ($inventory) {
    $inventory->coins += $coins;
    $inventory->timemodified = time();
    $DB->update_record('playerpuzzle_inventory', $inventory);
} else {
    $newinventory = new stdClass();
    $newinventory->userid = $USER->id;
    $newinventory->coins = $coins;
    $newinventory->swordlevel = 1;
    $newinventory->shieldlevel = 1;
    $newinventory->timecreated = time();
    $newinventory->timemodified = time();
    $DB->insert_record('playerpuzzle_inventory', $newinventory);
}

$totalcoins = $inventory ? $inventory->coins : $coins;

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'message' => get_string('progresssaved', 'mod_playerpuzzle', $totalcoins),
    'totalcoins' => $totalcoins,
]);
