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
 * Unit tests for the question editor/file helper.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for question_editor_files.
 *
 * @covers \mod_playerpuzzle\local\question_editor_files
 */
final class question_editor_files_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \context_module Module context. */
    private \context_module $context;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $instance = $generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('playerpuzzle', $instance->id);
        $this->context = \context_module::instance($cm->id);
    }

    /**
     * Tests that prepare() seeds a real draft area (a nonzero itemid) for a brand-new row
     * with no stored files yet.
     *
     * @return void
     */
    public function test_prepare_seeds_a_fresh_draft_area_for_a_new_row(): void {
        $editorvalue = question_editor_files::prepare('', FORMAT_HTML, null, $this->context, 'questiontext');

        $this->assertArrayHasKey('text', $editorvalue);
        $this->assertArrayHasKey('format', $editorvalue);
        $this->assertArrayHasKey('itemid', $editorvalue);
        $this->assertGreaterThan(0, $editorvalue['itemid']);
        $this->assertSame('', $editorvalue['text']);
    }

    /**
     * Tests that prepare() carries over a permanently stored file into the draft area, and
     * that finalize() moves it back out to the given real item id, leaving the marker
     * (@@PLUGINFILE@@) intact in the stored text — resolving it to a real URL is
     * format_text()'s job at display time (question_fetcher.php), never done at save time,
     * since a hardcoded URL would break across a site migration or wwwroot change.
     *
     * @return void
     */
    public function test_prepare_then_finalize_preserves_an_existing_file(): void {
        $questionid = 555001;
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_playerpuzzle',
            'filearea'  => 'questiontext',
            'itemid'    => $questionid,
            'filepath'  => '/',
            'filename'  => 'pic.png',
        ], 'original bytes');

        $editorvalue = question_editor_files::prepare(
            '<p>See @@PLUGINFILE@@/pic.png</p>',
            FORMAT_HTML,
            $questionid,
            $this->context,
            'questiontext'
        );

        $result = question_editor_files::finalize($editorvalue, $this->context, 'questiontext', $questionid);

        $this->assertStringContainsString('@@PLUGINFILE@@/pic.png', $result['text']);
        $this->assertSame((int) FORMAT_HTML, $result['format']);

        $files = get_file_storage()->get_area_files(
            $this->context->id,
            'mod_playerpuzzle',
            'questiontext',
            $questionid,
            'sortorder',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame('original bytes', reset($files)->get_content());
    }

    /**
     * Tests that finalize() attaches a newly uploaded draft file to the given real item id.
     *
     * @return void
     */
    public function test_finalize_attaches_a_new_upload_to_the_real_item(): void {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => 'newpic.png',
        ], 'new bytes');

        $editorvalue = ['text' => '<p>See @@PLUGINFILE@@/newpic.png</p>', 'format' => FORMAT_HTML, 'itemid' => $draftitemid];

        $questionid = 555002;
        $result = question_editor_files::finalize($editorvalue, $this->context, 'questiontext', $questionid);

        $this->assertStringContainsString('newpic.png', $result['text']);

        $files = get_file_storage()->get_area_files(
            $this->context->id,
            'mod_playerpuzzle',
            'questiontext',
            $questionid,
            'sortorder',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame('new bytes', reset($files)->get_content());
    }
}
