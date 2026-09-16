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
 * Manage PlayerPuzzle's own question bank for one activity instance.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_playerpuzzle\form\question_form;
use mod_playerpuzzle\local\question_list_service;
use mod_playerpuzzle\local\questions_repository;

require(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$questionid = optional_param('qid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('playerpuzzle', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:managequestions', $context);

$url = new moodle_url('/mod/playerpuzzle/managequestions.php', ['id' => $cmid]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('managequestions', 'mod_playerpuzzle'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

if ($action === 'delete' && $questionid) {
    require_sesskey();
    if (questions_repository::get_question($questionid, (int) $instance->id)) {
        questions_repository::delete_question($questionid);
    }
    redirect($url, get_string('questiondeleted', 'mod_playerpuzzle'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$mform = new question_form($url);

if ($mform->is_cancelled()) {
    redirect($url);
} else if ($data = $mform->get_data()) {
    if ($data->qtype === 'truefalse') {
        $answers = [
            ['text' => get_string('true', 'qtype_truefalse'), 'iscorrect' => $data->tfcorrect === 'true'],
            ['text' => get_string('false', 'qtype_truefalse'), 'iscorrect' => $data->tfcorrect === 'false'],
        ];
    } else {
        $answers = [];
        foreach ($data->optiontext as $index => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $answers[] = ['text' => $text, 'iscorrect' => (int) $data->mccorrect === (int) $index];
        }
    }

    if (!empty($data->qid)) {
        if (questions_repository::get_question((int) $data->qid, (int) $instance->id)) {
            questions_repository::update_question(
                (int) $data->qid,
                $data->qtype,
                $data->questiontext,
                $data->hint,
                $answers
            );
        }
    } else {
        questions_repository::add_question(
            (int) $instance->id,
            $data->qtype,
            $data->questiontext,
            $data->hint,
            $answers,
            (int) $USER->id
        );
    }

    redirect($url, get_string('questionsaved', 'mod_playerpuzzle'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

// A failed validation on submit is not itself an 'add'/'edit' GET request (the form posts
// back to this same URL without an action query param), so is_submitted() must be checked
// too — otherwise a validation error would silently fall through to the list below instead
// of redisplaying the form with the error messages.
if ($action === 'add' || $action === 'edit' || $mform->is_submitted()) {
    if ($action === 'edit' && $questionid && !$mform->is_submitted()) {
        $question = questions_repository::get_question($questionid, (int) $instance->id);
        if (!$question) {
            redirect($url);
        }

        $formdata = new stdClass();
        $formdata->qid = $question->id;
        $formdata->cmid = $cm->id;
        $formdata->qtype = $question->qtype;
        $formdata->questiontext = $question->questiontext;
        $formdata->hint = $question->hint ?? '';

        if ($question->qtype === 'truefalse') {
            foreach ($question->answers as $answer) {
                if ((int) $answer->iscorrect === 1) {
                    $formdata->tfcorrect = $answer->answertext === get_string('true', 'qtype_truefalse') ? 'true' : 'false';
                }
            }
        } else {
            $optiontext = [];
            $i = 1;
            foreach ($question->answers as $answer) {
                $optiontext[$i] = $answer->answertext;
                if ((int) $answer->iscorrect === 1) {
                    $formdata->mccorrect = $i;
                }
                $i++;
            }
            $formdata->optiontext = $optiontext;
        }

        $mform->set_data($formdata);
    } else if (!$mform->is_submitted()) {
        $mform->set_data(['cmid' => $cm->id]);
    }

    $mform->display();
} else {
    $listcontext = question_list_service::build_list_context($instance, $cmid, $OUTPUT);
    echo $OUTPUT->render_from_template('mod_playerpuzzle/managequestions', $listcontext);
}

echo $OUTPUT->footer();
