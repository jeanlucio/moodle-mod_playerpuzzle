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
 * Thin wrapper around the core file_prepare_standard_editor()/file_postupdate_standard_editor()
 * pair, so managequestions.php does not repeat the same throwaway-stdClass dance once per
 * editor field (questiontext, plus up to 5 multichoice options).
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context;

/**
 * Both core functions this wraps key everything off one field name on a plain stdClass
 * ($field, {$field}format, {$field}_editor) — using a fixed internal name ('content') works
 * identically regardless of whether the caller is preparing questiontext or one multichoice
 * option, since nothing here ever stores that object anywhere.
 */
class question_editor_files {
    /** @var string Internal-only field name file_prepare/postupdate_standard_editor() key off. */
    private const FIELD = 'content';

    /**
     * Returns the editor options shared by every playerpuzzle question/answer text field.
     *
     * @param context $context Module context the files live in.
     * @return array
     */
    public static function editor_options(context $context): array {
        global $CFG;

        return [
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => $CFG->maxbytes,
            'context'  => $context,
            'noclean'  => false,
            'subdirs'  => false,
        ];
    }

    /**
     * Builds the ['text' => ..., 'format' => ..., 'itemid' => ...] value an `editor` form
     * element expects, given the row's currently stored text/format and its draft file area
     * seeded from the given itemid's permanent files (or a brand-new draft area when the
     * item does not exist yet).
     *
     * @param string $text Currently stored text ('' for a not-yet-created row).
     * @param int $format Currently stored FORMAT_* constant.
     * @param int|null $itemid The question or answer id whose files to seed the draft area
     *  from, or null for a brand-new row with no id yet.
     * @param context $context Module context.
     * @param string $filearea 'questiontext' or 'answertext'.
     * @return array
     */
    public static function prepare(string $text, int $format, ?int $itemid, context $context, string $filearea): array {
        $field = self::FIELD;
        $tmp = (object) [$field => $text, "{$field}format" => $format];
        $tmp = file_prepare_standard_editor(
            $tmp,
            $field,
            self::editor_options($context),
            $context,
            'mod_playerpuzzle',
            $filearea,
            $itemid
        );

        return $tmp->{"{$field}_editor"};
    }

    /**
     * Moves a submitted editor field's draft files to permanent storage under the real
     * item id. The returned text keeps the @@PLUGINFILE@@ placeholder marker as-is — this
     * is the stable, storable form; resolving it to a real URL is format_text()'s job at
     * display time (question_fetcher.php), never done here at save time.
     *
     * @param array $editorvalue The submitted ['text' => ..., 'format' => ..., 'itemid' =>
     *  draft itemid] value from the form.
     * @param context $context Module context.
     * @param string $filearea 'questiontext' or 'answertext'.
     * @param int $itemid The real question or answer id to attach the files to.
     * @return array ['text' => string, 'format' => int] ready to persist.
     */
    public static function finalize(array $editorvalue, context $context, string $filearea, int $itemid): array {
        $field = self::FIELD;
        $tmp = (object) ["{$field}_editor" => $editorvalue];
        $tmp = file_postupdate_standard_editor(
            $tmp,
            $field,
            self::editor_options($context),
            $context,
            'mod_playerpuzzle',
            $filearea,
            $itemid
        );

        return ['text' => $tmp->{$field}, 'format' => (int) $tmp->{"{$field}format"}];
    }
}
