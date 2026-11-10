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
/**
 * The HUD and the stage of the game, drawn with elements on the stage instead of on a canvas: the scenery, the side
 * panels, the characters, the HP bars, the rings, the chips, the history, the balloon and the round buttons.
 *
 * It answers to the same calls as the canvas version (mod_playerpuzzle/ui), so combat.js and board.js do not know
 * which one they talk to. Everything stands where the layout says, in the stage's own coordinates; the stage scales
 * it all to the width of the page. The buttons are real buttons. The effects (the hits, the sparks, the numbers that
 * rise, the breathing and the pulses) answer the calls and do nothing yet.
 *
 * @module     mod_playerpuzzle/hud
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'core/ajax', 'core/templates', 'mod_playerpuzzle/accessibility', 'mod_playerpuzzle/stage/stage'
], (Ajax, Templates, Accessibility, Stage) => {
    'use strict';

    const TURN_GOLD = '#f2c14e';
    // The fill of each side's HP bar: a moss green and a brick red dark enough for white text to read on them.
    const HP_FILL = {player: '#3f7a2e', boss: '#9b2d28'};
    const TRANSLUCENT_ALPHA = 0.45;
    const SVG_NS = 'http://www.w3.org/2000/svg';

    // The Font Awesome class of each round button's icon, the second one for the off state of a toggle.
    const ICONS = {
        giveup: 'fa-flag', help: 'fa-question',
        music: ['fa-volume-high', 'fa-volume-xmark'], effects: ['fa-bell', 'fa-bell-slash'],
    };

    // The piece each consumable is named after, for the accessible name of its button.
    const CONSUMABLE_PIECE = {potion: 'piece_potion', sword: 'piece_sword', shield: 'piece_shield', magic: 'piece_grimoire'};

    /**
     * A hex number such as 0x9933cc as a CSS colour.
     *
     * @param {number} value The number.
     * @return {string}
     */
    const css = value => `#${value.toString(16).padStart(6, '0')}`;

    /**
     * Makes an element, puts it in a parent and places it on the stage.
     *
     * @param {string} tag Element name.
     * @param {string} className Its classes.
     * @param {HTMLElement} parent Where it goes.
     * @param {object} [box] Where it stands, as for Stage.place().
     * @return {HTMLElement}
     */
    const make = (tag, className, parent, box) => {
        const element = document.createElement(tag);
        element.className = className;
        if (box) {
            Stage.place(element, box);
        }
        parent.appendChild(element);
        return element;
    };

    /**
     * Wraps an element in the few calls the game makes on what it draws: text, opacity, visibility and colour.
     *
     * @param {HTMLElement} element The element.
     * @return {object}
     */
    const wrap = element => ({
        element,
        setText(text) {
            element.textContent = text;
            return this;
        },
        setAlpha(alpha) {
            element.style.opacity = String(alpha);
            return this;
        },
        setVisible(visible) {
            element.hidden = !visible;
            return this;
        },
        setColor(color) {
            element.style.color = color;
            return this;
        },
        remove() {
            element.remove();
        },
    });

    /**
     * The box of something centred on a point.
     *
     * @param {number} cx Centre x.
     * @param {number} cy Centre y.
     * @param {number} w Width.
     * @param {number} h Height, the width when left out.
     * @return {{x: number, y: number, w: number, h: number}}
     */
    const centered = (cx, cy, w, h = w) => ({x: cx - (w / 2), y: cy - (h / 2), w, h});

    /**
     * Makes an SVG element.
     *
     * @param {string} tag Element name.
     * @param {object} attributes Its attributes.
     * @param {Element} [parent] Where it goes.
     * @return {Element}
     */
    const svg = (tag, attributes, parent) => {
        const element = document.createElementNS(SVG_NS, tag);
        Object.keys(attributes).forEach(key => element.setAttribute(key, attributes[key]));
        if (parent) {
            parent.appendChild(element);
        }
        return element;
    };

    class HudHandler {
        /**
         * @param {object} scene The scene: stage, time, tweens, input, combat, board and the sound objects.
         * @param {object} layout The layout.
         * @param {object} gameConfig The game's configuration.
         * @param {object} strings The strings.
         */
        constructor(scene, layout, gameConfig, strings) {
            this.scene = scene;
            this.L = layout;
            this.gameConfig = gameConfig;
            this.strings = strings;
            this.layers = scene.stage.layers;
            this.rings = {};
            this.statusBadges = {};
            this.playerLog = [];
            this.bossLog = [];
            this.shownHp = {};
            this.hp = {};
            this.turnSide = null;
            this.turnFrame = null;
            this.demoRing = null;
            this.purchaseBadges = {};
        }

        /**
         * An image of the game at a box, by the key the game knows it by.
         *
         * @param {HTMLElement} parent Where it goes.
         * @param {string} key The image key: 'stagebg', 'player', 'item3'...
         * @param {object} box Where it stands.
         * @param {string} [extra] More classes.
         * @return {HTMLImageElement}
         */
        image(parent, key, box, extra = '') {
            const image = make('img', `pp-el pp-img ${extra}`.trim(), parent, box);
            image.src = this.scene.imageUrl(key);
            image.alt = '';
            image.draggable = false;
            return image;
        }

        /**
         * A text on the stage.
         *
         * @param {HTMLElement} parent Where it goes.
         * @param {string} className Classes: the look and the anchor.
         * @param {number} x Where its anchor is.
         * @param {number} y Where its anchor is.
         * @param {string} [text] What it says.
         * @return {HTMLElement}
         */
        text(parent, className, x, y, text = '') {
            const element = make('div', `pp-text ${className}`, parent, {x, y});
            element.textContent = text;
            return element;
        }

        setupStaticUI() {
            const L = this.L;
            if (L.hasCharacterStage) {
                this.buildLandscapeStage();
            } else {
                this.buildPortraitStage();
            }
            this.buildHpBar('boss', L.bossUiX, L.bossHpY, L.bossTxtY);
            this.buildHpBar('player', L.playerUiX, L.playerHpY, L.playerTxtY);
            this.setupProgressIndicator();
            this.setupQuestionsCounter();
            this.setupButtons();
        }

        /**
         * The horizontal layout: the wide stage, the carved panels, the characters, the consumables and the history.
         */
        buildLandscapeStage() {
            const L = this.L;
            const back = this.layers.back;
            this.image(back, 'stagebg', centered(L.stageBgX, L.stageBgY, L.stageBgW, L.stageBgH));
            const panelH = L.h - L.panelTop;
            this.image(back, 'panelstone', {x: 0, y: L.panelTop, w: L.panelW, h: panelH});
            this.image(back, 'panelstone', {x: L.w - L.panelW, y: L.panelTop, w: L.panelW, h: panelH}, 'pp-flip');

            this.createCharacters();

            this.image(back, 'item5', centered(L.potionX, L.potionY, L.resourceIconSize));
            this.createPurchaseBadge(L.potionX, L.potionY, 'potion');
            this.image(back, 'item3', centered(L.swordX, L.swordY, L.resourceIconSize));
            this.createPurchaseBadge(L.swordX, L.swordY, 'sword');

            this.txtGold = this.addResourceChip(L.goldX, L.goldY, 'item6', '0', L.indicatorIconSize);
            this.txtStar = this.addResourceChip(L.starX, L.starY, 'item0', 'x1.0', L.indicatorIconSize);

            this.bossPotionIcon = wrap(this.image(back, 'item5', centered(L.bossPotionX, L.bossPotionY, L.resourceIconSize)));
            this.bossSwordIcon = wrap(this.image(back, 'item3', centered(L.bossSwordX, L.bossSwordY, L.resourceIconSize)));
            this.txtBossGold = this.addResourceChip(L.bossGoldX, L.bossGoldY, 'item6', '0', L.indicatorIconSize);
            this.txtBossStar = this.addResourceChip(L.bossStarX, L.bossStarY, 'item0', 'x1.0', L.indicatorIconSize);

            this.createPurchaseBadge(L.playerGrimoireX, L.playerRingY, 'magic');
            this.createPurchaseBadge(L.playerShieldRingX, L.playerRingY, 'shield');

            this.playerLogLines = this.createHistoryLog(L.playerUiX);
            this.bossLogLines = this.createHistoryLog(L.bossUiX);
        }

        /**
         * The vertical layout: a dark backdrop, the stage band across the top, compact panels and the history button.
         */
        buildPortraitStage() {
            const L = this.L;
            const back = this.layers.back;
            const backdrop = make('div', 'pp-el pp-backdrop', back, {x: 0, y: 0, w: L.w, h: L.h});
            backdrop.style.backgroundColor = css(L.backdropColor);
            // The wide stage scaled to the band's height, keeping its own proportions and centred, so the edges of the
            // stage crop its two sides.
            const band = this.image(back, 'stagebg', {x: 0, y: 0, h: L.stageBandH}, 'pp-band-img');
            band.style.setProperty('--pp-band-h', `${L.stageBandH}px`);

            this.drawSimplePanel(L.panelTopBoss, L.panelBottomBoss);
            this.drawSimplePanel(L.panelTopPlayer, L.panelBottomPlayer);

            this.createCharacters();

            this.txtGold = this.addResourceChip(L.goldX, L.goldY, 'item6', '0');
            this.txtStar = this.addResourceChip(L.starX, L.starY, 'item0', 'x1.0');
            this.image(back, 'item5', centered(L.potionX, L.potionY, L.resourceIconSize));
            this.createPurchaseBadge(L.potionX, L.potionY, 'potion', L.badgeScale);
            this.txtBossGold = this.addResourceChip(L.bossGoldX, L.bossGoldY, 'item6', '0');
            this.txtBossStar = this.addResourceChip(L.bossStarX, L.bossStarY, 'item0', 'x1.0');

            this.createPurchaseBadge(L.playerGrimoireX, L.playerRingY, 'magic', L.badgeScale);
            this.createPurchaseBadge(L.playerShieldRingX, L.playerRingY, 'shield', L.badgeScale);

            this.setupHistoryButtonMobile();
        }

        /**
         * An HP bar: its backdrop with the bronze rim, its fill and its text.
         *
         * @param {string} side 'player' or 'boss'.
         * @param {number} x Left edge.
         * @param {number} y Top edge.
         * @param {number} textY Where the text is centred.
         */
        buildHpBar(side, x, y, textY) {
            const L = this.L;
            const bar = make('div', `pp-el pp-hp pp-hp-${side}`, this.layers.middle, {x, y, w: L.hpBarW, h: L.hpBarH});
            bar.style.borderRadius = `${L.hpBarRadius}px`;
            const fill = make('div', 'pp-hp-fill', bar);
            const label = this.text(this.layers.middle, 'pp-hp-text pp-anchor-center', x + (L.hpBarW / 2), textY);
            this.hp[side] = {bar, fill, label};
        }

        /**
         * Fills an HP bar to a share of its width, rounded on all four corners to match the bar. The radius shrinks with
         * the fill, so a nearly empty bar is a small rounded blob.
         *
         * @param {string} side 'player' or 'boss'.
         * @param {number} pct The share, 0 to 1.
         */
        drawHpFill(side, pct) {
            const L = this.L;
            const inset = 3;
            const fillW = Math.max(0, (L.hpBarW - (inset * 2)) * Math.max(0, pct));
            const fillH = L.hpBarH - (inset * 2);
            const {fill} = this.hp[side];
            fill.hidden = fillW <= 0;
            fill.style.width = `${fillW}px`;
            fill.style.backgroundColor = HP_FILL[side];
            fill.style.borderRadius = `${Math.max(0, Math.min(L.hpBarRadius - inset, fillW / 2, fillH / 2))}px`;
        }

        /**
         * An icon with a number beside it, for the coins and the stars.
         *
         * @param {number} x Icon centre x.
         * @param {number} y Icon centre y.
         * @param {string} key Image key of the icon.
         * @param {string} initialText Text shown before the first update.
         * @param {number} [size] Icon size, the resource icon size when left out.
         * @return {object} The text, which takes setText().
         */
        addResourceChip(x, y, key, initialText, size) {
            const iconSize = size || this.L.resourceIconSize;
            this.image(this.layers.back, key, centered(x, y, iconSize));
            return wrap(this.text(this.layers.back, 'pp-chip-text pp-anchor-left', x + (iconSize / 2) + 8, y, initialText));
        }

        /**
         * The button that uses a consumable, over the corner of its icon. A real button, named after what it uses and
         * how many are left; it dims and stops doing anything when none is left or the phase's limit is reached.
         *
         * @param {number} iconX The icon's centre x.
         * @param {number} iconY The icon's centre y.
         * @param {string} type 'potion', 'shield', 'magic' or 'sword'.
         * @param {number} [scale] Size multiplier off the 46 x 34 baseline.
         */
        createPurchaseBadge(iconX, iconY, type, scale = 1) {
            const w = 46 * scale;
            const h = 34 * scale;
            const offset = 20 * scale;
            const button = make('button', 'pp-el pp-badge-btn', this.layers.front, centered(iconX + offset, iconY + offset, w, h));
            button.type = 'button';
            button.style.borderRadius = `${6 * scale}px`;
            button.style.fontSize = `${Math.round(16 * scale)}px`;
            button.textContent = '0';
            button.addEventListener('click', () => {
                if (this.scene.combat) {
                    this.scene.combat.useConsumable(type);
                }
            });
            this.purchaseBadges[type] = {element: button, label: wrap(button), disabled: false};
        }

        /**
         * Refreshes every use button's number and name, and dims the ones that cannot be used: out of stock, or the
         * phase's limit reached. A Demo never runs out (it never touches the real stock), so it shows an infinity sign.
         */
        updateConsumableBadges() {
            const combat = this.scene.combat;
            if (!combat) {
                return;
            }
            Object.keys(this.purchaseBadges).forEach(type => {
                const badge = this.purchaseBadges[type];
                const owned = combat.consumableStock[type] || 0;
                const limitReached = (combat.consumableUses[type] || 0) >= combat.phaseLimit(type);
                const outOfStock = !combat.isdemo && owned <= 0;
                badge.disabled = limitReached || outOfStock;
                const count = combat.isdemo ? '∞' : String(owned);
                badge.label.setText(count);
                badge.element.setAttribute('aria-disabled', badge.disabled ? 'true' : 'false');
                badge.element.setAttribute('aria-label', this.strings.stage_use_item
                    .replace('{$a->item}', this.strings[CONSUMABLE_PIECE[type]])
                    .replace('{$a->count}', count));
            });
        }

        /**
         * The two characters on the stage, facing each other. Each stands on its feet, in a box as tall as it is wide.
         */
        createCharacters() {
            const L = this.L;
            const add = (x, y, key, size) => wrap(this.image(this.layers.back, key, centered(x, y, size), 'pp-character'));
            this.playerSprite = add(L.playerX, L.playerY, 'player', L.playerScale);
            this.bossSprite = add(L.bossX, L.bossY, 'boss', L.bossScale);
            this.characters = {
                player: {sprite: this.playerSprite, homeX: L.playerX, forward: 1, motion: null},
                boss: {sprite: this.bossSprite, homeX: L.bossX, forward: -1, motion: null},
            };
        }

        /**
         * Makes a character see-through, or solid again.
         *
         * @param {string} side 'player' or 'boss'.
         * @param {boolean} translucent Whether it should be see-through.
         */
        setTranslucent(side, translucent) {
            const character = this.characters && this.characters[side];
            if (character) {
                character.sprite.setAlpha(translucent ? TRANSLUCENT_ALPHA : 1);
            }
        }

        /**
         * Moves a character out and back. Not drawn yet.
         */
        moveCharacter() {
            // The effects arrive with the animations.
        }

        /**
         * The lunge of a side landing a hit. Not drawn yet.
         */
        lungeCharacter() {
            // The effects arrive with the animations.
        }

        /**
         * A side taking a hit. Not drawn yet.
         */
        hitCharacter() {
            // The effects arrive with the animations.
        }

        /**
         * A side taking a poison tick. Not drawn yet.
         */
        poisonCharacter() {
            // The effects arrive with the animations.
        }

        /**
         * Throws sparks off a piece being destroyed. Not drawn yet.
         */
        burstSparks() {
            // The effects arrive with the animations.
        }

        /**
         * Lets a number rise off a character for a change in its HP. Not drawn yet.
         */
        floatNumber() {
            // The effects arrive with the animations.
        }

        /**
         * Keeps a pale trail behind an HP bar that lost HP. Not drawn yet.
         */
        trailHp() {
            // The effects arrive with the animations.
        }

        /**
         * Remembers the HP a side shows, so the number that rises when it changes knows the difference. The first
         * drawing only remembers: a fight resumed from a checkpoint opens with no number.
         *
         * @param {string} side 'player' or 'boss'.
         * @param {number} currentHp The HP now.
         */
        trackHp(side, currentHp) {
            const now = Math.round(currentHp);
            const before = this.shownHp[side];
            this.shownHp[side] = now;
            if (before !== undefined && before !== now) {
                this.floatNumber(side, now - before);
            }
        }

        /**
         * Darkens the whole stage under the dialog that ends the match or the phase.
         */
        dimStage() {
            make('div', 'pp-el pp-dim', this.layers.overlay, {x: 0, y: 0, w: this.L.w, h: this.L.h});
        }

        /**
         * Shows the opponent's starting items, when it has any: a "x N" counter on a Potion and a Sword icon.
         *
         * @param {{potion: number, sword: number}} kit The items the phase started with.
         */
        createOpponentItems(kit) {
            if (!kit.potion && !kit.sword) {
                return;
            }
            const L = this.L;
            const size = L.bossItemIconSize;
            const make1 = (x, y, key, icon) => {
                const label = this.text(
                    this.layers.front, 'pp-item-count pp-anchor-center', x + (size * 0.35), y + (size * 0.35)
                );
                label.style.fontSize = `${L.bossItemFontSize}px`;
                return {
                    icon: icon || wrap(this.image(this.layers.back, key, centered(x, y, size))),
                    label: wrap(label),
                };
            };
            // The icon of an item the opponent does not bring stays, dimmed; only its count is left out, since "x0" of
            // something it never had says nothing.
            this.opponentItems = {
                potion: {...make1(L.bossPotionX, L.bossPotionY, 'item5', this.bossPotionIcon), brought: kit.potion > 0},
                sword: {...make1(L.bossSwordX, L.bossSwordY, 'item3', this.bossSwordIcon), brought: kit.sword > 0},
            };
        }

        /**
         * Refreshes the opponent's item counters; a spent item's icon is dimmed.
         *
         * @param {number} potions Potions left.
         * @param {number} swords Swords left.
         */
        updateOpponentItems(potions, swords) {
            if (!this.opponentItems) {
                return;
            }
            [[this.opponentItems.potion, potions], [this.opponentItems.sword, swords]].forEach(([item, count]) => {
                item.label.setText(`×${count}`).setVisible(item.brought);
                item.icon.setAlpha(count > 0 ? 1 : 0.35);
            });
        }

        /**
         * Shows a one-time tutorial balloon over the board: a rounded box with the text, fading after a few seconds.
         * Announced as well, since the balloon itself is not read.
         *
         * @param {string} text The text.
         * @param {boolean} [announce] Whether to announce the text too.
         */
        showTutorialBalloon(text, announce = true) {
            const L = this.L;
            if (announce) {
                Accessibility.announce(text);
            }
            this.removeBalloon();
            const boxW = Math.min(L.w - 40, 380);
            const balloon = make('div', 'pp-balloon', this.layers.overlay, {
                x: (L.w / 2) - (boxW / 2), y: Math.max(70, L.boardOffY - 190), w: boxW,
            });
            balloon.textContent = text;
            balloon.setAttribute('aria-hidden', 'true');
            this.balloon = balloon;
            // Eight seconds: the Demo's players found a shorter window too tight to read a full explanation.
            this.balloonTimer = this.scene.time.delayedCall(8000, () => {
                this.balloonTimer = null;
                const state = {alpha: 1};
                this.scene.tweens.add({
                    targets: state,
                    alpha: 0,
                    duration: 400,
                    onUpdate: () => {
                        balloon.style.opacity = String(state.alpha);
                    },
                    onComplete: () => {
                        balloon.remove();
                        if (this.balloon === balloon) {
                            this.balloon = null;
                        }
                    },
                });
            });
        }

        /**
         * Takes the balloon off, and its timer, before a new one comes.
         */
        removeBalloon() {
            if (this.balloonTimer) {
                this.balloonTimer.remove(false);
                this.balloonTimer = null;
            }
            if (this.balloon) {
                this.balloon.remove();
                this.balloon = null;
            }
        }

        /**
         * Shows the Demo walkthrough: a short, paged dialog explaining the HP bars and the buttons, every time a Demo
         * starts. The input stays off until the student steps through every page and closes it.
         *
         * @param {Function} onDismiss Called once the student closes the dialog.
         * @return {Promise<void>}
         */
        async showDemoWalkthrough(onDismiss) {
            const strings = this.strings;
            // Each step of a button shows the button's own icon and rings it on the stage; the HP step rings both bars.
            const steps = [
                {title: strings.demostep_welcome_title, text: strings.demostep_welcome_text, icon: '', target: null},
                {title: strings.demostep_hp_title, text: strings.demostep_hp_text, icon: '', target: 'hp'},
                {title: strings.demostep_giveup_title, text: strings.demostep_giveup_text, icon: 'fa-flag', target: 'giveup'},
                {title: strings.demostep_help_title, text: strings.demostep_help_text, icon: 'fa-question', target: 'help'},
                {title: strings.demostep_music_title, text: strings.demostep_music_text, icon: 'fa-volume-up', target: 'music'},
                {title: strings.demostep_sfx_title, text: strings.demostep_sfx_text, icon: 'fa-bell', target: 'effects'},
                {
                    title: strings.demostep_fullscreen_title, text: strings.demostep_fullscreen_text,
                    icon: '', fullscreenicon: true, target: 'expand',
                },
            ].map((step, index) => ({...step, index}));

            const html = await Templates.render('mod_playerpuzzle/demo_walkthrough', {
                title: strings.demowalkthrough_title,
                nextlabel: strings.demowalkthrough_next,
                startlabel: strings.demowalkthrough_start,
                steps: steps,
            });
            const existing = document.getElementById('pp-demo-walkthrough-dialog');
            if (existing) {
                existing.remove();
            }
            document.getElementById('playerpuzzle-canvas-container').insertAdjacentHTML('beforeend', html);

            const dialogEl = document.getElementById('pp-demo-walkthrough-dialog');
            const stepEls = dialogEl.querySelectorAll('.pp-demo-walkthrough-step');
            const progressEl = document.getElementById('pp-demo-walkthrough-progress');
            const nextBtn = document.getElementById('pp-demo-walkthrough-next');
            let current = 0;

            const renderStep = () => {
                stepEls.forEach(el => {
                    el.hidden = parseInt(el.dataset.step, 10) !== current;
                });
                progressEl.textContent = strings.demowalkthrough_progress
                    .replace('{$a->current}', current + 1)
                    .replace('{$a->total}', steps.length);
                nextBtn.textContent = current === steps.length - 1 ? nextBtn.dataset.startlabel : nextBtn.dataset.nextlabel;
                this.highlightDemoTarget(steps[current].target);
            };
            renderStep();

            nextBtn.addEventListener('click', () => {
                if (current < steps.length - 1) {
                    current++;
                    renderStep();
                    return;
                }
                dialogEl.close();
            });
            dialogEl.addEventListener('close', () => {
                this.highlightDemoTarget(null);
                dialogEl.remove();
                onDismiss();
            }, {once: true});
            dialogEl.showModal();
        }

        /**
         * Rings what a Demo walkthrough step is about, on the stage, in gold: a top button, or both HP bars. Replaces
         * the ring of the step before, and removes it for null.
         *
         * @param {string|null} target 'hp', a key of this.topButtons, or null for nothing.
         */
        highlightDemoTarget(target) {
            const L = this.L;
            if (this.demoRing) {
                this.demoRing.elements.forEach(element => element.remove());
                this.demoRing = null;
            }
            if (!target) {
                return;
            }
            const elements = [];
            if (target === 'hp') {
                [[L.playerUiX, L.playerHpY], [L.bossUiX, L.bossHpY]].forEach(([x, y]) => {
                    const ring = make('div', 'pp-el pp-demo-ring', this.layers.overlay, {
                        x: x - 4, y: y - 4, w: L.hpBarW + 8, h: L.hpBarH + 8,
                    });
                    ring.style.borderRadius = `${L.hpBarRadius + 4}px`;
                    elements.push(ring);
                });
            } else {
                const {x, y, r} = this.topButtons[target];
                const ring = make('div', 'pp-el pp-demo-ring', this.layers.overlay, centered(x, y, 2 * (r + 6)));
                ring.style.borderRadius = '50%';
                elements.push(ring);
            }
            this.demoRing = {elements, graphics: wrap(elements[0])};
        }

        /**
         * The action history of one side on the horizontal layout: the parchment banner with its title and the lines
         * below it, the newest on top.
         *
         * @param {number} x Left edge shared by the title and every line.
         * @return {object[]} The lines, index 0 the newest.
         */
        createHistoryLog(x) {
            const L = this.L;
            const back = this.layers.back;
            // Sized to the banner image's own proportions (about 1.74 to 1), so the parchment does not distort.
            const scrollH = L.historyBannerH || 52;
            this.image(back, 'scrollbanner', {x, y: L.historyTitleY - (scrollH / 2), w: scrollH * 1.738, h: scrollH});
            this.text(back, 'pp-history-title pp-anchor-left', x + 14, L.historyTitleY, this.strings.historylogtitle);
            const lines = [];
            for (let i = 0; i < (L.historyLines || 5); i++) {
                lines.push(wrap(this.text(back, 'pp-history-line', x, L.historyLineY + (i * L.historyLineHeight))));
            }
            lines[0].setText(this.strings.historylogempty);
            return lines;
        }

        /**
         * Appends a line to a side's history (the newest first, the oldest dropped once it cannot be shown) and
         * redraws that side's history, or the phone's history dialog when it is open.
         *
         * @param {string} side 'player' or 'boss'.
         * @param {string} text The new line.
         */
        pushHistoryLog(side, text) {
            const log = side === 'player' ? this.playerLog : this.bossLog;
            const maxLines = this.L.hasCharacterStage ? this.playerLogLines.length : 5;
            log.unshift(text);
            if (log.length > maxLines) {
                log.length = maxLines;
            }
            if (this.L.hasCharacterStage) {
                const lines = side === 'player' ? this.playerLogLines : this.bossLogLines;
                lines.forEach((line, i) => {
                    line.setText(log[i] || '');
                    line.element.classList.toggle('pp-history-newest', i === 0);
                });
            } else {
                this.renderHistoryModalLines();
            }
        }

        /**
         * A translucent rounded panel behind a cluster of the HUD, on the vertical layout.
         *
         * @param {number} topY Top edge.
         * @param {number} bottomY Bottom edge.
         */
        drawSimplePanel(topY, bottomY) {
            const margin = 16;
            make('div', 'pp-el pp-simple-panel', this.layers.back, {
                x: margin, y: topY, w: this.L.w - (margin * 2), h: bottomY - topY,
            });
        }

        /**
         * The button that opens the history on the phone, which has no room for two history boxes.
         */
        setupHistoryButtonMobile() {
            const L = this.L;
            const button = make('button', 'pp-text pp-anchor-top pp-history-btn', this.layers.front, {
                x: L.w / 2, y: L.historyBtnY,
            });
            button.type = 'button';
            button.textContent = this.strings.historylogtitle;
            button.addEventListener('click', () => this.showHistoryModalMobile());
        }

        /**
         * Opens the phone's history dialog: both sides' logs, the student's above the opponent's, over a dark veil, with
         * a button to close it. Filled by renderHistoryModalLines(), which pushHistoryLog() also calls while it is open.
         */
        showHistoryModalMobile() {
            if (this._historyModalOpen) {
                return;
            }
            this._historyModalOpen = true;
            const L = this.L;
            const boxW = Math.round(L.w * 0.85);
            const boxH = 460;
            const root = make('div', 'pp-history-modal', this.layers.overlay, {x: 0, y: 0, w: L.w, h: L.h});
            root.setAttribute('role', 'dialog');
            root.setAttribute('aria-modal', 'true');
            root.setAttribute('aria-label', this.strings.historylogtitle);
            const box = make('div', 'pp-history-box', root, {x: (L.w - boxW) / 2, y: (L.h - boxH) / 2, w: boxW, h: boxH});
            // The headings are labels coloured like each side's HP bar; the five lines of a side sit under its heading.
            const section = (labelText, side, headingY) => {
                this.text(box, `pp-history-heading pp-history-heading-${side} pp-anchor-left`, 20, headingY, labelText);
                const lines = [];
                for (let i = 0; i < 5; i++) {
                    lines.push(wrap(this.text(box, 'pp-history-line', 20, headingY + 26 + (i * 24))));
                }
                return lines;
            };
            const playerLines = section(this.strings.hpyou, 'player', 28);
            const bossLines = section(this.strings.hpboss, 'boss', 28 + 26 + (5 * 24) + 30);
            const close = make('button', 'pp-text pp-anchor-center pp-history-close', box, {x: boxW / 2, y: boxH - 35});
            close.type = 'button';
            close.textContent = this.strings.btncontinue;
            this._historyModal = {playerLines, bossLines};
            const dismiss = () => {
                this._historyModalOpen = false;
                this._historyModal = null;
                root.remove();
            };
            close.addEventListener('click', dismiss);
            root.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    dismiss();
                }
            });
            this.renderHistoryModalLines();
            close.focus();
        }

        /**
         * Fills the open phone history dialog from both logs. An empty log shows the "no moves yet" text, so an empty
         * opponent block does not look broken.
         */
        renderHistoryModalLines() {
            if (!this._historyModalOpen) {
                return;
            }
            const fill = (lines, log) => {
                lines.forEach((line, i) => {
                    line.setText(log[i] || (i === 0 ? this.strings.historylogempty : ''));
                    line.element.classList.toggle('pp-history-newest', i === 0);
                });
            };
            fill(this._historyModal.playerLines, this.playerLog);
            fill(this._historyModal.bossLines, this.bossLog);
        }

        /**
         * A ring meter around a piece's icon: a track, an arc that fills clockwise from the top, and the icon. Made on
         * the first call and redrawn on the next ones.
         *
         * @param {string} key Unique name of the ring.
         * @param {number} x Centre x.
         * @param {number} y Centre y.
         * @param {string} spriteKey Image key of the icon in the middle.
         * @param {number} pct How full, 0 to 1.
         * @param {number} color The arc's colour, as a hex number or a CSS colour.
         */
        updateRing(key, x, y, spriteKey, pct, color) {
            const {ringRadius: radius, ringThickness: thickness, ringIconSize: iconSize} = this.L;
            if (!this.rings[key]) {
                const reach = Math.ceil(radius + Math.max(3, thickness / 2)) + 1;
                const holder = make('div', 'pp-el pp-ring', this.layers.middle, centered(x, y, reach * 2));
                const drawing = svg('svg', {
                    viewBox: `${-reach} ${-reach} ${reach * 2} ${reach * 2}`, 'aria-hidden': 'true',
                }, holder);
                // A solid plate under the ring: it sits over the scenery, which bleeds through a stroke-only circle and
                // makes the icon unreadable at this size.
                svg('circle', {r: radius + 3, fill: '#1a1a1a', 'fill-opacity': '0.9'}, drawing);
                svg('circle', {r: radius, fill: 'none', stroke: '#222222', 'stroke-width': thickness}, drawing);
                const arc = svg('circle', {
                    r: radius, fill: 'none', 'stroke-width': thickness, transform: 'rotate(-90)',
                }, drawing);
                this.image(holder, spriteKey, centered(reach, reach, iconSize), 'pp-ring-icon');
                this.rings[key] = {arc, circumference: 2 * Math.PI * radius};
            }
            const ring = this.rings[key];
            const share = Math.max(0, Math.min(1, pct));
            ring.arc.setAttribute('stroke', typeof color === 'number' ? css(color) : color);
            ring.arc.setAttribute('stroke-dasharray', `${ring.circumference * share} ${ring.circumference}`);
            ring.arc.setAttribute('visibility', share > 0 ? 'visible' : 'hidden');
        }

        /**
         * The level and phase of a Campaign, on a dark plaque at the top; a Single Match has none.
         */
        setupProgressIndicator() {
            if (this.gameConfig.gamemode !== 'campaign') {
                return;
            }
            const L = this.L;
            const y = L.hasCharacterStage ? 20 : L.progressIndicatorY;
            const text = this.strings.progressindicator
                .replace('{$a->level}', this.gameConfig.currentlevel)
                .replace('{$a->phase}', this.gameConfig.currentphase);
            this.progressText = wrap(this.text(this.layers.front, 'pp-progress pp-anchor-center', L.w / 2, y + 16, text));
        }

        /**
         * The "Questions: X/N" counter under the student's HP bar, only when the instance asks for a minimum.
         */
        setupQuestionsCounter() {
            if (!(parseInt(this.gameConfig.minquestions, 10) > 0)) {
                return;
            }
            const L = this.L;
            this.questionsCounterText = wrap(this.text(
                this.layers.back, 'pp-counter pp-anchor-top',
                L.playerUiX + (L.hpBarW / 2), L.questionsCounterY || (L.playerHpY + L.hpBarH + 14)
            ));
        }

        /**
         * Refreshes the questions counter; nothing when no minimum was set.
         *
         * @param {number} total Questions answered so far.
         * @param {number} min The minimum.
         */
        updateQuestionsCounter(total, min) {
            if (!this.questionsCounterText) {
                return;
            }
            this.questionsCounterText.setText(
                this.strings.questionscounter.replace('{$a->current}', total).replace('{$a->total}', min)
            );
        }

        /**
         * The frame in gold around the HP bar of the side whose turn it is. Nothing when that side already has it.
         *
         * @param {string} side 'player' or 'boss'.
         */
        showTurn(side) {
            if (this.turnSide === side) {
                return;
            }
            const L = this.L;
            this.turnSide = side;
            if (!this.turnFrame) {
                this.turnFrame = make('div', 'pp-el pp-turn-frame', this.layers.middle);
                this.turnFrame.style.setProperty('--pp-turn-gold', TURN_GOLD);
            }
            const x = side === 'player' ? L.playerUiX : L.bossUiX;
            const y = side === 'player' ? L.playerHpY : L.bossHpY;
            Stage.place(this.turnFrame, {x: x - 2, y: y - 2, w: L.hpBarW + 4, h: L.hpBarH + 4});
            this.turnFrame.style.borderRadius = `${L.hpBarRadius + 2}px`;
        }

        /**
         * The status badges on an HP bar's lower corner, one for each effect that is active (poison rounds pending,
         * a block armed), packed from the corner inward.
         *
         * @param {string} prefix 'player' or 'boss'.
         * @param {number} cornerX The bar's right edge.
         * @param {number} cornerY The bar's bottom edge.
         * @param {number} poisonRounds Poison rounds left against this side.
         * @param {boolean} shieldReady Whether a block is armed.
         */
        updateStatusBadges(prefix, cornerX, cornerY, poisonRounds, shieldReady) {
            const badgeR = 15;
            const gap = 34;
            if (!this.statusBadges[prefix]) {
                const makeBadge = key => {
                    const root = make('div', 'pp-el pp-status-badge', this.layers.middle);
                    this.image(root, key, centered(badgeR, badgeR, badgeR * 1.3), 'pp-status-icon');
                    const count = make('div', 'pp-status-count', root);
                    count.style.left = `${(2 * badgeR) - 12}px`;
                    count.style.top = `${(2 * badgeR) - 12}px`;
                    return {root, count};
                };
                this.statusBadges[prefix] = {poison: makeBadge('item1'), shield: makeBadge('item4')};
            }
            const badges = this.statusBadges[prefix];
            const active = [];
            if (poisonRounds > 0) {
                active.push({badge: badges.poison, count: String(poisonRounds)});
            }
            if (shieldReady) {
                active.push({badge: badges.shield, count: '✓'});
            }
            [badges.poison, badges.shield].forEach(badge => {
                badge.root.hidden = !active.some(entry => entry.badge === badge);
            });
            active.forEach((entry, i) => {
                Stage.place(entry.badge.root, {
                    x: cornerX - (2 * badgeR) - (i * gap), y: cornerY - badgeR, w: 2 * badgeR, h: 2 * badgeR,
                });
                entry.badge.count.textContent = entry.count;
            });
        }

        /**
         * Refreshes the opponent's HP bar, rings, status badges and chips.
         *
         * @param {number} currentHp HP now.
         * @param {number} maxHp Full HP.
         * @param {number} poisonMeter The Grimoire meter, 0 to 100.
         * @param {number} poisonRounds Poison rounds left against this side.
         * @param {number} shieldMeter The Shield meter, 0 to 100.
         * @param {boolean} shieldReady Whether a block is armed.
         * @param {number} mana The Orb meter, 0 to 100.
         * @param {number} gold Coins.
         * @param {number} multiplier The Star multiplier.
         */
        updateBossBar(currentHp, maxHp, poisonMeter, poisonRounds, shieldMeter, shieldReady, mana, gold, multiplier) {
            const L = this.L;
            this.trackHp('boss', currentHp);
            this.drawHpFill('boss', Math.max(0, currentHp / maxHp));
            // The opponent's own name leads the bar; a long one shrinks to fit the bar instead of running past its rim.
            const name = this.gameConfig.opponentname ? `${this.gameConfig.opponentname}:` : this.strings.hpboss;
            const label = this.hp.boss.label;
            label.style.transform = '';
            label.textContent = `${name} ${Math.round(currentHp)} / ${maxHp}`;
            const scale = Math.min(1, (L.hpBarW - 16) / (label.offsetWidth || 1));
            label.style.transform = scale < 1 ? `translate(-50%, -50%) scale(${scale})` : '';

            this.txtBossGold.setText(`${Math.round(gold)}`);
            this.txtBossStar.setText(`x${multiplier.toFixed(1)}`);
            this.updateRing('bossGrimoire', L.bossGrimoireX, L.bossRingY, 'item1', poisonMeter / 100, 0x9933cc);
            this.updateRing(
                'bossShieldRing', L.bossShieldRingX, L.bossRingY, 'item4',
                shieldReady ? 1 : (shieldMeter / 100), shieldReady ? 0xffcc00 : 0x3388ff
            );
            this.updateRing('bossOrb', L.bossOrbX, L.bossRingY, 'item2', mana / 100, 0x0088ff);
            this.updateStatusBadges('boss', L.bossUiX + L.hpBarW, L.bossHpY + L.hpBarH, poisonRounds, shieldReady);
        }

        /**
         * Refreshes the student's HP bar, rings, status badges and chips; the arguments are those of updateBossBar().
         *
         * @param {number} currentHp HP now.
         * @param {number} maxHp Full HP.
         * @param {number} poisonMeter The Grimoire meter, 0 to 100.
         * @param {number} poisonRounds Poison rounds left against this side.
         * @param {number} shieldMeter The Shield meter, 0 to 100.
         * @param {boolean} shieldReady Whether a block is armed.
         * @param {number} mana The Orb meter, 0 to 100.
         * @param {number} gold Coins.
         * @param {number} multiplier The Star multiplier.
         */
        updatePlayerBar(currentHp, maxHp, poisonMeter, poisonRounds, shieldMeter, shieldReady, mana, gold, multiplier) {
            const L = this.L;
            this.trackHp('player', currentHp);
            this.drawHpFill('player', Math.max(0, currentHp / maxHp));
            this.hp.player.label.textContent = `${this.strings.hpyou} ${Math.round(currentHp)} / ${maxHp}`;

            this.txtGold.setText(`${Math.round(gold)}`);
            this.txtStar.setText(`x${multiplier.toFixed(1)}`);
            this.updateRing('playerGrimoire', L.playerGrimoireX, L.playerRingY, 'item1', poisonMeter / 100, 0x9933cc);
            this.updateRing(
                'playerShieldRing', L.playerShieldRingX, L.playerRingY, 'item4',
                shieldReady ? 1 : (shieldMeter / 100), shieldReady ? 0xffcc00 : 0x3388ff
            );
            this.updateRing('playerOrb', L.playerOrbX, L.playerRingY, 'item2', mana / 100, 0x0088ff);
            this.updateStatusBadges('player', L.playerUiX + L.hpBarW, L.playerHpY + L.hpBarH, poisonRounds, shieldReady);
        }

        /**
         * A round button of the top row: dark, with the bronze rim, and an icon. A real button with a name.
         *
         * @param {string} name Key in this.topButtons.
         * @param {string} label Its accessible name.
         * @param {number} x Centre x.
         * @param {number} y Centre y.
         * @param {number} r Radius.
         * @return {HTMLButtonElement}
         */
        roundButton(name, label, x, y, r) {
            const button = make('button', `pp-el pp-round-btn pp-round-${name}`, this.layers.front, centered(x, y, 2 * r));
            button.type = 'button';
            button.setAttribute('aria-label', label);
            button.style.fontSize = `${Math.round(r * 1.15)}px`;
            return button;
        }

        /**
         * Sets the icon of a button of Font Awesome.
         *
         * @param {HTMLElement} button The button.
         * @param {string} icon The icon class, such as 'fa-bell'.
         */
        setIcon(button, icon) {
            button.textContent = '';
            const glyph = make('i', `fa ${icon}`, button);
            glyph.setAttribute('aria-hidden', 'true');
        }

        /**
         * Draws the full-screen icon, four corner brackets pointing out (to enter) or pulled in (to leave).
         *
         * @param {HTMLElement} button The button.
         * @param {number} r The button's radius.
         * @param {boolean} expanded Whether the page is full screen.
         */
        drawFullscreenIcon(button, r, expanded) {
            button.textContent = '';
            const drawing = svg('svg', {viewBox: `${-r} ${-r} ${r * 2} ${r * 2}`, 'aria-hidden': 'true'}, button);
            const elbow = (expanded ? r * 0.3 : r * 0.62) / Math.SQRT2;
            const arm = elbow * 0.65;
            const sign = expanded ? 1 : -1;
            [[-1, -1], [1, -1], [-1, 1], [1, 1]].forEach(([sx, sy]) => {
                const ex = sx * elbow;
                const ey = sy * elbow;
                svg('path', {
                    d: `M ${ex + (sx * sign * arm)} ${ey} L ${ex} ${ey} L ${ex} ${ey + (sy * sign * arm)}`,
                    fill: 'none', stroke: '#e8dcc8', 'stroke-width': 3,
                }, drawing);
            });
        }

        /**
         * Opens the dialog that reads out what the opponent is and fights with.
         */
        openOpponentHelp() {
            const dialog = document.getElementById('playerpuzzle-opponent-help');
            if (dialog && !dialog.open) {
                dialog.showModal();
            }
        }

        /**
         * The round buttons of the top row: Give up and Help at the left edge, Music, Sound effects and Full screen at
         * the right. As before, they do nothing while the game has its input off.
         */
        setupButtons() {
            const me = this.scene;
            const L = this.L;
            const strings = this.strings;
            me.musicOn = this.gameConfig.musicenabled !== false;
            me.sfxOn = this.gameConfig.sfxenabled !== false;

            const y = L.topBtnY;
            const r = L.topBtnRadius;
            const gap = L.topBtnGap;
            const xGiveUp = 30;
            const xHelp = xGiveUp + gap;
            const xExpand = L.w - 30;
            const xEffects = xExpand - gap;
            const xMusic = xEffects - gap;
            // Where each button sits, for the Demo walkthrough to point at.
            this.topButtons = {
                giveup: {x: xGiveUp, y, r}, help: {x: xHelp, y, r}, music: {x: xMusic, y, r},
                effects: {x: xEffects, y, r}, expand: {x: xExpand, y, r},
            };
            const whenActive = action => () => {
                if (me.input.enabled) {
                    action();
                }
            };

            const giveUp = this.roundButton('giveup', strings.stage_giveup, xGiveUp, y, r);
            this.setIcon(giveUp, ICONS.giveup);
            giveUp.addEventListener('click', whenActive(() => me.combat.openGiveUpDialog()));
            const help = this.roundButton('help', strings.stage_help, xHelp, y, r);
            this.setIcon(help, ICONS.help);
            help.addEventListener('click', whenActive(() => this.openOpponentHelp()));
            document.getElementById('pp-opponent-help-close')?.addEventListener('click', () => {
                document.getElementById('playerpuzzle-opponent-help').close();
            });
            document.getElementById('pp-giveup-cancel')?.addEventListener('click', () => {
                document.getElementById('playerpuzzle-giveup').close();
            });
            document.getElementById('pp-giveup-confirm')?.addEventListener('click', () => {
                document.getElementById('playerpuzzle-giveup').close();
                me.combat.giveUp();
            });

            const toggle = (name, label, getOn) => {
                const button = this.roundButton(name, label, this.topButtons[name].x, y, r);
                const draw = () => {
                    this.setIcon(button, ICONS[name][getOn() ? 0 : 1]);
                    button.setAttribute('aria-pressed', getOn() ? 'true' : 'false');
                };
                draw();
                return {button, draw};
            };
            const music = toggle('music', strings.lobby_music_label, () => me.musicOn);
            music.button.addEventListener('click', whenActive(() => {
                if (me.board && me.board.swipePiece !== null) {
                    return;
                }
                me.musicOn = !me.musicOn;
                music.draw();
                if (me.musicOn) {
                    me.playMusic();
                } else {
                    me.pauseMusic();
                }
                this.saveSoundPreference('music', me.musicOn);
            }));
            const effects = toggle('effects', strings.lobby_sfx_label, () => me.sfxOn);
            effects.button.addEventListener('click', whenActive(() => {
                if (me.board && me.board.swipePiece !== null) {
                    return;
                }
                me.sfxOn = !me.sfxOn;
                effects.draw();
                const vol = me.sfxOn ? 1 : 0;
                me.sfxSwap.setVolume(0.6 * vol);
                me.sfxMatch.setVolume(0.5 * vol);
                me.sfxHit.setVolume(0.65 * vol);
                this.saveSoundPreference('sfx', me.sfxOn);
            }));

            const expand = this.roundButton('expand', strings.stage_fullscreen, xExpand, y, r);
            this.drawFullscreenIcon(expand, r, false);
            expand.setAttribute('aria-pressed', 'false');
            expand.addEventListener('click', whenActive(() => {
                const stage = me.stage;
                stage.fadeVeil(1, 200, () => {
                    stage.toggleFullscreen();
                    me.time.delayedCall(200, () => stage.fadeVeil(0, 200));
                });
            }));
            me.stage.onFullscreenChange(on => {
                this.drawFullscreenIcon(expand, r, on);
                expand.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        /**
         * Saves a Music or Sound effects toggle as a user preference, fire and forget: the icon and the sound were
         * already changed by the caller, and a failure only means the toggle does not survive a reload.
         *
         * @param {string} type 'music' or 'sfx'.
         * @param {boolean} enabled The new state.
         */
        saveSoundPreference(type, enabled) {
            Ajax.call([{
                methodname: 'mod_playerpuzzle_set_sound_preference',
                args: {cmid: this.gameConfig.cmid, type, enabled},
            }])[0].fail(error => window.console.error(error));
        }
    }

    return HudHandler;
});
