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
use mod_playerpuzzle\form\question_form;
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
    /** @var string[] Columns the listing offers a sort link for in its header. */
    private const SORTABLE_COLUMNS = ['phase', 'status', 'drawn'];

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
     * @param array $viewparams The page's own URL parameters (id, and the filters and sort when set), which every link
     *  and form of the listing carries along so none of them drops the teacher out of the view.
     * @param string $importhtml The import-from-bank panel, already rendered.
     * @param int|null $phase Phase the listing is filtered to (0 for those meant for no phase), or null for all.
     * @param string|null $status Status the listing is filtered to, or null for all.
     * @return array Template context for mod_playerpuzzle/managequestions.
     */
    public static function build_list_context(
        stdClass $instance,
        int $cmid,
        renderer_base $output,
        context $context,
        string $sort = 'id',
        string $dir = 'DESC',
        int $page = 0,
        array $viewparams = [],
        string $importhtml = '',
        ?int $phase = null,
        ?string $status = null
    ): array {
        $viewparams = $viewparams ?: ['id' => $cmid];
        $perpage = questions_repository::MANAGE_PERPAGE;
        $pool = questions_repository::get_questions_for_management(
            (int) $instance->id,
            $page,
            $perpage,
            $sort,
            $dir,
            $phase,
            $status
        );
        $drawcounts = questions_repository::get_draw_counts((int) $instance->id);

        $path = '/mod/playerpuzzle/managequestions.php';
        $baseurl = new moodle_url($path, $viewparams);

        $sorticons = [];
        $sorturls = [];
        $sortaria = [];
        foreach (self::SORTABLE_COLUMNS as $col) {
            if ($sort === $col) {
                $newdir = ($dir === 'ASC') ? 'DESC' : 'ASC';
                $icon = ($dir === 'ASC') ? 'fa-sort-up' : 'fa-sort-down';
                $aria = ($dir === 'ASC') ? 'ascending' : 'descending';
            } else {
                $newdir = 'ASC';
                $icon = 'fa-sort';
                $aria = 'none';
            }
            $sorticons[$col] = $icon;
            $sortaria[$col] = $aria;
            $colurl = clone $baseurl;
            $colurl->param('sort', $col);
            $colurl->param('dir', $newdir);
            $sorturls[$col] = $colurl->out(false);
        }

        $pagingbar = $output->paging_bar($pool['total'], $page, $perpage, $baseurl);

        $phaselabels = question_form::phase_options();
        $rows = [];
        foreach ($pool['rows'] as $question) {
            $editurl = new moodle_url($path, $viewparams + ['action' => 'edit', 'qid' => $question->id]);
            $approveurl = new moodle_url(
                $path,
                $viewparams + ['action' => 'approve', 'qid' => $question->id, 'sesskey' => sesskey()]
            );

            $answerspreview = array_map(
                fn(stdClass $answer): string => ((int) $answer->iscorrect === 1 ? '✓ ' : '')
                    . content_to_text($answer->answertext, (int) $answer->answerformat),
                $question->answers
            );

            // Only 'ai' ever reaches approved = 0 through a review queue a teacher is meant to
            // act on; question_bank_sync::sync_from_category() is the only other place that
            // clears the flag, for a 'bank' row orphaned by its source category disappearing —
            // that is a disabled state, not a pending one. 'manual' never reaches approved = 0
            // through any code path today, but is treated the same as 'bank' here rather than
            // as unreachable, so a future manual-disable action would get the right label for
            // free. Both states can be approved from here — approve_question()/
            // approve_questions_bulk() also clear sourceid, so reactivating a disabled bank
            // question detaches it from the sync instead of letting the next run disable it
            // again; only the button's own label differs, to tell the teacher why it was off.
            $isaipending = (int) $question->approved === 0 && $question->source === 'ai';
            $isdisabled = (int) $question->approved === 0 && $question->source !== 'ai';
            $canapprove = $isaipending || $isdisabled;
            if ($isaipending) {
                $statuslabel = get_string('pendingstatus', 'mod_playerpuzzle');
                $statusbadgeclass = 'bg-warning text-dark';
                $approvelabel = get_string('approvequestion', 'mod_playerpuzzle');
            } else if ($isdisabled) {
                $statuslabel = get_string('disabledstatus', 'mod_playerpuzzle');
                $statusbadgeclass = 'bg-secondary pp-status-disabled';
                $approvelabel = get_string('reactivatequestion', 'mod_playerpuzzle');
            } else {
                $statuslabel = get_string('approvedstatus', 'mod_playerpuzzle');
                $statusbadgeclass = 'bg-success';
                $approvelabel = '';
            }

            $rows[] = [
                'id' => (int) $question->id,
                'questiontext' => content_to_text($question->questiontext, (int) $question->questiontextformat),
                'qtypelabel' => get_string('qtype_' . $question->qtype, 'mod_playerpuzzle'),
                'answerspreview' => implode(' · ', $answerspreview),
                'sourcelabel' => get_string('source_' . $question->source, 'mod_playerpuzzle'),
                'metaline' => get_string('qtype_' . $question->qtype, 'mod_playerpuzzle') . ' · '
                    . get_string('source_' . $question->source, 'mod_playerpuzzle'),
                'phaselabel' => $phaselabels[(int) $question->phase] ?? '',
                'hasphase' => (int) $question->phase > 0,
                'statuslabel' => $statuslabel,
                'statusbadgeclass' => $statusbadgeclass,
                'canapprove' => $canapprove,
                'approveurl' => $canapprove ? $approveurl->out(false) : '',
                'approvelabel' => $approvelabel,
                'editurl' => $editurl->out(false),
                'drawcount' => $drawcounts[(int) $question->id] ?? 0,
            ];
        }

        $filtersactive = $phase !== null || $status !== null;
        $clearparams = array_diff_key($viewparams, ['phase' => 1, 'status' => 1]);

        return [
            'cmid' => $cmid,
            'sesskey' => sesskey(),
            'addurl' => (new moodle_url($path, $viewparams + ['action' => 'add']))->out(false),
            'addquestionlabel' => get_string('addquestion', 'mod_playerpuzzle'),
            'aiavailable' => ai_question_generator::has_key($context),
            'generatewithailabel' => get_string('generatewithai', 'mod_playerpuzzle'),
            'importheader' => get_string('importheader', 'mod_playerpuzzle'),
            'importtogglelabel' => get_string('importtogglelabel', 'mod_playerpuzzle'),
            'importhtml' => $importhtml,
            'hasquestions' => $pool['all'] > 0,
            'hasrows' => !empty($rows),
            'noquestionslabel' => get_string('noquestions', 'mod_playerpuzzle'),
            'nomatchlabel' => get_string('noquestionsmatch', 'mod_playerpuzzle'),
            'filteraction' => (new moodle_url($path))->out(false),
            'filterlabel' => get_string('filterlabel', 'mod_playerpuzzle'),
            'filterbutton' => get_string('filterbutton', 'mod_playerpuzzle'),
            'phasefilterlabel' => get_string('questionphase', 'mod_playerpuzzle'),
            'phasefilteroptions' => self::phase_filter_options($phase),
            'statusfilterlabel' => get_string('statuscolumnlabel', 'mod_playerpuzzle'),
            'statusfilteroptions' => self::status_filter_options($status),
            'sortbylabel' => get_string('sortbylabel', 'mod_playerpuzzle'),
            'sortoptions' => self::sort_options($sort),
            'sortdirlabel' => get_string('sortdirlabel', 'mod_playerpuzzle'),
            'sortdiroptions' => [
                [
                    'value' => 'ASC',
                    'label' => get_string('sortdir_asc', 'mod_playerpuzzle'),
                    'selected' => $dir === 'ASC',
                ],
                [
                    'value' => 'DESC',
                    'label' => get_string('sortdir_desc', 'mod_playerpuzzle'),
                    'selected' => $dir === 'DESC',
                ],
            ],
            'showingcount' => get_string(
                'showingcount',
                'mod_playerpuzzle',
                (object) ['shown' => $pool['total'], 'total' => $pool['all']]
            ),
            'filtersactive' => $filtersactive,
            'clearfiltersurl' => (new moodle_url($path, $clearparams))->out(false),
            'clearfilterslabel' => get_string('clearfilters', 'mod_playerpuzzle'),
            'questioncolumnlabel' => get_string('question', 'mod_playerpuzzle'),
            'phasecolumnlabel' => get_string('questionphase', 'mod_playerpuzzle'),
            'statuscolumnlabel' => get_string('statuscolumnlabel', 'mod_playerpuzzle'),
            'drawcountcolumnlabel' => get_string('drawcountcolumnlabel', 'mod_playerpuzzle'),
            'actionscolumnlabel' => get_string('actions'),
            'sort_phase_url' => $sorturls['phase'],
            'sort_phase_icon' => $sorticons['phase'],
            'sort_phase_aria' => $sortaria['phase'],
            'sort_status_url' => $sorturls['status'],
            'sort_status_icon' => $sorticons['status'],
            'sort_status_aria' => $sortaria['status'],
            'sort_drawn_url' => $sorturls['drawn'],
            'sort_drawn_icon' => $sorticons['drawn'],
            'sort_drawn_aria' => $sortaria['drawn'],
            'selectalllabel' => get_string('selectall', 'mod_playerpuzzle'),
            'selectquestionlabel' => get_string('selectquestion', 'mod_playerpuzzle'),
            'bulkhidden' => array_map(
                static fn(string $name, $value): array => ['name' => $name, 'value' => (string) $value],
                array_keys($viewparams),
                array_values($viewparams)
            ),
            'bulkhint' => get_string('bulkhint', 'mod_playerpuzzle'),
            'bulkselectedone' => get_string('bulkselected_one', 'mod_playerpuzzle'),
            'bulkselectedmany' => get_string('bulkselected', 'mod_playerpuzzle'),
            'bulkclearbutton' => get_string('bulkclearbutton', 'mod_playerpuzzle'),
            'bulkapprovebutton' => get_string('bulkapprovebutton', 'mod_playerpuzzle'),
            'bulkapprovebuttontitle' => get_string('bulkapprovebuttontitle', 'mod_playerpuzzle'),
            'bulkapproveconfirm' => get_string('bulkapproveconfirm', 'mod_playerpuzzle'),
            'bulkphaseselectlabel' => get_string('bulkphaseselectlabel', 'mod_playerpuzzle'),
            'bulkphaseoptions' => array_map(
                static fn(int $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($phaselabels),
                array_values($phaselabels)
            ),
            'bulkphasebutton' => get_string('bulkphasebutton', 'mod_playerpuzzle'),
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

    /**
     * The options of the phase filter: every phase, the questions meant for no phase in particular, then each
     * phase of a level.
     *
     * @param int|null $selected The phase the listing is filtered to (0 for none in particular), null for all.
     * @return array[] Each with value, label and selected.
     */
    private static function phase_filter_options(?int $selected): array {
        $options = [
            ['value' => '', 'label' => get_string('filterphase_all', 'mod_playerpuzzle'), 'selected' => $selected === null],
            ['value' => 'none', 'label' => get_string('filterphase_none', 'mod_playerpuzzle'), 'selected' => $selected === 0],
        ];
        for ($phase = 1; $phase <= campaigns::PHASES_PER_LEVEL; $phase++) {
            $options[] = [
                'value' => (string) $phase,
                'label' => get_string('questionphase_n', 'mod_playerpuzzle', $phase),
                'selected' => $selected === $phase,
            ];
        }
        return $options;
    }

    /**
     * The options of the status filter.
     *
     * @param string|null $selected The status the listing is filtered to, null for all.
     * @return array[] Each with value, label and selected.
     */
    private static function status_filter_options(?string $selected): array {
        $options = [
            ['value' => '', 'label' => get_string('filterstatus_all', 'mod_playerpuzzle'), 'selected' => $selected === null],
        ];
        foreach (questions_repository::MANAGE_STATUSES as $status) {
            $options[] = [
                'value' => $status,
                'label' => get_string('filterstatus_' . $status, 'mod_playerpuzzle'),
                'selected' => $selected === $status,
            ];
        }
        return $options;
    }

    /**
     * The options of the "sort by" select, for the sorts the header links do not cover (type and source) as well
     * as the ones they do.
     *
     * @param string $selected The column the listing is sorted by.
     * @return array[] Each with value, label and selected.
     */
    private static function sort_options(string $selected): array {
        $options = [];
        $keys = [
            'id' => 'recent',
            'phase' => 'phase',
            'status' => 'status',
            'drawn' => 'drawn',
            'qtype' => 'type',
            'source' => 'source',
        ];
        foreach ($keys as $column => $key) {
            $options[] = [
                'value' => $column,
                'label' => get_string('sort_' . $key, 'mod_playerpuzzle'),
                'selected' => $selected === $column,
            ];
        }
        return $options;
    }
}
