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
 * Headless tests of what the HUD shows of a status effect on a fighter (Hex, an armed Shield): the labels beside the
 * character, the auras around it and the announcements to the live region, run via `node --test` against a stand-in
 * for the page. They are about what the HUD asks the page to do, not how the page draws it.
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

/** A stand-in for a DOM element: what the HUD sets on it is kept to be looked at. */
const element = (tag = 'div') => {
    const props = new Map();
    const node = {
        tag,
        className: '',
        hidden: false,
        children: [],
        style: {setProperty: (name, value) => props.set(name, value)},
        attributes: {},
        textContent: '',
        src: '',
        alt: '',
        draggable: true,
        props,
        setAttribute(name, value) {
            this.attributes[name] = value;
        },
        append(...nodes) {
            this.children.push(...nodes);
        },
        appendChild(child) {
            this.children.push(child);
            return child;
        },
        before() {
            // Where the picture sits among its siblings does not matter to these tests.
        },
    };
    return node;
};
globalThis.document = {createElement: element, createElementNS: (ns, tag) => element(tag)};

// The module is an AMD file: its factory is given the stand-ins of what it needs.
let HudHandler;
const announcements = [];
const placed = [];
globalThis.define = (deps, factory) => {
    HudHandler = factory({}, {}, {announce: text => announcements.push(text)}, {place: (el, box) => placed.push([el, box])});
};
// eslint-disable-next-line no-eval
(0, eval)(fs.readFileSync(path.join('amd', 'src', 'hud.js'), 'utf8'));

const layout = {
    statusLabelW: 126, statusLabelH: 28, statusLabelGap: 33, statusLabelY: 108, statusLabelPlayerX: 150,
    statusLabelBossRight: 420, statusLabelFont: 16,
};
const strings = {
    'stage_status_hex': 'Feitiço {$a}',
    'stage_status_shield': 'Escudo',
    'stage_status_you': 'você',
    'stage_status_opponent': 'o oponente',
    'stage_status_hex_on': 'Feitiço sobre {$a->who}: {$a->rounds} rodadas.',
    'stage_status_hex_off': 'Feitiço acabou: {$a}.',
    'stage_status_shield_on': 'Escudo armado: {$a}.',
    'stage_status_shield_off': 'Escudo usado: {$a}.',
};

/** A HUD with its auras in place, as createCharacters() leaves them. */
const hud = () => {
    const layers = {back: element(), middle: element(), front: element()};
    const scene = {stage: {layers}, imageUrl: key => `/img/${key}.png`};
    const handler = new HudHandler(scene, layout, {opponentname: 'Goblin'}, strings);
    handler.auras = {player: {hex: element(), shield: element()}, boss: {hex: element(), shield: element()}};
    announcements.length = 0;
    placed.length = 0;
    return handler;
};

test('an effect that is not on shows nothing and says nothing, and the first look at a fighter is not news', () => {
    const handler = hud();

    handler.updateStatusEffects('player', 0, false);

    const state = handler.statusEffects.player;
    assert.equal(state.hex.root.hidden, true);
    assert.equal(state.shield.root.hidden, true);
    assert.equal(handler.auras.player.hex.hidden, true);
    assert.equal(handler.auras.player.shield.hidden, true);
    assert.deepEqual(announcements, []);
});

test('a Hex shows its aura and a label with the rounds left, beside the student on the side facing the opponent', () => {
    const handler = hud();
    handler.updateStatusEffects('player', 0, false);

    handler.updateStatusEffects('player', 3, false);

    const state = handler.statusEffects.player;
    assert.equal(handler.auras.player.hex.hidden, false);
    assert.equal(handler.auras.player.shield.hidden, true);
    assert.equal(state.hex.root.hidden, false);
    assert.equal(state.hex.text.textContent, 'Feitiço 3');
    assert.deepEqual(placed.find(([el]) => el === state.hex.root)[1], {x: 150, y: 108, w: 126, h: 28});
    assert.deepEqual(announcements, ['Feitiço sobre você: 3 rodadas.']);
});

test('the labels stack from the top and the opponent\'s sit against the side that faces the student', () => {
    const handler = hud();
    handler.updateStatusEffects('boss', 0, false);

    handler.updateStatusEffects('boss', 2, true);

    const state = handler.statusEffects.boss;
    assert.deepEqual(placed.find(([el]) => el === state.hex.root)[1], {x: 420 - 126, y: 108, w: 126, h: 28});
    assert.deepEqual(placed.find(([el]) => el === state.shield.root)[1], {x: 420 - 126, y: 108 + 33, w: 126, h: 28});
    assert.deepEqual(announcements, ['Feitiço sobre Goblin: 2 rodadas.', 'Escudo armado: Goblin.']);
});

test('with only the Shield on, its label takes the first row', () => {
    const handler = hud();
    handler.updateStatusEffects('player', 0, false);

    handler.updateStatusEffects('player', 0, true);

    const state = handler.statusEffects.player;
    assert.deepEqual(placed.find(([el]) => el === state.shield.root)[1], {x: 150, y: 108, w: 126, h: 28});
    assert.equal(state.hex.root.hidden, true);
});

test('the rounds counting down are not announced again, and the end of each effect is', () => {
    const handler = hud();
    handler.updateStatusEffects('player', 0, false);
    handler.updateStatusEffects('player', 3, true);
    announcements.length = 0;

    handler.updateStatusEffects('player', 2, true);
    handler.updateStatusEffects('player', 1, true);
    assert.deepEqual(announcements, []);
    assert.equal(handler.statusEffects.player.hex.text.textContent, 'Feitiço 1');

    handler.updateStatusEffects('player', 0, true);
    handler.updateStatusEffects('player', 0, false);
    assert.deepEqual(announcements, ['Feitiço acabou: você.', 'Escudo usado: você.']);
    assert.equal(handler.auras.player.hex.hidden, true);
    assert.equal(handler.auras.player.shield.hidden, true);
});

test('the opponent is named "the opponent" when the game does not know its name', () => {
    const handler = hud();
    handler.gameConfig = {};
    handler.updateStatusEffects('boss', 0, false);

    handler.updateStatusEffects('boss', 0, true);

    assert.deepEqual(announcements, ['Escudo armado: o oponente.']);
});

test('the labels are drawn for the eye only: the live region speaks for them', () => {
    const handler = hud();
    handler.updateStatusEffects('player', 0, false);

    const state = handler.statusEffects.player;
    assert.equal(state.hex.root.attributes['aria-hidden'], 'true');
    assert.equal(state.shield.root.attributes['aria-hidden'], 'true');
    assert.equal(state.hex.root.style.fontSize, '16px');
});
