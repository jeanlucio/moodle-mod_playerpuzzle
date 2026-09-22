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
 * Plugin administration settings.
 *
 * PlayerHUD integration is automatic via hud_service::is_installed(), detected per instance
 * in mod_form.php — no site-level configuration for it here. The speech narration toggle is
 * a per-student preference set from the activity's own Lobby (sound_preferences::TYPES,
 * 'speech'), not a site setting: whether to hear game announcements spoken aloud is the
 * student's own call, not something an admin should have to enable first. The PlayerHUD
 * headings below are informational notices, not settings — this file has no real setting.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    if (\mod_playerpuzzle\local\hud_service::is_outdated()) {
        $settings->add(new admin_setting_heading(
            'mod_playerpuzzle/hudoutdated',
            get_string('hud_outdated_heading', 'mod_playerpuzzle'),
            get_string('hud_outdated_desc', 'mod_playerpuzzle')
        ));
    } else if (!\mod_playerpuzzle\local\hud_service::is_installed()) {
        $settings->add(new admin_setting_heading(
            'mod_playerpuzzle/hudnotinstalled',
            get_string('hud_notinstalled_heading', 'mod_playerpuzzle'),
            get_string('hud_notinstalled_desc', 'mod_playerpuzzle')
        ));
    }
}
