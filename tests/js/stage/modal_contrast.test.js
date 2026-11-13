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
 * Headless test of the contrast of the answers in the question window once they are judged. The answer buttons are
 * disabled by then, and Bootstrap fades a disabled button; the theme keeps them at full strength and gives what the
 * opponent got wrong a colour pair of its own. The ratios are worked out from the colours the stylesheet declares.
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
const combat = fs.readFileSync('amd/src/combat.js', 'utf8');

const token = name => {
    const match = css.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`));
    assert.ok(match, `--${name} is not declared`);
    return match[1];
};

const luminance = hex => {
    const [r, g, b] = [1, 3, 5].map(at => parseInt(hex.slice(at, at + 2), 16) / 255)
        .map(v => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
    return (0.2126 * r) + (0.7152 * g) + (0.0722 * b);
};

const contrast = (a, b) => {
    const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (high + 0.05) / (low + 0.05);
};

const ruleOf = selector => {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\#]/g, '\\$&');
    const match = css.match(new RegExp(`${escaped}\\s*\\{([^}]*)\\}`));
    assert.ok(match, `${selector} has no rule`);
    return match[1];
};

const varIn = (body, property) => {
    // The property by itself: a plain `color` must not match the `border-color` before it.
    const match = body.match(new RegExp(`(?:^|[\\s;{])${property}:\\s*var\\(--(pp-[a-z-]+)\\)`));
    assert.ok(match, `${property} is not a theme colour`);
    return token(match[1]);
};

test('what the opponent got wrong is legible: parchment text on stone, at 4.5:1 or more', () => {
    const rule = ruleOf('body.path-mod-playerpuzzle .pp-question-modal button.btn-secondary.pp-boss-miss');

    assert.ok(contrast(varIn(rule, 'color'), varIn(rule, 'background')) >= 4.5);
});

test('the answer that hurt keeps white text on brick, at 4.5:1 or more', () => {
    const rule = ruleOf('body.path-mod-playerpuzzle .pp-question-modal .btn-danger');

    assert.ok(contrast('#ffffff', varIn(rule, 'background')) >= 4.5);
});

test('the other options are ink on parchment, at 4.5:1 or more, and a judged answer is not faded', () => {
    assert.ok(contrast(token('pp-ink'), token('pp-parchment')) >= 4.5);
    assert.match(
        ruleOf('body.path-mod-playerpuzzle .pp-question-modal #playerpuzzle-answers-container .btn:disabled'),
        /opacity:\s*1\b/
    );
});

test('the opponent\'s wrong answer is not forced to white text over a pale button', () => {
    assert.match(combat, /addClass\('btn-secondary pp-boss-miss'\)/);
    assert.doesNotMatch(combat, /btn-secondary text-white/);
});
