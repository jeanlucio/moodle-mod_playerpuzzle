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
 * Headless test that the HUD (hud.js) answers to every call the game makes on it. The game talks to its HUD through
 * the calls found in combat.js, board.js and scene_dom.js; a call with no method behind it only fails when that
 * moment of the match is reached, so this finds it without playing one.
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

const callers = ['combat.js', 'board.js', 'scene_dom.js'];
const called = calledOnHud(callers);
const elements = definedMethods(read('hud.js'));

test('the game calls a good number of things on its HUD, so this test is looking in the right place', () => {
    assert.ok(called.size >= 15, `found only ${[...called].join(', ')}`);
    ['pushHistoryLog', 'updatePlayerBar', 'updateBossBar', 'showTurn', 'dimStage'].forEach(name => {
        assert.ok(called.has(name), `${name} is not among the calls found`);
    });
});

test('every call the game makes on its HUD exists in the HUD', () => {
    const missing = [...called].filter(name => !elements.has(name));
    assert.deepEqual(missing, []);
});

test('the use buttons show what can still be used in the fight, never an infinity sign', () => {
    const body = read('hud.js').match(/^ {8}updateConsumableBadges\(\) \{\n([\s\S]*?)^ {8}\}$/m);

    assert.ok(body, 'updateConsumableBadges() was not found in hud.js');
    // The phase's limit holds in a Demo, so a button that dims after three uses cannot say it has no end.
    assert.ok(!body[1].includes('∞'), 'a Demo button shows an infinity sign');
    assert.match(body[1], /const left = Math\.max\(0, combat\.phaseLimit\(type\) - uses\);/);
    // A real attempt shows its stock, but never more than the phase's limit still lets it use.
    assert.match(body[1], /String\(combat\.isdemo \? left : Math\.min\(owned, left\)\)/);
    // A Shield already armed cannot take another, so its button dims.
    assert.match(body[1], /type === 'shield' && combat\.playerShieldReady/);
});

test('every layout constant the status labels read is defined by both layouts', () => {
    const used = new Set([...read('hud.js').matchAll(/\bL\.(statusLabel\w+)/g)].map(match => match[1]));
    const layout = read('layout.js');

    assert.ok(used.size >= 6, `found only ${[...used].join(', ')}`);
    used.forEach(name => {
        const definitions = [...layout.matchAll(new RegExp(`\\b${name}:`, 'g'))].length;
        assert.equal(definitions, 2, `${name} is defined ${definitions} time(s) in layout.js, not once for each layout`);
    });
});

test('the texts the status effects use are strings the game loads, and each exists in both languages', () => {
    const used = new Set([...read('hud.js').matchAll(/\bstrings\.(stage_status_\w+)/g)].map(match => match[1]));
    const boot = read('game_boot.js');
    const langs = ['en', 'pt_br'].map(lang => fs.readFileSync(path.join('lang', lang, 'playerpuzzle.php'), 'utf8'));

    assert.ok(used.size >= 8, `found only ${[...used].join(', ')}`);
    used.forEach(name => {
        assert.ok(boot.includes(`'${name}'`), `${name} is not loaded by game_boot.js`);
        langs.forEach(lang => assert.ok(lang.includes(`$string['${name}']`), `${name} is missing from a lang file`));
    });
});

test('the old status badges on the HP bars are gone', () => {
    const hud = read('hud.js');
    assert.ok(!hud.includes('updateStatusBadges'), 'updateStatusBadges() is still in hud.js');
    assert.ok(hud.includes('updateStatusEffects'), 'updateStatusEffects() is missing from hud.js');
});
