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
 * The layout of the game: where everything stands on the stage, in the stage's own coordinate space. One for the
 * horizontal screen (1280 x 720, with the character stage and the carved side panels) and one for the vertical one
 * (540 x 960, a stage band over a dark backdrop and compact panels). The stage and the HUD draw from these numbers, so
 * a thing stands in the same place in every layout.
 *
 * @module     mod_playerpuzzle/layout
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], () => {
    'use strict';

    /**
     * Builds the layout for a screen shape.
     *
     * @param {boolean} isDesk Whether the screen is wider than it is tall.
     * @return {object} The layout.
     */
    const build = (isDesk) => {
        return isDesk ? {
            w: 1280, h: 720, aspect: '16/9', maxW: '100%',
            hasCharacterStage: true,

            // Character stage band: student/boss standing in front of stage_bg. stage_bg
            // reaches all the way to y=0 — the top buttons already carry their own opaque
            // background, so there's no seam to hide there.
            stageBgX: 640, stageBgY: 140, stageBgW: 1280, stageBgH: 280,
            bossScale: 190, playerScale: 190,
            bossX: 1090, bossY: 150,
            playerX: 190, playerY: 150,

            // Board + side panels band. panelY is the side panels' true top edge — they
            // stay flush against it, no overlap. panelW is derived from the board's own
            // size (not independent), so both panels always sit flush against its edges,
            // no gap. panelOverlap is consumed by the board instead: it rises above panelY
            // by that much, on purpose, reading as a raised platform poking up between the
            // two flanking panels — matches the reference screenshot the plugin is modeled
            // after — and board.js reuses the same number to extend its own backdrop fill
            // past the grid's real bottom edge, so the gap that rise opens up before the
            // true stage bottom (720) stays covered.
            panelY: 280, panelOverlap: 14, panelW: 420,
            // The carved frame (pix/panel_frame.webp) starts higher than panelY: its crest rises into the
            // stage band, just under the characters' feet (all the sprites stand at y 236), and the frame
            // runs to the bottom of the stage. Inside it the content has x 70-350 and y 332-646 to itself.
            panelTop: 244,
            // A different pair, boardOffX/Y, is fed only to board.js: it treats this pair
            // as the center of the first cell (pieces are origin-centered images) rather
            // than the grid's top-left corner — it's the grid's own corner (420, 280)
            // minus panelOverlap on Y (the platform rise) plus half a cell (27.5) to
            // compensate for the center-origin. Never reuse this pair for anything drawn
            // as a plain top-left-origin rectangle (e.g. the side panels).
            boardOffX: 447.5, boardOffY: 293.5,

            // HP bars now live inside the panel, above the resource row. Centered within
            // the panel's own width ((420 - hpBarW) / 2 = 60) — clears panel_stone.png's
            // carved border on both sides (was 40/940, sitting under the border on one
            // side and asymmetric within the panel on the other).
            hpBarW: 270, hpBarH: 26, hpBarRadius: 13,
            bossUiX: 935, bossHpY: 332, bossTxtY: 345,
            playerUiX: 75, playerHpY: 332, playerTxtY: 345,
            questionsCounterY: 362,

            // Consumables row: the two purchasable consumables with no meter of their own
            // (Potion, Sword). Shield and Quick Magic fill a ring instead, so their buy
            // badges live on that ring, not here. Coin/Star used to share this row but
            // they're passive readouts, not buttons — moved down beside the history block
            // (goldX/starX below), leaving this row to hold two items with room to breathe.
            // Kept clear of panel_stone.png's carved border, which eats ~57px of panel
            // width on each edge.
            potionX: 130, potionY: 412, swordX: 290, swordY: 412,
            // Boss side is the player row translated by the panel offset (860) — Potion and
            // Sword, plain icons with no buy badge (no boss shop), counting the opponent's
            // starting items when it has any.
            bossPotionX: 990, bossPotionY: 412, bossSwordX: 1150, bossSwordY: 412,
            bossItemIconSize: 60, bossItemFontSize: 16,

            // Coin/Star: passive quantity readouts, moved out of the action area to sit
            // beside the history block instead, stacked vertically just right of the scroll
            // banner — the space freed up when the Status badges moved to the HP bar's own
            // corner. Smaller icon (indicatorIconSize) than the consumable row's.
            goldX: 190, goldY: 538, starX: 285, starY: 538,
            bossGoldX: 1050, bossGoldY: 538, bossStarX: 1145, bossStarY: 538,
            indicatorIconSize: 26,
            // Gap between ring centers tightened 120 -> 100 (was 90/210/330) — same border
            // as the HP bar/resource row above, now clearing it on both sides instead of
            // sitting almost flush against it.
            bossRingY: 482, bossGrimoireX: 970, bossShieldRingX: 1070, bossOrbX: 1170,
            playerRingY: 482, playerGrimoireX: 110, playerShieldRingX: 210, playerOrbX: 310,

            ringRadius: 32, ringThickness: 7, ringIconSize: 40, resourceIconSize: 60,

            // Action history: 4 lines per side, below the rings. Desktop-only, same as
            // the player character and resource chips — no mobile equivalent yet. Title Y
            // (and the line Y that follows it) both shifted down — at the old 500/520 the
            // scroll banner behind the title (see hud.js createHistoryLog()) overlapped the
            // bottom of the ring row above it.
            historyTitleY: 538, historyBannerH: 46, historyLines: 4, historyLineY: 565, historyLineHeight: 19,

            // Compact circular icon buttons: Give Up and Help at the left edge, Music/Sound Effects/Expand
            // right-aligned —
            // replaces the old bracket-text "[ Word ]" buttons, which read as
            // generic HTML/Bootstrap controls rather than the game's own bronze-accent
            // dark-fill language already used for rings/badges. hud.js computes each
            // button's own X from L.w, topBtnGap and its position in its group (Give Up first
            // from the left, Expand last from the right), so only the shared Y/radius/gap live here.
            topBtnY: 26, topBtnRadius: 20, topBtnGap: 50
        } : {
            // Portrait layout: a stage band across the top, then the opponent's HUD, the
            // board, the student's HUD and the history button. The band shows the phase's
            // own wide stage (the same image the desktop band uses), scaled to the band's
            // height and centred, so the middle of the scene shows and no portrait art is
            // needed; the student and the opponent stand at its two ends, at a size that
            // reads on a phone. Below the band, a plain dark backdrop: every px here is
            // shared with the fixed 440x440 board, so the HUD clusters are single compact
            // rows on a translucent panel (hud.js::drawSimplePanel()), not the desktop's
            // panel_stone.png/scroll_banner.png artwork.
            w: 540, h: 960, aspect: '9/16', maxW: '540px',
            hasCharacterStage: false,
            stageBandH: 236,
            backdropColor: 0x2b241e,

            // Over the band: the icon buttons (Give Up/Help left, Music/Sound Effects/Expand right, see the
            // desktop L's own topBtnY/Radius/Gap comment) on row 1, and the level/phase
            // indicator on its own row 2 — the full pt_BR progress string does not fit next
            // to the buttons at any readable size.
            progressIndicatorY: 58,
            topBtnY: 24, topBtnRadius: 18, topBtnGap: 44,

            // The two characters stand on the band's floor, below the indicator row. Their
            // sprites put the feet at 95% of the frame, so a 150px sprite centred 14px above
            // the band's own centre lands its feet just above the band's bottom edge.
            playerX: 88, playerY: 158, playerScale: 150,
            bossX: 452, bossY: 158, bossScale: 150,

            // Opponent HUD: HP bar (Status badges hang off its own corner), then one row
            // with Coin/Star and the Grimoire/Shield/Orb rings — no Potion and no purchase
            // badges, since the opponent has no shop.
            panelTopBoss: 242, panelBottomBoss: 320,
            hpBarW: 300, hpBarH: 22, hpBarRadius: 11,
            bossUiX: 120, bossHpY: 250, bossTxtY: 261,
            bossGoldX: 60, bossGoldY: 296, bossStarX: 160, bossStarY: 296,
            bossRingY: 296, bossGrimoireX: 330, bossShieldRingX: 390, bossOrbX: 450,
            // The opponent's starting items, drawn only when it has any, between the Star
            // and the rings.
            bossPotionX: 248, bossPotionY: 296, bossSwordX: 290, bossSwordY: 296,
            bossItemIconSize: 26, bossItemFontSize: 11,

            // Board: fixed 440x440 (board.js hardcodes pieceSize 55 regardless of layout),
            // centred horizontally ((540-440)/2 = 50), 8px below the opponent's panel —
            // boardOffX/Y is the piece-centre reference board.js expects (top-left 50/328
            // plus half a cell 27.5), same convention as the desktop boardOffX/Y comment.
            boardOffX: 77.5, boardOffY: 355.5,

            // Student HUD: HP bar, the "Perguntas: X/N" counter (only with a minimum of
            // questions), then one row with Coin/Star, the Potion and the rings, with
            // purchase badges on the Potion, Grimoire and Shield.
            panelTopPlayer: 776, panelBottomPlayer: 870,
            playerUiX: 120, playerHpY: 784, playerTxtY: 795,
            questionsCounterY: 811,
            goldX: 60, goldY: 846, starX: 150, starY: 846,
            potionX: 250, potionY: 846,
            playerRingY: 846, playerGrimoireX: 330, playerShieldRingX: 390, playerOrbX: 450,

            // History: one button (not two always-visible 5-line blocks like desktop —
            // there's no room) opening a modal with Player/Boss tabs, see
            // hud.js::showHistoryModalMobile().
            historyBtnY: 904,

            resourceIconSize: 32,
            ringRadius: 16, ringThickness: 4, ringIconSize: 22,

            // Purchase badge scale for hud.js::createPurchaseBadge() — the desktop's default
            // 46x34 badge is bigger than mobile's own 32px-diameter ring, swallowing its
            // colored arc entirely. 0.72 is the smallest scale that still clears the WCAG
            // 24x24 real-px floor at mobile's true 1:1 CSS scale (34 * 0.72 ≈ 24.5),
            // leaving the ring's own icon/arc visible next to it. See
            // createPurchaseBadge()'s own docblock for the full scale math (desktop vs
            // mobile stage scaling).
            badgeScale: 0.72
        };
    };

    return {build};
});
