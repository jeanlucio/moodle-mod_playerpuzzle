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
 * Headless test that the two drawings of the HUD, the canvas one (ui.js) and the one made of elements (hud.js), answer
 * to the same calls. The game talks to its HUD through the calls found in combat.js, board.js, game_boot.js and
 * scene_dom.js; every one of them has to exist in both files for the game to run on either.
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
const read = name => fs.readFileSync(path.join('amd', 'src', name), 'utf8');

/**
 * The methods a class file defines: a name at the indent of a method, followed by its parameters and an opening brace.
 *
 * @param {string} source The file.
 * @return {Set<string>}
 */
const definedMethods = source => {
    const found = new Set();
    for (const match of source.matchAll(/^ {8}(?:async )?([a-zA-Z]\w*)\([^)]*\) \{$/gm)) {
        found.add(match[1]);
    }
    return found;
};

/**
 * The calls the game makes on its HUD, wherever it holds it.
 *
 * @param {string[]} files The files that call it.
 * @return {Set<string>}
 */
const calledOnHud = files => {
    const found = new Set();
    files.forEach(name => {
        for (const match of read(name).matchAll(/(?:\.ui|\bui)\.([a-zA-Z]\w*)\(/g)) {
            found.add(match[1]);
        }
    });
    return found;
};

const callers = ['combat.js', 'board.js', 'game_boot.js', 'scene_dom.js'];
const called = calledOnHud(callers);
const canvas = definedMethods(read('ui.js'));
const elements = definedMethods(read('hud.js'));

test('the game calls a good number of things on its HUD, so this test is looking in the right place', () => {
    assert.ok(called.size >= 15, `found only ${[...called].join(', ')}`);
    ['pushHistoryLog', 'updatePlayerBar', 'updateBossBar', 'showTurn', 'dimStage'].forEach(name => {
        assert.ok(called.has(name), `${name} is not among the calls found`);
    });
});

test('every call the game makes on its HUD exists in the canvas drawing', () => {
    const missing = [...called].filter(name => !canvas.has(name));
    assert.deepEqual(missing, []);
});

// The canvas drawing loads its own images, through Phaser's loader; the one made of elements has them loaded by the
// scene before it exists.
const ofTheCanvasLoader = new Set(['setupLoader']);

test('every call the game makes on its HUD exists in the drawing made of elements', () => {
    const missing = [...called].filter(name => !ofTheCanvasLoader.has(name) && !elements.has(name));
    assert.deepEqual(missing, []);
});

test('the drawing made of elements answers to every call the canvas drawing answers to, but the ones of the canvas itself', () => {
    // What belongs to the canvas: its loading bar, its font, its double tap guard and the shapes it draws buttons from.
    const ofTheCanvas = new Set([
        'setupLoader', 'useUiFont', 'guardAgainstDoubleTap', 'hpBarCorners', 'createButtonBadge', 'createMusicIcon',
        'createGlyphIcon', 'createEffectsIcon', 'moveCharacter',
    ]);
    const missing = [...canvas].filter(name => !ofTheCanvas.has(name) && !elements.has(name));
    assert.deepEqual(missing, []);
});
