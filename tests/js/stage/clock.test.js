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
 * Headless tests for the stage runtime's clock and its pause, run via `node --test`. The cases of
 * tests/fixtures/clock_vectors.json were sampled in the Phaser the plugin used to ship; these tests schedule the
 * same calls in the DOM runtime and require them to fire on the same frames, with the same arguments, in the same
 * order.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {Runtime, FRAME_MS} = require('../../../amd/src/stage/runtime.js');

const vectors = require('../../fixtures/clock_vectors.json');

vectors.cases.forEach(vector => {
    test(`clock ${vector.name} fires on the frames Phaser fired on`, () => {
        const runtime = new Runtime();
        const events = [];
        let frameNo = 0;
        const made = {};
        const log = name => events.push({event: name, frame: frameNo});
        const schedule = spec => {
            made[spec.id || spec.log] = runtime.time.addEvent({
                delay: spec.delay,
                loop: Boolean(spec.loop),
                args: spec.args,
                callbackScope: spec.scope,
                callback: function(...args) {
                    log(spec.args ? `${spec.log}:${args.join('')}${this.tag}` : spec.log);
                    if (spec.cancel) {
                        made[spec.cancel].remove(false);
                    }
                    if (spec.then) {
                        schedule(spec.then);
                    }
                },
            });
        };
        vector.calls.forEach(schedule);
        const nows = [];
        for (frameNo = 0; frameNo < vector.nowSinceStart.length; frameNo++) {
            nows.push(Math.round(runtime.time.now * 1000) / 1000);
            runtime.update(FRAME_MS);
        }
        assert.deepEqual(events, vector.events);
        assert.deepEqual(nows, vector.nowSinceStart);
    });
});

test('a pause freezes the tweens and the clock, and what was waiting fires on the same frame as in Phaser', () => {
    const {pause} = vectors;
    const runtime = new Runtime();
    const target = {x: 0};
    const events = [];
    let frameNo = 0;
    runtime.tweens.add({targets: target, ...pause.tween});
    runtime.time.delayedCall(pause.call.delay, () => events.push({event: 'fired', frame: frameNo}));
    const series = [];
    for (frameNo = 0; frameNo <= pause.frames; frameNo++) {
        if (frameNo === pause.pauseAtFrame) {
            runtime.pause();
        }
        if (frameNo === pause.resumeAtFrame) {
            runtime.resume();
        }
        series.push({frame: frameNo, x: target.x, now: runtime.time.now});
        runtime.update(FRAME_MS);
    }
    assert.deepEqual(events, pause.events);
    const frozen = series[pause.pauseAtFrame];
    for (let i = pause.pauseAtFrame; i <= pause.resumeAtFrame; i++) {
        assert.equal(series[i].x, frozen.x, `x while paused, frame ${i}`);
        assert.equal(series[i].now, frozen.now, `now while paused, frame ${i}`);
    }
    // Phaser let the first update after a pause move tweens by about two frames at once; here time simply goes on,
    // so the values catch up to Phaser's from the second frame after the pause, within one frame of travel.
    const oneFrame = FRAME_MS;
    pause.series.slice(pause.resumeAtFrame + 3).forEach(expected => {
        const got = series[expected.frame].x;
        assert.ok(Math.abs(got - expected.x) <= 2 * oneFrame + 1e-3, `frame ${expected.frame}: ${got} vs ${expected.x}`);
    });
});

test('step() moves time in frames and ends on the exact amount', () => {
    const runtime = new Runtime();
    let fired = 0;
    runtime.time.delayedCall(100, () => {
        fired++;
    });
    runtime.step(99);
    assert.equal(fired, 0);
    assert.ok(Math.abs(runtime.time.now - 99) < 1e-9);
    runtime.step(2);
    assert.equal(fired, 1);
    assert.ok(Math.abs(runtime.time.now - 101) < 1e-9);
});

test('removeAllEvents() drops the waiting calls too', () => {
    const runtime = new Runtime();
    let fired = 0;
    runtime.time.delayedCall(50, () => {
        fired++;
    });
    runtime.time.removeAllEvents();
    runtime.step(500);
    assert.equal(fired, 0);
});

test('the loop moves time by the real time between frames and never by more than a hundred milliseconds', () => {
    let tick = null;
    const runtime = new Runtime({
        requestFrame: callback => {
            tick = callback;
            return 1;
        },
        cancelFrame: () => {
            tick = null;
        },
    });
    runtime.start();
    tick(1000);
    tick(1016);
    assert.equal(runtime.time.now, 16);
    tick(1016 + 5000);
    assert.equal(runtime.time.now, 116);
    runtime.stop();
    assert.equal(tick, null);
});
