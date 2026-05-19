/**
 * UI Module for PlayerPuzzle
 *
 * @module     mod_playerpuzzle/ui
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    class UIHandler {
        constructor(scene, layout, gameConfig) {
            this.scene = scene;
            this.L = layout;
            this.gameConfig = gameConfig;
        }

        setupLoader() {
            var loadingHtml = '<div id="pp-bootstrap-loader" ' +
                'class="d-flex flex-column justify-content-center align-items-center" ' +
                'style="position: absolute; top: 0; left: 0; width: 100%; ' +
                'height: 100%; z-index: 1000; background-color: #1a1a1a;">';
            loadingHtml += '<h3 class="text-white mb-3">Carregando recursos...</h3>';
            loadingHtml += '<div class="progress w-50" style="height: 25px;">';

            var pBar = '<div id="pp-progress-bar" ' +
                'class="progress-bar progress-bar-striped progress-bar-animated bg-primary" ' +
                'role="progressbar" style="width: 0%;" aria-valuenow="0" ' +
                'aria-valuemin="0" aria-valuemax="100">0%</div>';

            loadingHtml += pBar + '</div></div>';

            $('#playerpuzzle-canvas-container').css('position', 'relative').append(loadingHtml);

            this.scene.load.on('progress', function(value) {
                var percent = parseInt(value * 100);
                $('#pp-progress-bar')
                    .css('width', percent + '%')
                    .attr('aria-valuenow', percent)
                    .text(percent + '%');
            });

            this.scene.load.on('complete', function() {
                $('#pp-bootstrap-loader').fadeOut(300, function() {
                    $(this).remove();
                });
            });
        }

        setupStaticUI() {
            var me = this.scene;
            var L = this.L;

            // Fundo
            me.add.image(L.bgX, L.bgY, 'bg').setDisplaySize(L.bgW, L.bgH);

            // Sprites Iniciais
            this.bossSprite = me.add.image(L.bossX, L.bossY, 'boss')
                .setDisplaySize(L.bossScale, L.bossScale);

            // Textos e Status
            this.txtVeneno = me.add.text(L.venenoX, L.bossTxtY, '☠️', {fontSize: '16px'})
                .setOrigin(0.5)
                .setAlpha(0);

            var styleEscudo = {fontSize: '16px', fill: '#aaaaff', fontStyle: 'bold'};
            var styleOuro = {fontSize: '16px', fill: '#ffffaa', fontStyle: 'bold'};
            var styleEstrela = {fontSize: '16px', fill: '#ffddaa', fontStyle: 'bold'};

            this.txtEscudo = me.add.text(L.escudoX, L.playerHpY + 40, '🛡️: 0', styleEscudo);
            this.txtOuro = me.add.text(L.ouroX, L.playerHpY + 40, '🪙: 0', styleOuro)
                .setOrigin(0.5, 0);
            this.txtEstrela = me.add.text(L.estrelaX, L.playerHpY + 40, '⭐x1.0', styleEstrela)
                .setOrigin(1, 0);

            // Barras de Vida e Mana do Chefe
            me.add.graphics().fillStyle(0x000000, 0.8).fillRect(L.bossUiX, L.bossHpY, 300, 22);
            this.bossHpBar = me.add.graphics();
            this.bossHpText = me.add.text(L.bossUiX + 150, L.bossTxtY, '', {
                fontSize: '15px',
                fill: '#ffffff',
                fontStyle: 'bold'
            }).setOrigin(0.5);

            me.add.graphics().fillStyle(0x000000, 0.8).fillRect(L.bossUiX, L.bossManaY, 300, 8);
            this.bossManaBar = me.add.graphics();

            // Barras de Vida e Mana do Aluno
            me.add.graphics().fillStyle(0x000000, 0.8).fillRect(L.playerUiX, L.playerHpY, 300, 22);
            this.playerHpBar = me.add.graphics();
            this.playerHpText = me.add.text(L.playerUiX + 150, L.playerTxtY, '', {
                fontSize: '15px',
                fill: '#ffffff',
                fontStyle: 'bold'
            }).setOrigin(0.5);

            me.add.graphics().fillStyle(0x000000, 0.8).fillRect(L.playerUiX, L.playerManaY, 300, 8);
            this.playerManaBar = me.add.graphics();

            this.setupButtons();
        }

        setupButtons() {
            var me = this.scene;
            var L = this.L;

            // Botão de Expandir Tela.
            // Desktop: API nativa de fullscreen (mais imersivo).
            // Mobile: expande o container via CSS para 100vw × 100vh sem esconder a UI do browser,
            //         permitindo que o botão Encolher permaneça acessível.
            const isMobile = window.matchMedia('(pointer: coarse)').matches;
            const container = document.getElementById('playerpuzzle-canvas-container');

            if (!document.getElementById('pp-fullscreen-style')) {
                const styleEl = document.createElement('style');
                styleEl.id = 'pp-fullscreen-style';
                styleEl.textContent = '#playerpuzzle-canvas-container.pp-fullscreen{' +
                    'position:fixed!important;top:0!important;left:0!important;' +
                    'width:100vw!important;height:100vh!important;z-index:9999!important;}';
                document.head.appendChild(styleEl);
            }

            var btnFullscreen = me.add.text(L.btnExpX, L.btnExpY, '[ Expandir ]', {
                fontSize: '20px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
            }).setOrigin(1, 0).setInteractive().setDepth(10);

            const onFullscreenChange = () => {
                if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                    btnFullscreen.setText('[ Expandir ]');
                }
            };
            document.addEventListener('fullscreenchange', onFullscreenChange);
            document.addEventListener('webkitfullscreenchange', onFullscreenChange);

            btnFullscreen.on('pointerdown', () => {
                me.cameras.main.fadeOut(200, 0, 0, 0);
                me.time.delayedCall(200, () => {
                    if (isMobile) {
                        if (container.classList.contains('pp-fullscreen')) {
                            container.classList.remove('pp-fullscreen');
                            btnFullscreen.setText('[ Expandir ]');
                            requestAnimationFrame(() => {
                                me.scale.refresh();
                                me.input.updateBounds();
                            });
                        } else {
                            container.classList.add('pp-fullscreen');
                            btnFullscreen.setText('[ Encolher ]');
                            requestAnimationFrame(() => {
                                me.scale.refresh();
                                me.input.updateBounds();
                            });
                        }
                    } else {
                        if (me.scale.isFullscreen) {
                            me.scale.stopFullscreen();
                            btnFullscreen.setText('[ Expandir ]');
                        } else {
                            me.scale.startFullscreen();
                            btnFullscreen.setText('[ Encolher ]');
                        }
                    }
                    me.cameras.main.fadeIn(200, 0, 0, 0);
                });
            });

            // Controles de Áudio
            me.musicOn = true;
            me.sfxOn = true;

            var btnMusic = me.add.text(20, 20, '🎵 Música', {
                fontSize: '16px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
            }).setInteractive().setDepth(10);

            var btnSfx = me.add.text(120, 20, '🔊 Efeitos', {
                fontSize: '16px', fill: '#ffffff', backgroundColor: '#333333', padding: {x: 8, y: 8}
            }).setInteractive().setDepth(10);

            btnMusic.on('pointerdown', function() {
                me.musicOn = !me.musicOn;
                btnMusic.setText(me.musicOn ? '🎵 Música' : '🔇 Música');
                btnMusic.setStyle({fill: me.musicOn ? '#ffffff' : '#aaaaaa'});
                if (me.musicOn) {
                    me.bgMusic.resume();
                } else {
                    me.bgMusic.pause();
                }
            });

            btnSfx.on('pointerdown', function() {
                me.sfxOn = !me.sfxOn;
                btnSfx.setText(me.sfxOn ? '🔊 Efeitos' : '🔈 Efeitos');
                btnSfx.setStyle({fill: me.sfxOn ? '#ffffff' : '#aaaaaa'});
                var vol = me.sfxOn ? 1 : 0;
                me.sfxSwap.setVolume(0.6 * vol);
                me.sfxMatch.setVolume(0.5 * vol);
                me.sfxHit.setVolume(0.8 * vol);
            });
        }

        atualizarBarraBoss(currentHp, maxHp, mana, envenenadoTurnos) {
            var pctHp = Math.max(0, currentHp / maxHp);
            this.bossHpBar.clear()
                .fillStyle(0xdd0000, 1)
                .fillRect(this.L.bossUiX + 2, this.L.bossHpY + 2, 296 * pctHp, 18);
            this.bossHpText.setText('Chefe: ' + Math.round(currentHp));

            var pctMana = Math.min(1, mana / 100);
            this.bossManaBar.clear()
                .fillStyle(0x0088ff, 1)
                .fillRect(this.L.bossUiX + 2, this.L.bossManaY + 1, 296 * pctMana, 6);

            this.txtVeneno.setAlpha(envenenadoTurnos > 0 ? 1 : 0);
        }

        atualizarBarraAluno(currentHp, maxHp, mana, escudo, ouro, multiplicador) {
            var pctHp = Math.max(0, currentHp / maxHp);
            this.playerHpBar.clear()
                .fillStyle(0x00cc00, 1)
                .fillRect(this.L.playerUiX + 2, this.L.playerHpY + 2, 296 * pctHp, 18);
            this.playerHpText.setText('Você: ' + Math.round(currentHp) + ' / ' + maxHp);

            var pctMana = Math.min(1, mana / 100);
            this.playerManaBar.clear()
                .fillStyle(0x0088ff, 1)
                .fillRect(this.L.playerUiX + 2, this.L.playerManaY + 1, 296 * pctMana, 6);

            this.txtEscudo.setText('🛡️: ' + escudo);
            this.txtOuro.setText('🪙: ' + ouro);
            this.txtEstrela.setText('⭐x' + multiplicador.toFixed(1));
        }
    }

    return UIHandler;
});
