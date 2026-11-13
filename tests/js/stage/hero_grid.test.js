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
 * Headless test of how the hero choice screen lays its cards out: wrapped and centred, so that the cards of an
 * incomplete last row do not sit at the left with an empty space beside them.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

// The tests run from the plugin's own directory.
const css = fs.readFileSync('styles.css', 'utf8');

test('the hero cards wrap and are centred, so an incomplete last row stands in the middle', () => {
    const rule = css.match(/\.pp-hero-choices \{([^}]*)\}/);

    assert.ok(rule, 'the hero cards have no container rule');
    assert.match(rule[1], /display:\s*flex/);
    assert.match(rule[1], /flex-wrap:\s*wrap/);
    assert.match(rule[1], /justify-content:\s*center/);
    // A grid would put the last row at the start, whatever else is set.
    assert.doesNotMatch(rule[1], /display:\s*grid/);
});
