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
 * Headless test that the vertical (phone) layout offers every use button the horizontal one does, and that the
 * student's row of resources fits them. The shop sells each consumable once, whatever the screen: an item with no
 * button in one layout is stock a student paid for and cannot use there. The buttons of the rings sit under them in
 * both layouts, clear of what is around them, so they hide none of the arc.
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
const vm = require('node:vm');

// The tests run from the plugin's own directory.
const read = name => fs.readFileSync(path.join('amd', 'src', name), 'utf8');

/**
 * The layout module, loaded the way the AMD loader does: its file calls define() with no dependencies.
 *
 * @param {boolean} isDesk Whether to build the horizontal layout.
 * @return {object}
 */
const layoutFor = isDesk => {
    let factory = null;
    vm.runInNewContext(read('layout.js'), {
        define: (deps, build) => {
            factory = build;
        },
    });
    return factory().build(isDesk);
};

/**
 * The body of a method of hud.js: from its signature to the closing brace at the same indent.
 *
 * @param {string} name The method.
 * @return {string}
 */
const methodBody = name => {
    const match = read('hud.js').match(new RegExp(`^ {8}${name}\\(\\) \\{\\n([\\s\\S]*?)^ {8}\\}$`, 'm'));
    assert.ok(match, `${name} was not found in hud.js`);
    return match[1];
};

/**
 * The consumable types a stage builder puts a use button on.
 *
 * @param {string} method The builder.
 * @return {string[]}
 */
const buttonTypes = method => [...methodBody(method).matchAll(/createPurchaseBadge\([^)]*?, '(\w+)'/g)]
    .map(match => match[1])
    .sort();

test('the vertical layout has a use button for every consumable the horizontal one has', () => {
    const horizontal = buttonTypes('buildLandscapeStage');
    assert.deepEqual(horizontal, ['magic', 'potion', 'shield', 'sword']);
    assert.deepEqual(buttonTypes('buildPortraitStage'), horizontal);
});

/**
 * The rectangle a use button occupies, as hud.js::createPurchaseBadge() places it: centred 20 * scale to the right
 * of and below its icon, 46 x 34 at scale 1.
 *
 * @param {number} x Centre of the icon.
 * @param {number} y Centre of the icon.
 * @param {number} scale The badge scale.
 * @return {{left: number, right: number, top: number, bottom: number}}
 */
const badgeRect = (x, y, scale) => ({
    left: x + (20 * scale) - (23 * scale),
    right: x + (20 * scale) + (23 * scale),
    top: y + (20 * scale) - (17 * scale),
    bottom: y + (20 * scale) + (17 * scale),
});

/**
 * The rectangle the use button of a ring occupies, as hud.js::createPurchaseBadge() places it: centred under the
 * ring, its top 10 * scale behind the ring's outer edge, 46 x 34 at scale 1.
 *
 * @param {object} L The layout.
 * @param {number} x Centre of the ring.
 * @param {number} y Centre of the ring.
 * @param {number} scale The badge scale.
 * @return {{left: number, right: number, top: number, bottom: number}}
 */
const ringBadgeRect = (L, x, y, scale) => {
    const top = y + L.ringRadius + (L.ringThickness / 2) - (10 * scale);
    return {left: x - (23 * scale), right: x + (23 * scale), top, bottom: top + (34 * scale)};
};

/**
 * The square a ring occupies, its stroke included.
 *
 * @param {object} L The layout.
 * @param {number} x Centre of the ring.
 * @param {number} y Centre of the ring.
 * @return {{left: number, right: number, top: number, bottom: number}}
 */
const ringRect = (L, x, y) => {
    const reach = L.ringRadius + (L.ringThickness / 2);
    return {left: x - reach, right: x + reach, top: y - reach, bottom: y + reach};
};

