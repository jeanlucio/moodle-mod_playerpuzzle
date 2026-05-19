/**
 * Main bootloader for the PlayerPuzzle Phaser game.
 *
 * @module     mod_playerpuzzle/game_boot
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global Phaser */

define([
    'jquery',
    'core/notification',
    'mod_playerpuzzle/ui',
    'mod_playerpuzzle/combat',
    'mod_playerpuzzle/board'
], function($, notification, UIHandler, CombatHandler, BoardHandler) {
    'use strict';

    var startPhaser = function(gameConfig) {

        var preload = function() {
            this.ui = new UIHandler(this, null, gameConfig);
            this.ui.setupLoader();

            this.load.image('bg', gameConfig.bgurl);
            this.load.image('boss', gameConfig.bossurl);
            for (var i = 0; i < 7; i++) {
                this.load.image('item' + i, gameConfig.spriteurls[i]);
            }

            var urlPix = M.cfg.wwwroot + '/mod/playerpuzzle/pix/';
            this.load.audio('bg_music', urlPix + 'music.mp3');
            this.load.audio('sfx_swap', urlPix + 'swap.mp3');
            this.load.audio('sfx_match', urlPix + 'match.mp3');
            this.load.audio('sfx_hit', urlPix + 'hit.mp3');
        };

        var create = function() {
            var isDesk = window.innerWidth > window.innerHeight;

            var L = isDesk ? {
                w: 1280, h: 720, aspect: '16/9', maxW: '100%',
                bgX: 640, bgY: 360, bgW: 1280, bgH: 720,
                bossX: 1040, bossY: 260, bossScale: 180,
                bossUiX: 890, bossHpY: 380, bossManaY: 405, bossTxtY: 391, venenoX: 1200,
                playerUiX: 90, playerHpY: 380, playerManaY: 405, playerTxtY: 391,
                escudoX: 90, ouroX: 240, estrelaX: 390,
                boardOffX: 447.5, boardOffY: 167.5, btnExpX: 1260, btnExpY: 20
            } : {
                w: 540, h: 960, aspect: '9/16', maxW: '540px',
                bgX: 270, bgY: 480, bgW: 540, bgH: 960,
                bossX: 270, bossY: 75, bossScale: 100,
                bossUiX: 120, bossHpY: 135, bossManaY: 158, bossTxtY: 146, venenoX: 430,
                playerUiX: 120, playerHpY: 175, playerManaY: 198, playerTxtY: 186,
                escudoX: 120, ouroX: 270, estrelaX: 420,
                boardOffX: 77.5, boardOffY: 280, btnExpX: 520, btnExpY: 20
            };

            var containerDOM = $('#playerpuzzle-canvas-container');
            containerDOM.find('p').remove();
            if (!gameConfig.mobile) {
                containerDOM.css({'aspect-ratio': L.aspect, 'max-width': L.maxW, 'margin': '0 auto'});
            }
            containerDOM.append($('#playerpuzzle-modal'));

            // Injeta o Layout na UI e desenha a interface estática
            this.ui.L = L;
            this.ui.setupStaticUI();

            // Áudios
            this.sfxSwap = this.sound.add('sfx_swap', {volume: 0.6});
            this.sfxMatch = this.sound.add('sfx_match', {volume: 0.5});
            this.sfxHit = this.sound.add('sfx_hit', {volume: 0.8});
            this.bgMusic = this.sound.add('bg_music', {volume: 0.3, loop: true});

            var me = this;
            if (!this.sound.locked) {
                this.bgMusic.play();
            } else {
                this.sound.once(Phaser.Sound.Events.UNLOCKED, function() {
                    me.bgMusic.play();
                });
            }

            // Inicializa os módulos de Combate e Tabuleiro
            this.combat = new CombatHandler(this, gameConfig);
            this.board = new BoardHandler(this, L);

            // Sincroniza a interface visual com os status iniciais do combate
            this.combat.atualizarUI();

            if (gameConfig.mobile) {
                history.pushState({ppgame: true}, '');
                window.addEventListener('popstate', function() {
                    history.pushState({ppgame: true}, '');
                    me.ui.showExitConfirm();
                });
            }
        };

        var isDesk = window.innerWidth > window.innerHeight;
        var config = {
            type: Phaser.AUTO,
            parent: 'playerpuzzle-canvas-container',
            backgroundColor: '#1a1a1a',
            scale: {
                mode: Phaser.Scale.FIT,
                autoCenter: Phaser.Scale.CENTER_BOTH,
                width: isDesk ? 1280 : 540,
                height: isDesk ? 720 : 960,
                fullscreenTarget: document.getElementById('playerpuzzle-canvas-container')
            },
            // --- NOVA CONFIGURAÇÃO DE INPUT AQUI ---
            input: {
                mouse: {
                    preventDefaultWheel: false
                },
                touch: {
                    capture: false
                }
            },
            // ---------------------------------------
            scene: {preload: preload, create: create}
        };
        new Phaser.Game(config);
    };

    return {
        init: function() {
            $(document).ready(function() {
                try {
                    $('#playerpuzzle-canvas-container p').css('color', '#ffffff');
                    var container = document.getElementById('playerpuzzle-canvas-container');
                    var configStr = container.getAttribute('data-config');
                    if (!configStr) {
                        throw new Error('Game configuration is missing from HTML.');
                    }

                    var config = JSON.parse(configStr);

                    require(['Phaser'], function(PhaserObj) {
                        if (PhaserObj) {
                            window.Phaser = PhaserObj;
                        }
                        startPhaser(config);
                    }, function(erro) {
                        var msg = '<p class="text-danger">Erro crítico do RequireJS.</p>';
                        $('#playerpuzzle-canvas-container').html(msg);
                        window.console.error("RequireJS Error:", erro);
                    });
                } catch (error) {
                    notification.exception(error);
                }
            });
        }
    };
});
