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

use mod_playerpuzzle\form\import_form;
use mod_playerpuzzle\form\question_form;
use mod_playerpuzzle\local\question_bank_sync;
use mod_playerpuzzle\local\question_editor_files;
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
        questions_repository::delete_question($questionid, $context);
    }
    redirect($url, get_string('questiondeleted', 'mod_playerpuzzle'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'approve' && $questionid) {
    require_sesskey();
    if (questions_repository::get_question($questionid, (int) $instance->id)) {
        questions_repository::approve_question($questionid);
    }
    redirect($url, get_string('questionapproved', 'mod_playerpuzzle'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
if ($bulkaction !== '') {
    require_sesskey();
    $bulkids = array_values(array_filter(array_map('intval', optional_param_array('bulk_ids', [], PARAM_INT))));
    if ($bulkaction === 'delete') {
        questions_repository::delete_questions_bulk($bulkids, (int) $instance->id, $context);
        redirect($url, get_string('bulkdeleted', 'mod_playerpuzzle'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($bulkaction === 'approve') {
        questions_repository::approve_questions_bulk($bulkids, (int) $instance->id);
        redirect($url, get_string('bulkapproved', 'mod_playerpuzzle'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$sort = optional_param('sort', 'id', PARAM_ALPHA);
$dir = optional_param('dir', 'DESC', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);

$allowedsorts = ['id', 'qtype', 'source', 'approved'];
if (!in_array($sort, $allowedsorts, true)) {
    $sort = 'id';
}
$dir = strtoupper($dir);
if (!in_array($dir, ['ASC', 'DESC'], true)) {
    $dir = 'DESC';
}

$importablecategories = question_bank_sync::get_importable_categories($cm);
$importform = new import_form($url, ['categories' => $importablecategories]);

if (!empty($importablecategories) && ($importdata = $importform->get_data())) {
    $stats = question_bank_sync::sync_from_category($cm, (int) $instance->id, (int) $importdata->categoryid);
    redirect(
        $url,
        get_string('importresult', 'mod_playerpuzzle', $stats),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Fetched here, before constructing the form, so its answer count can size the
// multichoice repeat_elements() block on the form's first (non-postback) render — the
// value only matters for that first render; a postback (an "Add more" click or the real
// submit) always carries its own repeat count in the option_repeats hidden field instead.
$editquestion = null;
if ($action === 'edit' && $questionid) {
    $editquestion = questions_repository::get_question($questionid, (int) $instance->id);
    if (!$editquestion) {
        redirect($url);
    }
}

$mform = new question_form($url, [
    'context' => $context,
    'answercount' => $editquestion ? count($editquestion->answers) : 0,
]);

if ($mform->is_cancelled()) {
    redirect($url);
} else if ($data = $mform->get_data()) {
    if ($data->qtype === 'truefalse') {
        $answers = [
            ['text' => get_string('true', 'qtype_truefalse'), 'iscorrect' => $data->tfcorrect === 'true'],
            ['text' => get_string('false', 'qtype_truefalse'), 'iscorrect' => $data->tfcorrect === 'false'],
        ];
        $optionslots = null;
    } else {
        $answers = [];
        $optionslots = [];
        foreach ($data->optiontext_editor as $slot => $editorvalue) {
            if (trim(strip_tags((string) $editorvalue['text'])) === '' && !str_contains($editorvalue['text'], '<img')) {
                continue;
            }
            $answers[] = [
                'text' => $editorvalue['text'],
                'format' => (int) $editorvalue['format'],
                'iscorrect' => (int) $data->mccorrect === (int) $slot,
            ];
            $optionslots[] = $slot;
        }
    }

    $isedit = !empty($data->qid) && questions_repository::get_question((int) $data->qid, (int) $instance->id);
    if ($isedit) {
        // The real questiontext is written a second time below, once the editor's draft
        // files have somewhere real to attach to — this first write only carries the
        // qtype/hint/answer-shape change through, keeping update_question() as the single
        // place that knows how to safely replace the answer rows (and purge their stale
        // file areas).
        questions_repository::update_question(
            (int) $data->qid,
            $data->qtype,
            $data->questiontext_editor['text'],
            $data->hint,
            $answers,
            $context
        );
        $questionid = (int) $data->qid;
    } else {
        $questionid = questions_repository::add_question(
            (int) $instance->id,
            $data->qtype,
            $data->questiontext_editor['text'],
            $data->hint,
            $answers,
            (int) $USER->id
        );
    }

    $qcontent = question_editor_files::finalize($data->questiontext_editor, $context, 'questiontext', $questionid);
    questions_repository::set_question_text($questionid, $qcontent['text'], $qcontent['format']);

    if ($optionslots !== null) {
        $question = questions_repository::get_question($questionid, (int) $instance->id);
        foreach ($question->answers as $k => $answer) {
            $acontent = question_editor_files::finalize(
                $data->optiontext_editor[$optionslots[$k]],
                $context,
                'answertext',
                (int) $answer->id
            );
            questions_repository::set_answer_text((int) $answer->id, $acontent['text'], $acontent['format']);
        }
    }

    redirect($url, get_string('questionsaved', 'mod_playerpuzzle'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

// A failed validation on submit is not itself an 'add'/'edit' GET request (the form posts
// back to this same URL without an action query param), so is_submitted() must be checked
// too — otherwise a validation error would silently fall through to the list below instead
// of redisplaying the form with the error messages.
if ($action === 'add' || $action === 'edit' || $mform->is_submitted()) {
    if ($action === 'edit' && $editquestion && !$mform->is_submitted()) {
        $question = $editquestion;

        $formdata = new stdClass();
        $formdata->qid = $question->id;
        $formdata->cmid = $cm->id;
        $formdata->qtype = $question->qtype;
        $formdata->hint = $question->hint ?? '';
        $formdata->questiontext_editor = question_editor_files::prepare(
            $question->questiontext,
            (int) $question->questiontextformat,
            $question->id,
            $context,
            'questiontext'
        );

        if ($question->qtype === 'truefalse') {
            foreach ($question->answers as $answer) {
                if ((int) $answer->iscorrect === 1) {
                    $formdata->tfcorrect = $answer->answertext === get_string('true', 'qtype_truefalse') ? 'true' : 'false';
                }
            }
        } else {
            $optiontexteditors = [];
            $i = 1;
            foreach ($question->answers as $answer) {
                $optiontexteditors[$i] = question_editor_files::prepare(
                    $answer->answertext,
                    (int) $answer->answerformat,
                    (int) $answer->id,
                    $context,
                    'answertext'
                );
                if ((int) $answer->iscorrect === 1) {
                    $formdata->mccorrect = $i;
                }
                $i++;
            }
            // Pad any remaining rendered slots (the form always shows at least 5) with
            // blank editors, same as a brand new question below — otherwise a question
            // with fewer than 5 saved answers would leave its unused slots without the
            // draft file area prepare() sets up.
            for (; $i <= question_form::initial_repeat_count(count($question->answers)); $i++) {
                $optiontexteditors[$i] = question_editor_files::prepare('', FORMAT_HTML, null, $context, 'answertext');
            }
            $formdata->optiontext_editor = $optiontexteditors;
        }

        $mform->set_data($formdata);
    } else if (!$mform->is_submitted()) {
        $formdata = ['cmid' => $cm->id];
        $formdata['questiontext_editor'] = question_editor_files::prepare('', FORMAT_HTML, null, $context, 'questiontext');
        for ($i = 1; $i <= question_form::initial_repeat_count(0); $i++) {
            $formdata['optiontext_editor'][$i] = question_editor_files::prepare('', FORMAT_HTML, null, $context, 'answertext');
        }
        $mform->set_data($formdata);
    }

    $mform->display();
} else {
    echo $OUTPUT->heading(format_string($instance->name, true, ['context' => $context]));

    // The import-from-bank option lives above the question list, not after it — a teacher
    // scanning the page top-to-bottom sees it before deciding whether to add/generate
    // questions manually, instead of risking it going unnoticed at the very end of a long,
    // paginated list (reported live, 17/09/2026).
    echo $OUTPUT->heading(get_string('importheader', 'mod_playerpuzzle'), 3);
    if (empty($importablecategories)) {
        echo $OUTPUT->notification(get_string('noimportablecategories', 'mod_playerpuzzle'), 'info');
    } else {
        $importform->display();
    }

    $listcontext = question_list_service::build_list_context($instance, $cmid, $OUTPUT, $context, $sort, $dir, $page);
    if ($listcontext['aiavailable']) {
        $PAGE->requires->js_call_amd('mod_playerpuzzle/ai_generate', 'init', [$cmid]);
    }
    $PAGE->requires->js_call_amd('mod_playerpuzzle/managequestions', 'init');
    echo $OUTPUT->render_from_template('mod_playerpuzzle/managequestions', $listcontext);
}

echo $OUTPUT->footer();
