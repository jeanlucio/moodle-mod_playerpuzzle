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
 * Unit tests for the question bank sync (import from the real Moodle question bank).
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for question_bank_sync.
 *
 * @covers \mod_playerpuzzle\local\question_bank_sync
 */
final class question_bank_sync_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Course module for the playerpuzzle instance. */
    private \stdClass $cm;

    /** @var \stdClass Activity instance. */
    private \stdClass $instance;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('playerpuzzle', $this->instance->id);
    }

    /**
     * Creates a question bank category in a qbank activity instance living in this test's
     * own course — a sibling module context, one of the contexts
     * question_bank_sync::get_importable_categories() considers reachable. Question
     * categories can only live in a CONTEXT_MODULE context (a "Question bank" activity)
     * since the qbank plugin type was introduced; passing a course/system contextid to
     * the generator silently redirects to a qbank instance on the site course instead,
     * which would not be reachable from this test's own course.
     *
     * @return \stdClass The category record.
     */
    private function make_category(): \stdClass {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        return $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($qbank->cmid)->id,
        ]);
    }

    /**
     * Tests that a single-answer multichoice and a truefalse question both import, with
     * their text/answers/correctness copied and sourceid set to the question bank entry id.
     *
     * @return void
     */
    public function test_sync_imports_multichoice_and_truefalse_questions(): void {
        global $DB;

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $this->make_category();
        $mc = $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $category->id]);
        $tf = $questiongenerator->create_question('truefalse', 'true', ['category' => $category->id]);

        $stats = question_bank_sync::sync_from_category($this->cm, (int) $this->instance->id, (int) $category->id);

        $this->assertSame(2, $stats->imported);
        $this->assertSame(0, $stats->updated);
        $this->assertSame(0, $stats->skipped);
        $this->assertSame(0, $stats->disabled);

        $rows = questions_repository::get_questions_for_instance((int) $this->instance->id);
        $this->assertCount(2, $rows);

        $entryidmc = (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $mc->id]);
        $entryidtf = (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $tf->id]);

        $bysourceid = [];
        foreach ($rows as $row) {
            $this->assertSame('bank', $row->source);
            $this->assertSame(1, (int) $row->approved);
            $bysourceid[(int) $row->sourceid] = $row;
        }

        $this->assertArrayHasKey($entryidmc, $bysourceid);
        $this->assertArrayHasKey($entryidtf, $bysourceid);
        $this->assertSame('multichoice', $bysourceid[$entryidmc]->qtype);
        $this->assertSame('truefalse', $bysourceid[$entryidtf]->qtype);
        $this->assertNotEmpty($bysourceid[$entryidmc]->questiontext);

        $correct = array_values(array_filter(
            $bysourceid[$entryidmc]->answers,
            fn($a) => (int) $a->iscorrect === 1
        ));
        $this->assertCount(1, $correct);
        $this->assertSame('One', $correct[0]->answertext);
    }

    /**
     * Tests that a multichoice question configured to accept more than one correct answer
     * is skipped and counted, never imported half-broken.
     *
     * @return void
     */
    public function test_sync_skips_multianswer_question_and_counts_it(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $this->make_category();
        $questiongenerator->create_question('multichoice', 'two_of_four', ['category' => $category->id]);

        $stats = question_bank_sync::sync_from_category($this->cm, (int) $this->instance->id, (int) $category->id);

        $this->assertSame(0, $stats->imported);
        $this->assertSame(1, $stats->skipped);
        $this->assertSame([], questions_repository::get_questions_for_instance((int) $this->instance->id));
    }

    /**
     * Tests that re-running the sync on an unchanged category updates the same row rather
     * than duplicating it, matched by sourceid.
     *
     * @return void
     */
    public function test_sync_is_idempotent_on_rerun(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $this->make_category();
        $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $category->id]);

        $first = question_bank_sync::sync_from_category($this->cm, (int) $this->instance->id, (int) $category->id);
        $second = question_bank_sync::sync_from_category($this->cm, (int) $this->instance->id, (int) $category->id);

        $this->assertSame(1, $first->imported);
        $this->assertSame(0, $second->imported);
        $this->assertSame(1, $second->updated);
        $this->assertCount(1, questions_repository::get_questions_for_instance((int) $this->instance->id));
    }

    /**
     * Tests that a bank-sourced row whose bank entry disappears from the category (deleted,
     * in this test) is soft-disabled — approved flips to 0 — never hard-deleted, since an
     * attempt's answered-question log may still reference its id.
     *
     * @return void
     */
    public function test_sync_disables_orphan_instead_of_deleting_it(): void {
        global $DB;

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $this->make_category();
        $question = $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $category->id]);

        question_bank_sync::sync_from_category($this->cm, (int) $this->instance->id, (int) $category->id);
        $before = questions_repository::get_questions_for_instance((int) $this->instance->id);
        $this->assertCount(1, $before);
        $questionid = (int) $before[0]->id;

        // Simulates the bank entry leaving 'ready' status (e.g. sent back to draft) —
        // the canonical query's own MAX(version)-where-ready subquery then finds nothing
        // for this entry, the same as if it had been deleted or moved to another category.
        $DB->set_field('question_versions', 'status', 'draft', ['questionid' => $question->id]);

        $stats = question_bank_sync::sync_from_category($this->cm, (int) $this->instance->id, (int) $category->id);

        $this->assertSame(1, $stats->disabled);
        $after = questions_repository::get_question($questionid, (int) $this->instance->id);
        $this->assertNotNull($after);
        $this->assertSame(0, (int) $after->approved);
    }

    /**
     * Tests that a manual question already in the instance's own bank is never touched by
     * a sync run, even one importing questions into the same instance.
     *
     * @return void
     */
    public function test_sync_never_touches_manual_questions(): void {
        $manualid = questions_repository::add_question(
            (int) $this->instance->id,
            'multichoice',
            'Manual question',
            'A hint',
            [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
            2
        );

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $this->make_category();
        $questiongenerator->create_question('multichoice', 'one_of_four', ['category' => $category->id]);

        question_bank_sync::sync_from_category($this->cm, (int) $this->instance->id, (int) $category->id);

        $manual = questions_repository::get_question($manualid, (int) $this->instance->id);
        $this->assertSame('Manual question', $manual->questiontext);
        $this->assertSame('A hint', $manual->hint);
        $this->assertSame('manual', $manual->source);
        $this->assertCount(2, questions_repository::get_questions_for_instance((int) $this->instance->id));
    }

    /**
     * Tests that importing from a category outside every reachable context for this course
     * module is rejected, never silently reading it anyway.
     *
     * @return void
     */
    public function test_sync_rejects_a_category_outside_reachable_contexts(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $othercm = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $othercourse->id]);
        $othercmrecord = get_coursemodule_from_instance('playerpuzzle', $othercm->id);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $foreigncategory = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($othercmrecord->id)->id,
        ]);

        $this->expectException(\moodle_exception::class);
        question_bank_sync::sync_from_category($this->cm, (int) $this->instance->id, (int) $foreigncategory->id);
    }

    /**
     * Tests that get_importable_categories() lists a category from a reachable context.
     *
     * @return void
     */
    public function test_get_importable_categories_lists_reachable_category(): void {
        $category = $this->make_category();

        $categories = question_bank_sync::get_importable_categories($this->cm);

        $ids = array_map(fn($c) => (int) $c->id, $categories);
        $this->assertContains((int) $category->id, $ids);
    }
}
