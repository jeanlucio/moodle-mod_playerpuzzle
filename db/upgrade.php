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
 * Plugin upgrade steps.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executes mod_playerpuzzle upgrade steps from the given old version.
 *
 * @param int $oldversion Version number we are upgrading from.
 * @return bool True if upgrade succeeded.
 */
function xmldb_playerpuzzle_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026052101) {
        $table = new xmldb_table('playerpuzzle');

        // Rename bosshp -> basebosshp.
        $field = new xmldb_field('bosshp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1000');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'basebosshp');
        }

        // Add maxlevels.
        $field = new xmldb_field('maxlevels', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add basestudenthp.
        $field = new xmldb_field('basestudenthp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $attemptstable = new xmldb_table('playerpuzzle_attempts');

        // Add currentlevel.
        $field = new xmldb_field('currentlevel', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        // Add currentphase.
        $field = new xmldb_field('currentphase', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        // Add timefinished.
        $field = new xmldb_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($attemptstable, $field)) {
            $dbman->add_field($attemptstable, $field);
        }

        upgrade_mod_savepoint(true, 2026052101, 'playerpuzzle');
    }

    return true;
}
