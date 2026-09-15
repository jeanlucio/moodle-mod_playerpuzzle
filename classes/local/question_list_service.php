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
 * Service to build the template context for the question management listing.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use confirm_action;
use moodle_url;
use pix_icon;
use renderer_base;
use stdClass;

/**
 * Builds the mod_playerpuzzle/managequestions template context.
 */
class question_list_service {
    /**
     * Builds the template context for the question listing screen.
     *
     * Delete links are real core_renderer::action_icon() calls with a confirm_action, not a
     * plain data-confirm attribute on an anchor — the latter is inert without its own JS
     * wiring (no core behaviour binds to it), which would make Cancel/Confirm a silent no-op.
     *
     * @param stdClass $instance The activity instance.
     * @param int $cmid The course module id.
     * @param renderer_base $output Used to render the edit/delete icons.
     * @return array Template context for mod_playerpuzzle/managequestions.
     */
    public static function build_list_context(stdClass $instance, int $cmid, renderer_base $output): array {
        $questions = questions_repository::get_questions_for_instance((int) $instance->id);

        $rows = [];
        foreach ($questions as $question) {
            $editurl = new moodle_url(
                '/mod/playerpuzzle/managequestions.php',
                ['id' => $cmid, 'action' => 'edit', 'qid' => $question->id]
            );
            $deleteurl = new moodle_url(
                '/mod/playerpuzzle/managequestions.php',
                ['id' => $cmid, 'action' => 'delete', 'qid' => $question->id, 'sesskey' => sesskey()]
            );

            $answerspreview = array_map(
                fn(stdClass $answer): string => ((int) $answer->iscorrect === 1 ? '✓ ' : '') . $answer->answertext,
                $question->answers
            );

            $rows[] = [
                'questiontext' => format_text($question->questiontext, FORMAT_PLAIN),
                'qtypelabel' => get_string('qtype_' . $question->qtype, 'mod_playerpuzzle'),
                'answerspreview' => implode(' · ', $answerspreview),
                'sourcelabel' => get_string('source_' . $question->source, 'mod_playerpuzzle'),
                'isunapproved' => (int) $question->approved === 0,
                'editurl' => $editurl->out(false),
                'deletelink' => $output->action_icon(
                    $deleteurl,
                    new pix_icon('t/delete', get_string('delete')),
                    new confirm_action(get_string('confirmdeletequestion', 'mod_playerpuzzle'))
                ),
            ];
        }

        return [
            'addurl' => (new moodle_url('/mod/playerpuzzle/managequestions.php', ['id' => $cmid, 'action' => 'add']))->out(false),
            'addquestionlabel' => get_string('addquestion', 'mod_playerpuzzle'),
            'hasquestions' => !empty($rows),
            'noquestionslabel' => get_string('noquestions', 'mod_playerpuzzle'),
            'questioncolumnlabel' => get_string('question', 'mod_playerpuzzle'),
            'typecolumnlabel' => get_string('questiontype', 'mod_playerpuzzle'),
            'sourcecolumnlabel' => get_string('source', 'mod_playerpuzzle'),
            'actionscolumnlabel' => get_string('actions'),
            'unapprovedlabel' => get_string('unapproved', 'mod_playerpuzzle'),
            'questions' => $rows,
            'backurl' => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cmid]))->out(false),
            'backlabel' => get_string('back', 'core'),
        ];
    }
}