const overlap = (a, b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;

test('the student row of the vertical layout fits its icons, rings and use buttons without overlap', () => {
    const L = layoutFor(false);
    const half = L.resourceIconSize / 2;
    const icon = (x, y) => ({left: x - half, right: x + half, top: y - half, bottom: y + half});
    const ring = x => ringRect(L, x, L.playerRingY);

    const items = {
        potion: icon(L.potionX, L.potionY),
        potionButton: badgeRect(L.potionX, L.potionY, L.badgeScale),
        sword: icon(L.swordX, L.swordY),
        swordButton: badgeRect(L.swordX, L.swordY, L.badgeScale),
        grimoire: ring(L.playerGrimoireX),
        grimoireButton: ringBadgeRect(L, L.playerGrimoireX, L.playerRingY, L.badgeScale),
        shield: ring(L.playerShieldRingX),
        shieldButton: ringBadgeRect(L, L.playerShieldRingX, L.playerRingY, L.badgeScale),
        orb: ring(L.playerOrbX),
    };

    const names = Object.keys(items);
    names.forEach((first, index) => {
        names.slice(index + 1).forEach(second => {
            // A button sits on the lower corner of its own icon by design.
            if (first === second.replace('Button', '') || second === first.replace('Button', '')) {
                return;
            }
            assert.ok(!overlap(items[first], items[second]), `${first} overlaps ${second}`);
        });
        assert.ok(items[first].left >= 0 && items[first].right <= L.w, `${first} leaves the stage`);
        assert.ok(items[first].bottom <= L.panelBottomPlayer, `${first} leaves the student's panel`);
    });
    assert.ok(L.panelBottomPlayer <= L.historyBtnY, "the student's panel runs into the history button");
});

test('the use buttons of the rings sit under them in both layouts, so the arcs stay in sight', () => {
    ['buildLandscapeStage', 'buildPortraitStage'].forEach(method => {
        const calls = [...methodBody(method).matchAll(/createPurchaseBadge\(([^)]*)\)/g)].map(match => match[1]);
        calls.forEach(args => {
            const onRing = /'(magic|shield)'/.test(args);
            assert.equal(/,\s*true$/.test(args.trim()), onRing, `${method}: ${args}`);
        });
    });
});

test('in the horizontal layout the use buttons of the rings stay clear of the history, the coins and the stars', () => {
    const L = layoutFor(true);
    const banner = {
        left: L.playerUiX,
        right: L.playerUiX + (L.historyBannerH * 1.738),
        top: L.historyTitleY - (L.historyBannerH / 2),
        bottom: L.historyTitleY + (L.historyBannerH / 2),
    };
    const chip = (x, y) => ({
        left: x - (L.indicatorIconSize / 2),
        right: x + 60,
        top: y - (L.indicatorIconSize / 2),
        bottom: y + (L.indicatorIconSize / 2),
    });
    const others = {banner, gold: chip(L.goldX, L.goldY), star: chip(L.starX, L.starY)};
    const rings = {grimoire: L.playerGrimoireX, shield: L.playerShieldRingX, orb: L.playerOrbX};
    [['grimoire', L.playerGrimoireX], ['shield', L.playerShieldRingX]].forEach(([name, x]) => {
        const button = ringBadgeRect(L, x, L.playerRingY, 1);
        Object.entries(others).forEach(([other, rect]) => {
            assert.ok(!overlap(button, rect), `the ${name} button overlaps the ${other}`);
        });
        Object.entries(rings).filter(([other]) => other !== name).forEach(([other, ringX]) => {
            assert.ok(!overlap(button, ringRect(L, ringX, L.playerRingY)), `the ${name} button overlaps the ${other} ring`);
        });
    });
});

test('the use buttons keep a clickable area of at least 24 x 24 screen pixels whatever the stage scale', () => {
    const css = fs.readFileSync('styles.css', 'utf8');
    const rule = css.match(/\.pp-badge-btn::after \{([^}]*)\}/);

    assert.ok(rule, 'the badge has no layer that grows its clickable area');
    // Both axes are grown from the stage scale, so a stage shrunk on a phone still reaches the minimum.
    assert.match(rule[1], /inset-block:[^;]*24px[^;]*--pp-stage-scale[^;]*--h/);
    assert.match(rule[1], /inset-inline:[^;]*24px[^;]*--pp-stage-scale[^;]*--w/);
});
