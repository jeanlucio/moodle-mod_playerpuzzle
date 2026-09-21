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
 * Restore structure step for mod_playerpuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Processes the XML tree produced by backup and rebuilds the database records.
 */
class restore_playerpuzzle_activity_structure_step extends restore_activity_structure_step {
    /**
     * Returns the path elements the restore engine should process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure(): array {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('playerpuzzle', '/activity/playerpuzzle');
        $paths[] = new restore_path_element(
            'playerpuzzle_bankquestion',
            '/activity/playerpuzzle/bankquestions/bankquestion'
        );
        $paths[] = new restore_path_element(
            'playerpuzzle_bankanswer',
            '/activity/playerpuzzle/bankquestions/bankquestion/bankanswers/bankanswer'
        );

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'playerpuzzle_attempt',
                '/activity/playerpuzzle/attempts/attempt'
            );
            $paths[] = new restore_path_element(
                'playerpuzzle_attempt_question',
                '/activity/playerpuzzle/attempts/attempt/questions/question'
            );
            $paths[] = new restore_path_element(
                'playerpuzzle_attempt_consumable',
                '/activity/playerpuzzle/attempts/attempt/consumables/consumable'
            );
            $paths[] = new restore_path_element(
                'playerpuzzle_stockitem',
                '/activity/playerpuzzle/stockitems/stockitem'
            );
        }

        // Wrap with the generic '/activity' path so the base class's process_activity()
        // runs: it registers the old-to-new context mapping and the old activity id.
        // Without this, restore_calendarevents_structure_step::after_execute() (a generic
        // step that runs for every activity) fails with unknown_context_mapping, and
        // course_format\local\cmactions::duplicate() never reaches its post-restore
        // cleanup (renaming to "(copy)", moving to the target section, rebuilding the
        // course cache) since the exception aborts the restore plan first.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Resolves a backed-up PlayerHUD item ID to the item that should be referenced in the
     * restored copy, or 0 if none applies.
     *
     * Tries, in order: (1) the playerhud_item restore mapping, when the PlayerHUD block was
     * part of the same restore (full course backup/restore, or "Import") — block_playerhud's
     * own restore task always runs before this activity's, so the mapping is already
     * available here, no after_execute()/after_restore() deferral needed; (2) if no mapping
     * was registered, whether the original ID still legitimately belongs to the destination
     * course's own PlayerHUD block instance ("Duplicate activity", which never restores the
     * block, so nothing needs remapping); (3) otherwise the original ID belongs to a
     * different course's PlayerHUD, or PlayerHUD isn't installed on this site at all, and
     * must be dropped rather than risk operating on the wrong course's item —
     * block_playerhud_items.id is a single site-wide sequence, not scoped per course.
     *
     * @param int $oldid Backed-up item ID, 0 if the field was not configured.
     * @return int
     */
    private function resolve_hud_item(int $oldid): int {
        if ($oldid <= 0) {
            return 0;
        }

        $mapped = $this->get_mappingid('playerhud_item', $oldid);
        if ($mapped) {
            return (int) $mapped;
        }

        if (!class_exists('\block_playerhud\local\external_items')) {
            return 0;
        }

        $blockinstanceid = \mod_playerpuzzle\local\hud_service::get_block_instance_id($this->get_courseid());
        if ($blockinstanceid === null) {
            return 0;
        }

        return \block_playerhud\local\external_items::belongs_to_instance($oldid, $blockinstanceid) ? $oldid : 0;
    }

