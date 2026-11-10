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
 * Headless tests for the stage's image preloader and its sound, run via `node --test`, with the browser objects
 * replaced by fakes.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {preloadImages} = require('../../../amd/src/stage/assets.js');
const Sound = require('../../../amd/src/stage/sound.js');

test('preloadImages() reports each file as it arrives and returns the images by key', async() => {
    const reports = [];
    const images = await preloadImages(
        [{key: 'a', url: 'a.png'}, {key: 'b', url: 'b.png'}, {key: 'c', url: 'c.png'}],
        {load: async url => ({src: url}), onProgress: (loaded, total, key) => reports.push([loaded, total, key])}
    );
    assert.deepEqual(Object.keys(images).sort(), ['a', 'b', 'c']);
    assert.equal(images.b.src, 'b.png');
    assert.deepEqual(reports.map(report => report[0]), [1, 2, 3]);
    assert.ok(reports.every(report => report[1] === 3));
});

test('preloadImages() fails when one file fails', async() => {
    await assert.rejects(
        preloadImages([{key: 'a', url: 'a.png'}, {key: 'b', url: 'missing.png'}], {
            load: async url => {
                if (url === 'missing.png') {
                    throw new Error('Could not load missing.png');
                }
                return {src: url};
            },
        }),
        /Could not load missing.png/
    );
});

/**
 * Builds a sound with a fake browser: an audio context that starts suspended, a music element that refuses to play
 * until the context has been resumed, and a way to send the first gesture.
 *
 * @param {object} [options] Passed to Sound.create().
 * @return {object} The sound and the fakes.
 */
const fakeSound = (options = {}) => {
    const played = [];
    const state = {gesture: null, musicFetched: 0, unlockedByGesture: false, gestures: 0, listening: true, resumeFails: 0};
    const context = {
        state: 'suspended',
        destination: {},
        decodeAudioData: async bytes => ({bytes}),
        resume: async() => {
            if (state.resumeFails > 0) {
                state.resumeFails--;
                throw new Error('The browser did not take that touch as an activation');
            }
            context.state = 'running';
        },
        createGain: () => ({gain: {value: 1}, connect: () => null}),
        createBufferSource: () => {
            const source = {connect: () => null, start: () => played.push(source), volumeAtStart: null};
            return source;
        },
    };
    const music = {
        loop: false,
        volume: 0,
        plays: 0,
        paused: false,
        play: async() => {
            if (!state.unlockedByGesture) {
                throw new Error('NotAllowedError');
            }
            music.plays++;
            music.paused = false;
        },
        pause: () => {
            music.paused = true;
        },
    };
    const sound = Sound.create({
        createContext: () => context,
        fetchBuffer: async url => url,
        createMusic: url => {
            state.musicFetched++;
            music.url = url;
            return music;
        },
        // The way the browser's helper does it: the sound is told of every gesture until it answers true.
        onGesture: callback => {
            state.gesture = async() => {
                if (!state.listening) {
                    return;
                }
                state.gestures++;
                state.unlockedByGesture = true;
                if (await callback()) {
                    state.listening = false;
                }
            };
        },
    }, options);
    return {sound, context, music, played, state};
};

test('effects stay silent until the first gesture unlocks the browser, then play at their own volume', async() => {
    const {sound, context, played, state} = fakeSound();
    await sound.loadEffects({swap: 'swap.mp3', match: 'match.mp3', hit: 'hit.mp3'});
    sound.playEffect('swap');
    assert.equal(played.length, 0);
    await state.gesture();
    assert.equal(context.state, 'running');
    assert.equal(sound.isUnlocked(), true);
    sound.playEffect('swap');
    assert.equal(played.length, 1);
});

test('effects off means no sound, and on again brings it back', async() => {
    const {sound, played, state} = fakeSound();
    await sound.loadEffects({hit: 'hit.mp3'});
    await state.gesture();
    sound.setEffects(false);
    sound.playEffect('hit');
    assert.equal(played.length, 0);
    sound.setEffects(true);
    sound.playEffect('hit');
    assert.equal(played.length, 1);
});

test('the music is not fetched until it is asked for, and plays on the gesture that unlocks it', async() => {
    const {sound, music, state} = fakeSound();
    await sound.loadEffects({hit: 'hit.mp3'});
    sound.setMusicUrl('music.mp3');
    assert.equal(state.musicFetched, 0);
    await sound.startMusic();
    assert.equal(state.musicFetched, 1);
    assert.equal(music.plays, 0, 'refused before the gesture');
    await state.gesture();
    assert.equal(music.plays, 1);
    assert.equal(music.loop, true);
    assert.equal(music.volume, Sound.MUSIC_VOLUME);
});

test('music off pauses it and never fetches it when it was never on', async() => {
    const {sound, music, state} = fakeSound({musicOn: false});
    sound.setMusicUrl('music.mp3');
    await sound.startMusic();
    assert.equal(state.musicFetched, 0);
    await state.gesture();
    await sound.setMusic(true);
    assert.equal(music.plays, 1);
    await sound.setMusic(false);
    assert.equal(music.paused, true);
});

test('a touch the browser does not take as an activation is tried again on the next one, until the sound is running', async() => {
    const {sound, context, played, state} = fakeSound();
    await sound.loadEffects({swap: 'swap.mp3'});
    state.resumeFails = 2;
    await state.gesture();
    assert.equal(context.state, 'suspended');
    assert.equal(sound.isUnlocked(), false);
    assert.equal(state.listening, true, 'it keeps listening');
    sound.playEffect('swap');
    assert.equal(played.length, 0);
    await state.gesture();
    assert.equal(sound.isUnlocked(), false);
    await state.gesture();
    assert.equal(sound.isUnlocked(), true);
    assert.equal(state.listening, false, 'and stops once it runs');
    assert.equal(state.gestures, 3);
    sound.playEffect('swap');
    assert.equal(played.length, 1);
});

test('the music asked for before the browser allows it plays on the gesture that finally unlocks it', async() => {
    const {sound, music, state} = fakeSound();
    sound.setMusicUrl('music.mp3');
    state.resumeFails = 1;
    await sound.loadEffects({});
    await sound.startMusic();
    assert.equal(music.plays, 0);
    await state.gesture();
    // The first gesture let the music play (the browser only refused the audio context), the next one unlocks the rest.
    assert.equal(music.plays, 1);
    await state.gesture();
    assert.equal(sound.isUnlocked(), true);
});
