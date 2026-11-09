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
 * Headless tests for the stage runtime's tweens, run via `node --test`. Every case of
 * tests/fixtures/tween_vectors.json was sampled, frame by frame at 60 fps, in the Phaser the plugin used to ship;
 * these tests build the same tweens in the DOM runtime and require the same values and the same frames for the
 * completion events.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {Runtime, FRAME_MS} = require('../../../amd/src/stage/runtime.js');
const Easing = require('../../../amd/src/stage/easing.js');

const vectors = require('../../fixtures/tween_vectors.json');

// How far a value may be from the sampled one, as a share of how far the tween travels. Phaser keeps its tween
// time in a way that leaves errors of about one part in a hundred thousand, which nothing on screen can show.
const TOLERANCE = 2e-4;

/**
 * Runs a case in the DOM runtime and returns what the vectors hold: a sample before every step and the frame each
 * completion event fired on.
 *
 * @param {object} vector One case of the vectors file.
 * @return {{frames: object[], events: object[]}}
 */
const play = vector => {
    const runtime = new Runtime();
    const target = {...vector.start};
    const events = [];
    let frameNo = 0;
    const launch = desc => runtime.tweens.add({
        targets: target,
        ...desc.tween,
        onComplete: () => {
            if (desc.event) {
                events.push({event: desc.event, frame: frameNo});
            }
            if (desc.then) {
                launch(desc.then);
            }
        },
    });
    launch(vector);
    const frames = [];
    const total = vector.frames.length - 1;
    for (frameNo = 0; frameNo <= total; frameNo++) {
        frames.push({...target});
        runtime.update(FRAME_MS);
    }
    return {frames, events};
};

// How far each property travels in a case, to scale the tolerance.
const travel = vector => {
    const range = {};
    const all = [vector.tween].concat(vector.then ? [vector.then.tween] : []);
    Object.keys(vector.start).forEach(key => {
        const distances = all.map(tween => {
            const end = tween[key] === undefined ? vector.start[key] : tween[key];
            return Math.abs(end - vector.start[key]);
        });
        range[key] = Math.max(...distances, 1e-9);
    });
    return range;
};

vectors.cases.forEach(vector => {
    test(`tween ${vector.name} gives the values and the completion frames Phaser gave`, () => {
        const {frames, events} = play(vector);
        assert.deepEqual(events, vector.events, 'completion events');
        const range = travel(vector);
        vector.frames.forEach((expected, i) => {
            Object.keys(vector.start).forEach(key => {
                const slack = Math.max(range[key] * TOLERANCE, 1e-6);
                assert.ok(
                    Math.abs(frames[i][key] - expected[key]) <= slack,
                    `${vector.name} frame ${i} ${key}: got ${frames[i][key]}, Phaser had ${expected[key]}`
                );
            });
        });
    });
});

test('the curves hold their end points and their middle', () => {
    Easing.names.forEach(name => {
        const curve = Easing.get(name);
        assert.ok(Math.abs(curve(0)) < 1e-12, `${name} at 0`);
        assert.ok(Math.abs(curve(1) - 1) < 1e-12, `${name} at 1`);
    });
    assert.equal(Easing.get('Quad.easeIn')(0.5), 0.25);
    assert.equal(Easing.get('Quad.easeOut')(0.5), 0.75);
    assert.ok(Math.abs(Easing.get('Sine.easeInOut')(0.5) - 0.5) < 1e-12);
    assert.equal(Easing.get(undefined), Easing.get('Linear'));
    assert.throws(() => Easing.get('Nope.easeIn'), /Unknown easing/);
});

test('stop() leaves the values where they are and never calls onComplete', () => {
    const runtime = new Runtime();
    const target = {x: 0};
    let completed = false;
    const tween = runtime.tweens.add({targets: target, x: 100, duration: 1000, onComplete: () => {
        completed = true;
    }});
    runtime.step(500);
    const where = target.x;
    assert.ok(where > 0 && where < 100);
    tween.stop();
    runtime.step(2000);
    assert.equal(target.x, where);
    assert.equal(completed, false);
    assert.equal(runtime.tweens.getTweens().length, 0);
});

test('killTweensOf() stops the tweens of one object only', () => {
    const runtime = new Runtime();
    const a = {x: 0};
    const b = {x: 0};
    runtime.tweens.add({targets: a, x: 100, duration: 1000});
    runtime.tweens.add({targets: b, x: 100, duration: 1000});
    runtime.step(200);
    runtime.tweens.killTweensOf(a);
    const frozen = a.x;
    runtime.step(300);
    assert.equal(a.x, frozen);
    assert.ok(b.x > frozen);
});

test('a tween on several targets moves them all, and onUpdate sees each update', () => {
    const runtime = new Runtime();
    const targets = [{y: 0}, {y: 10}];
    let updates = 0;
    runtime.tweens.add({targets, y: 20, duration: 100, onUpdate: () => {
        updates++;
    }});
    runtime.step(400);
    assert.deepEqual(targets.map(target => target.y), [20, 20]);
    assert.ok(updates >= 6);
});

test('a tween is read from where its target stands when it starts counting', () => {
    const runtime = new Runtime();
    const target = {x: 0};
    runtime.tweens.add({targets: target, x: 100, duration: 100});
    target.x = 50;
    runtime.step(1000);
    assert.equal(target.x, 100);
});
