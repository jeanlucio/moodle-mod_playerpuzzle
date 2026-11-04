<?php
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
 * What the student can read about an opponent: who it is and what it fights with.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\combat_engine;
use stdClass;

/**
 * The opponent's card, shown in the shop before a phase (to help decide what to buy) and in a dialog during the
 * fight. The text is worked out from the same numbers the fight runs on (see combat::phase_opponent()), so a
 * change to an opponent's profile, armor or powers reaches the card without touching its words.
 */
class opponent_card {
    /**
     * The card of the opponent the student fights next: the phase under way (a fresh one, as on the screen
     * between phases, or one being fought) or, with none, the phase the next attempt will start on.
     *
     * Shows nothing when there is no opponent to name yet: a Campaign whose hero is not chosen. The items
     * the opponent brings depend on the difficulty, which an attempt fixes and the Lobby still lets the
     * student pick, so they are listed only for a phase that exists.
     *
     * @param stdClass $instance Activity instance (id, gamemode, maxlevels, maxattempts, bossavatar, basebosshp,
     *  bossdamage).
     * @param int $userid User ID.
     * @return array Template data to merge into the shop's: 'opponentcard', or empty.
     */
    public static function for_player(stdClass $instance, int $userid): array {
        global $DB;

        $campaign = $instance->gamemode === constants::GAMEMODE_CAMPAIGN;
        $inprogress = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'status' => 'inprogress', 'isdemo' => 0],
            'timecreated DESC, id DESC',
            'id, isdemo, difficulty, currentlevel, currentphase, frozenbasebosshp, frozenbossdamage, frozenopponent, ' .
                'frozenhero',
            0,
            1
        );
        $attempt = reset($inprogress);
        if ($attempt) {
            $level = (int) $attempt->currentlevel;
            $phase = (int) $attempt->currentphase;
            // A row from before the opponent was frozen has none, and the phase's own opponent stands in.
            $opponent = (string) $attempt->frozenopponent !== ''
                ? (string) $attempt->frozenopponent
                : opponents::for_phase($instance, $level, $phase);
            $side = combat::phase_opponent($attempt, $instance);

            return ['opponentcard' => self::build($opponent, $campaign, $level, $phase, $side, false, 'next')];
        }

        if (!$campaign) {
            $opponent = opponents::normalise($instance->bossavatar ?? null);
            // The hero of a Single Match is chosen after the Lobby, so the final boss's answer to it is not known.
            $modifiers = opponents::modifiers($opponent);
            $side = [
                'bossattack' => $modifiers['attack'],
                'bossarmor' => $modifiers['armor'],
                'bosspowers' => opponents::powers($opponent),
                'bossbreathevery' => 0,
                'bosskit' => null,
            ];

            return ['opponentcard' => self::build($opponent, false, 1, 1, $side, false, 'next')];
        }

        $next = campaigns::next_start((int) $instance->id, $userid, (int) $instance->maxlevels, (int) $instance->maxattempts);
        $hero = game_page_service::current_hero($instance, $userid);
        if ($next->startsnew || $hero === null) {
            return [];
        }
        $opponent = opponents::for_phase($instance, (int) $next->level, (int) $next->phase);
        $side = combat::phase_opponent((object) [
            'isdemo' => 0,
            'difficulty' => constants::DIFFICULTY_NORMAL,
            'currentlevel' => $next->level,
            'currentphase' => $next->phase,
            'frozenbasebosshp' => 0,
            'frozenbossdamage' => 0,
            'frozenopponent' => $opponent,
            'frozenhero' => heroes::sprite($hero->hero, (int) $hero->stage),
        ], $instance);
        $side['bosskit'] = null;

        return ['opponentcard' => self::build($opponent, true, (int) $next->level, (int) $next->phase, $side, false, 'next')];
    }

    /**
     * The card of the opponent of the phase being fought, from the game page's config.
     *
     * @param array $config The config game_page_service::build_game_config() returns: bossavatar, gamemode,
     *  currentlevel, currentphase, isdemo and the opponent's side (bossattack, bossarmor, bosspowers,
     *  bossbreathevery, bosskit).
     * @return array The card, see build().
     */
    public static function for_fight(array $config): array {
        $side = [
            'bossattack' => (float) ($config['bossattack'] ?? 1.0),
            'bossarmor' => (float) ($config['bossarmor'] ?? 0.0),
            'bosspowers' => (array) ($config['bosspowers'] ?? []),
            'bossbreathevery' => (int) ($config['bossbreathevery'] ?? 0),
            'bosskit' => $config['bosskit'] ?? null,
        ];

        return self::build(
            opponents::normalise((string) ($config['bossavatar'] ?? '')),
            ($config['gamemode'] ?? '') === constants::GAMEMODE_CAMPAIGN,
            (int) ($config['currentlevel'] ?? 1),
            (int) ($config['currentphase'] ?? 1),
            $side,
            !empty($config['isdemo']),
            'now'
        );
    }

    /**
     * Builds the card: the opponent's name and picture, where in the Campaign it is fought, and one line for each
     * thing that sets it apart.
     *
     * @param string $opponent An opponent key.
     * @param bool $campaign Whether the phase is part of a Campaign, which has levels and phases to name.
     * @param int $level The phase's level.
     * @param int $phase The phase within the level.
     * @param array $side The opponent's side of the phase: bossattack (multiplier on its hits), bossarmor (share
     *  of each hit it takes that it absorbs), bosspowers, bossbreathevery (0 for the default rhythm) and bosskit
     *  (['potion' => int, 'sword' => int], or null to leave the items out).
     * @param bool $demo Whether the fight is a Demo, which has no profile and no extra HP.
     * @param string $heading 'next' for the opponent of a phase to come, 'now' for the one being fought.
     * @return array ['heading', 'name', 'spriteurl', 'phasetext', 'traits' => [['text' => string]]]
     */
    public static function build(
        string $opponent,
        bool $campaign,
        int $level,
        int $phase,
        array $side,
        bool $demo,
        string $heading
    ): array {
        $traits = [];
        $hpmore = $demo ? 0 : (int) round((opponents::modifiers($opponent)['hp'] - 1) * 100);
        if ($hpmore > 0) {
            $traits[] = get_string('opponent_trait_hp', 'mod_playerpuzzle', $hpmore);
        }
        $attackmore = (int) round(($side['bossattack'] - 1) * 100);
        if ($attackmore > 0) {
            $traits[] = get_string('opponent_trait_attack', 'mod_playerpuzzle', $attackmore);
        }
        $armor = (int) round($side['bossarmor'] * 100);
        if ($armor > 0) {
            $traits[] = get_string('opponent_trait_armor', 'mod_playerpuzzle', $armor);
        }

        $breathevery = combat_engine::breath_every(['bossBreathEvery' => $side['bossbreathevery']]);
        foreach ($side['bosspowers'] as $power) {
            $traits[] = get_string('power_' . $power, 'mod_playerpuzzle', $breathevery);
        }

        $kit = $side['bosskit'] ?? null;
        if ($kit !== null && ($kit['potion'] > 0 || $kit['sword'] > 0)) {
            $traits[] = get_string('opponentkit', 'mod_playerpuzzle', (object) [
                'potions' => $kit['potion'],
                'swords' => $kit['sword'],
            ]);
        }
        if (!$demo && $opponent === opponents::FINAL_BOSS) {
            $traits[] = get_string('opponent_trait_adapts', 'mod_playerpuzzle');
        }
        if ($traits === []) {
            $traits[] = get_string('opponent_trait_none', 'mod_playerpuzzle');
        }

        return [
            'heading' => get_string('opponent_card_' . $heading, 'mod_playerpuzzle'),
            'name' => get_string('bossavatar_' . $opponent, 'mod_playerpuzzle'),
            'spriteurl' => opponents::sprite_url($opponent)->out(false),
            'phasetext' => $campaign
                ? get_string('opponent_card_phase', 'mod_playerpuzzle', (object) ['level' => $level, 'phase' => $phase])
                : '',
            'traits' => array_map(static fn(string $text): array => ['text' => $text], $traits),
        ];
    }
}
