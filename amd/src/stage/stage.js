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
 * The stage of the game: a box with the game's own coordinate space (1280 x 720 or 540 x 960) scaled to whatever
 * width the page gives its container, with the layers the scene is built in, a veil for fades and the fullscreen
 * switch. Everything on the stage is placed in the game's own coordinates, and the scale does the rest.
 *
 * Layers, from the back: back (the scenery), middle, front, floating (numbers and sparks over everything of the
 * scene) and overlay (the veil and what a dialog needs over the whole stage).
 *
 * @module     mod_playerpuzzle/stage/stage
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], () => {
    'use strict';

    const LAYERS = ['back', 'middle', 'front', 'floating', 'overlay'];

    /**
     * Sets where an element stands on the stage, in the game's coordinates; the stylesheet turns the four numbers
     * into its box.
     *
     * @param {HTMLElement} element The element.
     * @param {{x: number, y: number, w: number, h: number}} box Its left, top, width and height; width and height
     *  may be left out.
     */
    const place = (element, box) => {
        element.style.setProperty('--x', `${box.x}px`);
        element.style.setProperty('--y', `${box.y}px`);
        if (box.w !== undefined) {
            element.style.setProperty('--w', `${box.w}px`);
        }
        if (box.h !== undefined) {
            element.style.setProperty('--h', `${box.h}px`);
        }
    };

    /**
     * Creates the stage inside a container.
     *
     * @param {object} options
     * @param {HTMLElement} options.container Where the stage goes; its width decides the scale.
     * @param {number} options.width Width of the game's coordinate space.
     * @param {number} options.height Height of the game's coordinate space.
     * @param {object} options.runtime The runtime whose tweens fade the veil.
     * @return {object} The stage.
     */
    const create = ({container, width, height, runtime}) => {
        const root = document.createElement('div');
        root.className = 'pp-stage';
        root.dataset.region = 'stage';
        root.style.setProperty('--pp-stage-w', `${width}px`);
        root.style.setProperty('--pp-stage-h', `${height}px`);
        const design = document.createElement('div');
        design.className = 'pp-stage-design';
        root.appendChild(design);
        const layers = {};
        LAYERS.forEach(name => {
            const layer = document.createElement('div');
            layer.className = `pp-stage-layer pp-stage-layer-${name}`;
            design.appendChild(layer);
            layers[name] = layer;
        });
        container.appendChild(root);

        const veil = document.createElement('div');
        veil.className = 'pp-stage-veil';
        layers.overlay.appendChild(veil);
        const veilState = {alpha: 0};
        let veilTween = null;

        const rescale = () => {
            const scale = container.clientWidth > 0 ? container.clientWidth / width : 1;
            root.style.setProperty('--pp-stage-scale', String(scale));
        };
        rescale();
        let observer = null;
        if (typeof ResizeObserver === 'function') {
            observer = new ResizeObserver(rescale);
            observer.observe(container);
        }

        const fullscreenListeners = [];
        const onFullscreenChange = () => fullscreenListeners.forEach(listener => listener(Boolean(document.fullscreenElement)));
        document.addEventListener('fullscreenchange', onFullscreenChange);

        return {
            root,
            layers,
            width,
            height,
            place,

            /**
             * Fades the veil over the whole stage to a given opacity.
             *
             * @param {number} alpha Opacity, 0 to 1.
             * @param {number} [duration] Milliseconds; 0 or left out sets it at once.
             * @param {Function} [onComplete] Called when the fade ends.
             */
            fadeVeil(alpha, duration = 0, onComplete = null) {
                if (veilTween) {
                    veilTween.stop();
                }
                const apply = () => {
                    veil.style.opacity = String(veilState.alpha);
                };
                if (duration <= 0) {
                    veilState.alpha = alpha;
                    apply();
                    if (onComplete) {
                        onComplete();
                    }
                    return;
                }
                veilTween = runtime.tweens.add({
                    targets: veilState,
                    alpha,
                    duration,
                    onUpdate: apply,
                    onComplete: () => {
                        apply();
                        if (onComplete) {
                            onComplete();
                        }
                    },
                });
            },

            /**
             * Whether the container is full screen.
             *
             * @return {boolean}
             */
            isFullscreen() {
                return document.fullscreenElement === container;
            },

            /**
             * Enters or leaves full screen with the container, which keeps the dialogs inside it.
             *
             * @return {Promise<void>}
             */
            async toggleFullscreen() {
                if (document.fullscreenElement) {
                    await document.exitFullscreen();
                } else if (container.requestFullscreen) {
                    await container.requestFullscreen();
                }
            },

            /**
             * Calls a function whenever the page enters or leaves full screen.
             *
             * @param {Function} listener Called with whether it is full screen now.
             */
            onFullscreenChange(listener) {
                fullscreenListeners.push(listener);
            },

            /**
             * Takes the stage out of the page.
             */
            destroy() {
                if (observer) {
                    observer.disconnect();
                }
                document.removeEventListener('fullscreenchange', onFullscreenChange);
                root.remove();
            },
        };
    };

    return {create, place, LAYERS};
});
