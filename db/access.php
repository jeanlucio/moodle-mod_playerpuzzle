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
 * Capability definitions for the playerpuzzle module.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Ability to add a new instance of the activity.
    'mod/playerpuzzle:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],

    // Ability to view and play the activity.
    'mod/playerpuzzle:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'guest' => CAP_ALLOW,
            'user' => CAP_ALLOW,
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Ability to view every student's report.
    'mod/playerpuzzle:viewreport' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Ability to manage PlayerPuzzle's own question bank (add/edit/delete questions).
    // RISK_SPAM, not RISK_XSS (security audit finding #3, moodle-security-audit,
    // 2026-09-18): the question/answer editors this capability unlocks always render with
    // noclean => false (question_editor_files::editor_options()) and are displayed through
    // format_text() without noclean/trusttext (question_fetcher::format_with_files()), so
    // there is no live-HTML path for this capability to unlock, unlike moodle/question:add
    // (lib/db/access.php), which does render noclean => true and correctly carries
    // RISK_XSS for it. What this capability genuinely unlocks — authored content (links,
    // external images) shown to every student in the question modal — is exactly the shape
    // core marks RISK_SPAM for elsewhere (mod/forum:replypost, mod/glossary:write,
    // mod/data:writeentry, mod/wiki:editpage): visible to other users, cleaned on output.
    'mod/playerpuzzle:managequestions' => [
        'riskbitmask' => RISK_SPAM,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
