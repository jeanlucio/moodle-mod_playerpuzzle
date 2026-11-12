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
 * Main bootloader for the PlayerPuzzle game: reads the game configuration, loads the strings and starts the scene.
 *
 * @module     mod_playerpuzzle/game_boot
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'core/notification',
    'core/str',
    'mod_playerpuzzle/accessibility',
    'mod_playerpuzzle/scene_dom'
], (notification, Str, Accessibility, SceneDom) => {
    'use strict';

    return {
        async init() {
            try {
                const container = document.getElementById('playerpuzzle-canvas-container');
                const configStr = container.getAttribute('data-config');
                if (!configStr) {
                    throw new Error('Game configuration is missing from HTML.');
                }

                const config = JSON.parse(configStr);
                Accessibility.setSpeechEnabled(!!config.enablespeech);
                Accessibility.hideEmojis(container);
                Accessibility.hideEmojis(document.getElementById('playerpuzzle-modal'));

                // Apply desktop layout immediately so the loading screen renders at the correct size.
                if (window.innerWidth > window.innerHeight) {
                    container.classList.add('pp-canvas-desktop');
                }

                const strKeys = [
                    'advancingphase',
                    'answersr_correct', 'answersr_yourcorrect', 'answersr_yourwrong',
                    'bossansweredcorrect', 'bossansweredwrong', 'bosscorrectblocked', 'bosscorrectfeedback',
                    'bosslostmultiplier', 'bossrevived', 'bosstrigger', 'bosswrongfeedback',
                    'btnattack', 'btncontinue', 'btnexitgame', 'btnplayagain', 'btnstartphase',
                    'campaigncomplete', 'coinscollected',
                    'damagedealt', 'damagetaken',
                    'debriefcorrectanswer', 'debriefcorrectsr', 'debriefempty', 'debriefreview',
                    'debriefsummary', 'debrieftitle', 'debriefwrongsr', 'debriefyouranswer',
                    'defeat',
                    'demostep_fullscreen_text', 'demostep_fullscreen_title',
                    'demostep_giveup_text', 'demostep_giveup_title',
                    'demostep_howto_text', 'demostep_howto_title',
                    'demostep_hp_text', 'demostep_hp_title',
                    'demostep_music_text', 'demostep_music_title',
                    'demostep_opponent_text', 'demostep_opponent_title',
                    'demostep_sfx_text', 'demostep_sfx_title',
                    'demostep_welcome_text', 'demostep_welcome_title',
                    'demounseen',
                    'demowalkthrough_next', 'demowalkthrough_progress', 'demowalkthrough_start',
                    'demowalkthrough_title',
                    'difficulty_easy', 'difficulty_hard', 'difficulty_normal',
                    'hintbuyinlobby', 'hintbutton',
                    'historylogambush', 'historylogbreath', 'historylogbreathblocked', 'historylogbreathtaken',
                    'historylogcoins', 'historylogcritical', 'historylogcriticalblocked', 'historylogcriticaltaken',
                    'historylogdrain', 'historylogempty', 'historylogintangible', 'historylogitempotion',
                    'historylogkit', 'historylogkit_potion', 'historylogkit_sword', 'historylogloot', 'historylogopening_boss',
                    'historylogopening_player', 'historyloglooted',
                    'historylograid', 'historylograidblocked', 'historylograidtaken',
                    'historylogheal', 'historylogmana', 'historylogmultiplier',
                    'historylogmultiplierlost', 'historylogpoisoncharge', 'historylogpoisontick',
                    'historylogpower_ambush', 'historylogpower_breath', 'historylogpower_drain',
                    'historylogpower_fastgrimoire',
                    'historylogpower_iceshield', 'historylogpower_intangible', 'historylogpower_loot',
                    'historylogpower_longpoison', 'historylogpower_manaboost', 'historylogpower_regen',
                    'historylogpower_rootheal',
                    'historylogregen', 'historylogrevive', 'historylogshieldblock', 'historylogshieldcharge',
                    'historylogstrike', 'historylogstrikeblocked', 'historylogstriketaken',
                    'historylogsword', 'historylogswordblocked', 'historylogswordtaken',
                    'historylogtitle', 'historylogwronganswer',
                    'hpboss', 'hpyou', 'loading', 'manafull', 'maxmultiplier',
                    'nextlevel', 'nextphase', 'noanswers', 'opponentitempotion',
                    'opponentkit', 'opponentkit_potion', 'opponentkit_sword',
                    'openingright', 'openingtrigger', 'openingwrong',
                    'phaseadvanced', 'phaseadvanceerror', 'phasecompletetitle', 'phasedifficulty', 'phaselost', 'phaseshoptitle',
                    'shop_hero_evolved',
                    'pieceselected',
                    'piece_coin', 'piece_coin_plural', 'piece_grimoire', 'piece_grimoire_plural',
                    'piece_orb', 'piece_orb_plural', 'piece_potion', 'piece_potion_plural',
                    'piece_shield', 'piece_shield_plural', 'piece_star', 'piece_star_plural',
                    'piece_sword', 'piece_sword_plural',
                    'playercorrect', 'playercorrectblocked',
                    'power_ambush', 'power_breath', 'power_drain', 'power_fastgrimoire', 'power_iceshield',
                    'power_longpoison',
                    'power_intangible', 'power_loot', 'power_manaboost',
                    'power_regen', 'power_rootheal',
                    'playerlostmultiplier', 'playerwrong', 'progressindicator', 'progresssaved',
                    'questionerror', 'questionscounter', 'questionsprogress',
                    'requirejserror', 'retrydifficulty', 'saveerror', 'savingprogress',
                    'selectioncleared', 'shoperror', 'shuffling',
                    'stage_fullscreen', 'stage_giveup', 'stage_howtoplay', 'stage_opponent', 'stage_use_item',
                    'lobby_music_label', 'lobby_sfx_label',
                    'turnstart_intro', 'turnstart_move',
                    'tutorialballoon_coin', 'tutorialballoon_grimoire', 'tutorialballoon_orb',
                    'tutorialballoon_potion', 'tutorialballoon_shield', 'tutorialballoon_star',
                    'tutorialballoon_sword', 'tutorialquestioninstruction',
                    'victory', 'victoryunverifiedlost', 'victoryunverifiedrestart'
                ];

                const values = await Str.get_strings(
                    strKeys.map(key => ({key, component: 'mod_playerpuzzle'}))
                );
                const strings = {};
                strKeys.forEach((key, i) => {
                    strings[key] = values[i];
                });

                try {
                    window.__dom = {scene: await SceneDom.start(config, strings)};
                } catch (err) {
                    window.console.error('PlayerPuzzle failed to start:', err);
                    container.innerHTML = `<p class="text-danger">${strings.requirejserror}</p>`;
                }
            } catch (error) {
                notification.exception(error);
            }
        }
    };
});
