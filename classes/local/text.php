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
 * Helper turning stored names into plain text for Mustache templates.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context;

/**
 * Text helpers shared by the services that feed Mustache templates.
 */
class text {
    /**
     * A name as plain text, ready for a Mustache `{{ }}` tag, which escapes it once.
     *
     * format_string() returns HTML, with "&" as "&amp;", so passing its result to `{{ }}` shows
     * "&amp;amp;". Its 'escape' => false option does not fix that: it is ignored when the site
     * setting formatstringstriptags is off, because the HTML cleaner then re-encodes the text. The
     * filters still run; the markup is stripped and the entities are decoded afterwards.
     *
     * @param string|null $string The stored name.
     * @param context|null $context Context for the filters; defaults to the page context.
     * @return string Plain text, unescaped.
     */
    public static function plain_string(?string $string, ?context $context = null): string {
        if ($string === null || $string === '') {
            return '';
        }

        $options = $context === null ? [] : ['context' => $context];
        $formatted = format_string($string, true, $options);

        return html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
