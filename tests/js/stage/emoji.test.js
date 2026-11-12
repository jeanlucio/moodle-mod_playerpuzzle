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
 * Headless tests for the emoji helpers the accessibility code uses to keep pictographs out of what a screen reader
 * announces, run via `node --test`.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const Emoji = require('../../../amd/src/stage/emoji.js');

test('strip() takes the pictographs out of a button label and a title the game really has', () => {
    assert.equal(Emoji.strip('⚔️ Atacar!'), 'Atacar!');
    assert.equal(Emoji.strip('💡 Dica: compre no Lobby'), 'Dica: compre no Lobby');
    assert.equal(Emoji.strip('💔 DERROTA 💔 Moedas coletadas: 0.'), 'DERROTA Moedas coletadas: 0.');
    assert.equal(Emoji.strip('🌟 VITÓRIA! 🌟'), 'VITÓRIA!');
});

test('strip() leaves a text without pictographs exactly as it was', () => {
    ['Seu turno. 3 jogadas disponíveis.', 'Sword x2 → 30 ∞', 'x1.5', '', 'Ação: 100% 7/10'].forEach(text => {
        assert.equal(Emoji.strip(text), text);
    });
});

test('strip() takes the check and cross marks off the right and wrong messages', () => {
    assert.equal(Emoji.strip('✓ Correto! O oponente sofre 30 de dano!'), 'Correto! O oponente sofre 30 de dano!');
    assert.equal(Emoji.strip('✗ Errou! A resposta certa está em verde.'), 'Errou! A resposta certa está em verde.');
    assert.equal(Emoji.strip('✔️ ok'), 'ok');
    assert.equal(Emoji.has('✓ EAD'), true);
});

test('a pictograph with a variation selector, a skin tone or a joiner is one run, with nothing left over', () => {
    assert.equal(Emoji.strip('a⚔️b'), 'ab');
    assert.equal(Emoji.strip('a👍🏽b'), 'ab');
    assert.equal(Emoji.strip('a👩‍🚀b'), 'ab');
    assert.equal(Emoji.strip('🪙 3 🪙'), '3');
});

test('split() cuts a text into its runs in order and joins back to the same text', () => {
    const text = '🚪 Sair do jogo';
    const parts = Emoji.split(text);
    assert.deepEqual(parts, [{text: '🚪', emoji: true}, {text: ' Sair do jogo', emoji: false}]);
    assert.equal(parts.map(part => part.text).join(''), text);
    const middle = Emoji.split('Vitória 🏆 final');
    assert.deepEqual(middle.map(part => part.emoji), [false, true, false]);
});

test('has() tells a text with a pictograph from one without', () => {
    assert.equal(Emoji.has('Atacar ⚔️'), true);
    assert.equal(Emoji.has('Atacar'), false);
    assert.equal(Emoji.has('Sword x2 → 30 ∞ correta'), false);
});
