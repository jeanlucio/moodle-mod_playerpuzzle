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
 * Checks that the committed parity fixtures still describe today's JavaScript engine, run via
 * `node --test`.
 *
 * The PHPUnit side (tests/local/engine/combat_vectors_test.php and replay_vectors_test.php)
 * only proves the server agrees with the fixtures. This file closes the other half: a change
 * to amd/src/engine/ that is not followed by `node tests/js/engine/generate_vectors.js` fails
 * here, instead of leaving the PHP test checking the server against a JavaScript engine that
 * no longer exists.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const Generator = require('./generate_vectors.js');
const ReplayMirror = require('./replay_mirror.js');

test('combat_vectors.json matches what the JavaScript engine returns today', () => {
    const committed = fs.readFileSync(Generator.COMBAT_FIXTURE, 'utf8');
    assert.equal(committed, Generator.serialiseCalls(Generator.buildCombatVectors()));
});

test('replay_vectors.json matches what the JavaScript engine records today', () => {
    const committed = fs.readFileSync(Generator.REPLAY_FIXTURE, 'utf8');
    assert.equal(committed, Generator.serialise(Generator.buildReplayVectors()));
});

test('every recorded match verifies through the mirror as recorded', () => {
    for (const vector of JSON.parse(fs.readFileSync(Generator.REPLAY_FIXTURE, 'utf8'))) {
        const derived = ReplayMirror.derive({
            attempt: vector.attempt,
            playerpuzzle: vector.playerpuzzle,
            events: vector.events,
            outcomes: vector.outcomes,
            serverUses: vector.serveruses,
            poolEmpty: true,
        });
        assert.deepEqual(derived, vector.expected, vector.name);
    }
});
