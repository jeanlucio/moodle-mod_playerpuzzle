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

use context;
use moodle_url;
use renderer_base;
use stdClass;

/**
 * Builds the mod_playerpuzzle/managequestions template context.
 *
 * Mirrors mod_playerwords/managewords.php's own listing (sortable columns, bulk
 * approve/delete, draw count, paging bar) — ported deliberately for consistency across the
 * two management screens, not independently designed.
 */
class question_list_service {
    /** @var string[] Columns the listing offers a sort link for. */
    private const SORTABLE_COLUMNS = ['qtype', 'source', 'approved'];

    /**
     * Builds the template context for the question listing screen.
     *
     * @param stdClass $instance The activity instance.
     * @param int $cmid The course module id.
     * @param renderer_base $output Used to render the paging bar.
     * @param context $context Module context, to check AI availability.
     * @param string $sort Column to sort by (already validated by the caller).
     * @param string $dir 'ASC' or 'DESC' (already validated by the caller).
     * @param int $page Zero-based page number.
     * @return array Template context for mod_playerpuzzle/managequestions.
     */
    public static function build_list_context(
        stdClass $instance,
        int $cmid,
        renderer_base $output,
        context $context,
        string $sort = 'id',
        string $dir = 'DESC',
        int $page = 0
    ): array {
        $perpage = questions_repository::MANAGE_PERPAGE;
        $pool = questions_repository::get_questions_for_management(
            (int) $instance->id,
            $page,
            $perpage,
            $sort,
            $dir
        );
        $drawcounts = questions_repository::get_draw_counts((int) $instance->id);

        $baseurl = new moodle_url('/mod/playerpuzzle/managequestions.php', ['id' => $cmid]);

        $sorticons = [];
        $sorturls = [];
        foreach (self::SORTABLE_COLUMNS as $col) {
            if ($sort === $col) {
                $newdir = ($dir === 'ASC') ? 'DESC' : 'ASC';
                $icon = ($dir === 'ASC') ? 'fa-sort-up' : 'fa-sort-down';
            } else {
                $newdir = 'ASC';
                $icon = 'fa-sort';
            }
            $sorticons[$col] = $icon;
            $colurl = clone $baseurl;
            $colurl->param('sort', $col);
            $colurl->param('dir', $newdir);
            $sorturls[$col] = $colurl->out(false);
        }

        $pagingbaseurl = clone $baseurl;
        $pagingbaseurl->param('sort', $sort);
        $pagingbaseurl->param('dir', $dir);
        $pagingbar = $output->paging_bar($pool['total'], $page, $perpage, $pagingbaseurl);

        $rows = [];
        foreach ($pool['rows'] as $question) {
            $editurl = new moodle_url(
                '/mod/playerpuzzle/managequestions.php',
                ['id' => $cmid, 'action' => 'edit', 'qid' => $question->id]
            );
            $approveurl = new moodle_url(
                '/mod/playerpuzzle/managequestions.php',
                ['id' => $cmid, 'action' => 'approve', 'qid' => $question->id, 'sesskey' => sesskey()]
            );

            $answerspreview = array_map(
                fn(stdClass $answer): string => ((int) $answer->iscorrect === 1 ? '✓ ' : '')
                    . content_to_text($answer->answertext, (int) $answer->answerformat),
                $question->answers
            );

            // Only 'ai' ever reaches approved = 0 through a review queue a teacher is meant to
            // act on; question_bank_sync::sync_from_category() is the only other place that
            // clears the flag, for a 'bank' row orphaned by its source category disappearing —
            // that is a disabled state, not a pending one, and the fix is resyncing, not
            // approving. 'manual' never reaches approved = 0 through any code path today, but
            // is treated the same as 'bank' here rather than as unreachable, so a future
            // manual-disable action would get the right label for free.
            $isaipending = (int) $question->approved === 0 && $question->source === 'ai';
            $isdisabled = (int) $question->approved === 0 && $question->source !== 'ai';
            if ($isaipending) {
                $statuslabel = get_string('pendingstatus', 'mod_playerpuzzle');
                $statusbadgeclass = 'bg-warning text-dark';
            } else if ($isdisabled) {
                $statuslabel = get_string('disabledstatus', 'mod_playerpuzzle');
                $statusbadgeclass = 'bg-secondary pp-status-disabled';
            } else {
                $statuslabel = get_string('approvedstatus', 'mod_playerpuzzle');
                $statusbadgeclass = 'bg-success';
            }

            $rows[] = [
                'id' => (int) $question->id,
                'questiontext' => content_to_text($question->questiontext, (int) $question->questiontextformat),
                'qtypelabel' => get_string('qtype_' . $question->qtype, 'mod_playerpuzzle'),
                'answerspreview' => implode(' · ', $answerspreview),
                'sourcelabel' => get_string('source_' . $question->source, 'mod_playerpuzzle'),
                'statuslabel' => $statuslabel,
                'statusbadgeclass' => $statusbadgeclass,
                'ispending' => $isaipending,
                'approveurl' => $isaipending ? $approveurl->out(false) : '',
                'approvelabel' => get_string('approvequestion', 'mod_playerpuzzle'),
                'editurl' => $editurl->out(false),
                'drawcount' => $drawcounts[(int) $question->id] ?? 0,
            ];
        }

        return [
            'cmid' => $cmid,
            'sesskey' => sesskey(),
            'addurl' => (new moodle_url('/mod/playerpuzzle/managequestions.php', ['id' => $cmid, 'action' => 'add']))->out(false),
            'addquestionlabel' => get_string('addquestion', 'mod_playerpuzzle'),
            'aiavailable' => ai_question_generator::has_key($context),
            'generatewithailabel' => get_string('generatewithai', 'mod_playerpuzzle'),
            'hasquestions' => !empty($rows),
            'noquestionslabel' => get_string('noquestions', 'mod_playerpuzzle'),
            'questioncolumnlabel' => get_string('question', 'mod_playerpuzzle'),
            'typecolumnlabel' => get_string('questiontype', 'mod_playerpuzzle'),
            'sourcecolumnlabel' => get_string('source', 'mod_playerpuzzle'),
            'statuscolumnlabel' => get_string('statuscolumnlabel', 'mod_playerpuzzle'),
            'drawcountcolumnlabel' => get_string('drawcountcolumnlabel', 'mod_playerpuzzle'),
            'actionscolumnlabel' => get_string('actions'),
            'sort_qtype_url' => $sorturls['qtype'],
            'sort_qtype_icon' => $sorticons['qtype'],
            'sort_source_url' => $sorturls['source'],
            'sort_source_icon' => $sorticons['source'],
            'sort_approved_url' => $sorturls['approved'],
            'sort_approved_icon' => $sorticons['approved'],
            'selectalllabel' => get_string('selectall', 'mod_playerpuzzle'),
            'selectquestionlabel' => get_string('selectquestion', 'mod_playerpuzzle'),
            'bulkapprovebutton' => get_string('bulkapprovebutton', 'mod_playerpuzzle'),
            'bulkapprovebuttontitle' => get_string('bulkapprovebuttontitle', 'mod_playerpuzzle'),
            'bulkapproveconfirm' => get_string('bulkapproveconfirm', 'mod_playerpuzzle'),
            'bulkdeletebutton' => get_string('bulkdeletebutton', 'mod_playerpuzzle'),
            'bulkdeleteconfirm' => get_string('bulkdeleteconfirm', 'mod_playerpuzzle'),
            'deletequestionbutton' => get_string('delete'),
            'deletequestiontitle' => get_string('deletequestiontitle', 'mod_playerpuzzle'),
            'deletequestionconfirm' => get_string('confirmdeletequestion', 'mod_playerpuzzle'),
            'editquestionbutton' => get_string('edit'),
            'questions' => $rows,
            'pagingbar' => $pagingbar,
            'backurl' => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cmid]))->out(false),
            'backlabel' => get_string('back', 'core'),
        ];
    }
}
