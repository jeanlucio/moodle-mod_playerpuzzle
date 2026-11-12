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
 * Headless test that the game's modules leave nothing on the page's global object. A handle left there (the scene,
 * for one) is readable and writable from the browser console and can clash with another script on the page.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// The tests run from the plugin's own directory.
const sources = dir => fs.readdirSync(dir, {withFileTypes: true}).flatMap(entry => {
    const file = path.join(dir, entry.name);
    if (entry.isDirectory()) {
        return sources(file);
    }
    return file.endsWith('.js') ? [file] : [];
});

test('no module assigns a property on window', () => {
    const files = sources(path.join('amd', 'src'));
    assert.ok(files.length > 10, 'the sources were not found');
    const assigned = files.flatMap(file => [...fs.readFileSync(file, 'utf8').matchAll(/\bwindow\.(\w+)\s*=[^=]/g)]
        .map(match => `${file}: window.${match[1]}`));
    assert.deepEqual(assigned, []);
});