    /**
     * Restores the root playerpuzzle instance record.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playerpuzzle(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Same pattern already shipped by mod_game for its own questioncategoryid column
        // (backup/moodle2/restore_game_stepslib.php): core's own generic question-bank
        // restore step registers a 'question_category' mapping whenever the category was
        // actually part of this backup (a full course backup/restore, or a same-course
        // "Duplicate activity"), remapped here to the freshly created copy. Falls back to
        // 0 only when no mapping exists at all — the original id no longer resolves to any
        // real category on the destination site (e.g. importing a backup produced on a
        // different Moodle install).
        $data->questioncategory = (int) $this->get_mappingid('question_category', $data->questioncategory, 0);

        $data->hud_coin_item = $this->resolve_hud_item((int) ($data->hud_coin_item ?? 0));
        $data->hud_retry_cost_item = $this->resolve_hud_item((int) ($data->hud_retry_cost_item ?? 0));
        $data->hud_win_grant_item = $this->resolve_hud_item((int) ($data->hud_win_grant_item ?? 0));

        $newitemid = $DB->insert_record('playerpuzzle', $data);
        $this->apply_activity_instance($newitemid);
        $this->set_mapping('playerpuzzle', $oldid, $newitemid);
    }

    /**
     * Restores a question belonging to the activity's own question bank.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playerpuzzle_bankquestion(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->playerpuzzleid = $this->get_new_parentid('playerpuzzle');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        // Fall back to 0 (anonymous) when unmapped — addedby is content metadata, not
        // personal data itself, so it is always present here regardless of $userinfo.
        $data->addedby = (int) $this->get_mappingid('user', $data->addedby, 0);

        $newitemid = $DB->insert_record('playerpuzzle_questions', $data);
        // Registered under the same name as the path element, so process_playerpuzzle_
        // bankanswer() can resolve it via get_new_parentid(), and process_playerpuzzle_
        // attempt_question() — a same-instance sibling processed later in the same
        // document — can resolve it via get_mappingid(), which does not require the name
        // to match a path element. The 4th argument (true) is mandatory for
        // after_execute()'s add_related_files() to find this row's questiontext file: it
        // records the old activity context as this mapping's parentitemid, which is
        // exactly what add_related_files()'s own SQL join matches a file's old contextid
        // against — omitting it means the join finds nothing and the file is silently
        // never restored, mirroring mod_glossary's own set_mapping('glossary_entry', ...,
        // true) for glossary_entries.
        $this->set_mapping('playerpuzzle_bankquestion', $oldid, $newitemid, true);
    }

    /**
     * Restores an answer belonging to a restored bank question.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playerpuzzle_bankanswer(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->questionid = $this->get_new_parentid('playerpuzzle_bankquestion');

        $newitemid = $DB->insert_record('playerpuzzle_question_answers', $data);
        // Registered purely so after_execute()'s add_related_files() can remap an
        // answertext file's itemid — nothing else references a bank answer's id. The 4th
        // argument (true) is mandatory, same reasoning as process_playerpuzzle_bankquestion().
        $this->set_mapping('playerpuzzle_bankanswer', $oldid, $newitemid, true);
    }

    /**
     * Restores a student attempt record (only when userinfo is enabled).
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playerpuzzle_attempt(array|object $data): void {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->playerpuzzleid = $this->get_new_parentid('playerpuzzle');
        $data->userid = $this->get_mappingid('user', $data->userid);
        // Same namespace/fallback as process_playerpuzzle_attempt_question()'s own
        // questionid remap below: 0 (no question currently open) when the original question
        // no longer exists or was not part of this restore, rather than leaking a stale id
        // that would point at the wrong question in the target course.
        $data->currentquestionid = (int) $this->get_mappingid('playerpuzzle_bankquestion', $data->currentquestionid, 0);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timefinished = $this->apply_date_offset($data->timefinished);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        // Regenerated rather than preserved: the column carries a UNIQUE index across the
        // whole site, not scoped per instance, so restoring the exact original bytes would
        // collide whenever the same backup is restored into the same site (import, or a
        // second "Duplicate" of a course already containing this attempt). The token is
        // inherently ephemeral anyway — resume_or_create_attempt_token() always rotates it
        // the moment a student resumes an in-progress attempt.
        $data->token = bin2hex(random_bytes(32));

        // Skip an orphaned attempt (the user was not part of this restore).
        if (empty($data->userid)) {
            return;
        }

        $newitemid = $DB->insert_record('playerpuzzle_attempts', $data);
        $this->set_mapping('playerpuzzle_attempt', $oldid, $newitemid);
    }

    /**
     * Restores a logged question row belonging to an attempt (only when userinfo is
     * enabled). The question/answer text is already snapshotted inline on the row, so an
     * unmapped questionid (the original question no longer exists, or wasn't part of this
     * restore) still leaves a fully readable review entry — the row is kept either way.
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playerpuzzle_attempt_question(array|object $data): void {
        global $DB;

        $data = (object) $data;

        $data->attemptid = $this->get_new_parentid('playerpuzzle_attempt');
        // Questionid points at this activity's own bank (see the Wave 1 revert to a single
        // question source), never the real Moodle question bank — the same namespace the
        // backup step annotates it under.
        $data->questionid = (int) $this->get_mappingid('playerpuzzle_bankquestion', $data->questionid, 0);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $DB->insert_record('playerpuzzle_attempt_questions', $data);
    }

    /**
     * Restores a consumable-use counter row belonging to an attempt (only when userinfo
     * is enabled).
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playerpuzzle_attempt_consumable(array|object $data): void {
        global $DB;

        $data = (object) $data;

        $data->attemptid = $this->get_new_parentid('playerpuzzle_attempt');

        $DB->insert_record('playerpuzzle_attempt_consumables', $data);
    }

    /**
     * Restores a loadout stock row (only when userinfo is enabled). Skipped when the owning
     * user was not part of this restore — same rule as process_playerpuzzle_attempt().
     *
     * @param array|object $data XML data for this element.
     * @return void
     */
    public function process_playerpuzzle_stockitem(array|object $data): void {
        global $DB;

        $data = (object) $data;

        $data->playerpuzzleid = $this->get_new_parentid('playerpuzzle');
        $data->userid = $this->get_mappingid('user', $data->userid);

        if (empty($data->userid)) {
            return;
        }

        $DB->insert_record('playerpuzzle_user_stock', $data);
    }

    /**
     * Restores files embedded in the activity's intro editor field.
     *
     * The grade item itself is not touched here: restore_activity_grades_structure_step
     * (added generically by restore_activity_task for every gradable module) already
     * restores it. Calling playerpuzzle_grade_item_update() again here would race against
     * that generic step and leave two grade_items for the same instance.
     *
     * @return void
     */
    protected function after_execute(): void {
        $this->add_related_files('mod_playerpuzzle', 'intro', null);
        $this->add_related_files('mod_playerpuzzle', 'questiontext', 'playerpuzzle_bankquestion');
        $this->add_related_files('mod_playerpuzzle', 'answertext', 'playerpuzzle_bankanswer');
    }
}
