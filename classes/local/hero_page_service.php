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
 * Service building the hero choice screen (hero.php).
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use moodle_url;
use stdClass;

/**
 * The screen where a student picks a hero, always between the Lobby's Play button and the match: in a
 * Campaign it comes on the first Play and on the first Play of each new Campaign, and the choice, which then
 * stays for the whole Campaign, is saved by play.php as the match starts (see choose_for_play()). For a
 * Single Match or a Demo it only picks how the student looks. Every mode goes on to play.php with the
 * choice; the hero last chosen for a Single Match comes marked.
 */
class hero_page_service {
    /** @var string The student is choosing the hero of their Campaign. */
    public const MODE_CAMPAIGN = 'campaign';

    /** @var string The student is choosing the hero of a Single Match. */
    public const MODE_SINGLE = 'single';

    /** @var string The student is choosing the hero of a Demo. */
    public const MODE_DEMO = 'demo';

    /** @var string[] Every mode, in the order the Lobby offers them. */
    public const MODES = [self::MODE_CAMPAIGN, self::MODE_SINGLE, self::MODE_DEMO];

    /** @var string choose_for_play(): the Campaign has no hero to choose, play on. */
    public const CHOICE_NONE = 'none';

    /** @var string choose_for_play(): the hero was saved, play on. */
    public const CHOICE_SAVED = 'saved';

    /** @var string choose_for_play(): something came as the hero that is not one of them. */
    public const CHOICE_INVALID = 'invalid';

    /** @var string choose_for_play(): no hero came, or a new Campaign was not confirmed: back to the Lobby. */
    public const CHOICE_LOBBY = 'lobby';

    /**
     * Whether a student may choose a hero in a mode: a Campaign hero only in a Campaign activity that
     * still needs one, a Single Match hero only in a Single Match activity, and the Demo anywhere.
     *
     * @param stdClass $instance Activity instance (gamemode, and what hero_stage() reads).
     * @param int $userid User ID.
     * @param string $mode One of self::MODES.
     * @return bool
     */
    public static function is_available(stdClass $instance, int $userid, string $mode): bool {
        return match ($mode) {
            self::MODE_CAMPAIGN => game_page_service::hero_stage($instance, $userid) !== game_page_service::HERO_NONE,
            self::MODE_SINGLE => $instance->gamemode === constants::GAMEMODE_SINGLE,
            self::MODE_DEMO => true,
            default => false,
        };
    }

    /**
     * Saves the hero a student sent on from the choice screen, when the Campaign they are about to play still
     * needs one, so the match can start in the same request. A new Campaign empties the student's PuzzleCoin
     * and stock, so it is only started once the Lobby's warning was confirmed.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid User ID.
     * @param string $hero The hero key that came with the request, possibly empty or unknown.
     * @param bool $confirmed Whether the Lobby's new Campaign warning was confirmed.
     * @return string One of the self::CHOICE_* constants.
     */
    public static function choose_for_play(stdClass $instance, int $userid, string $hero, bool $confirmed): string {
        $stage = game_page_service::hero_stage($instance, $userid);
        if ($stage === game_page_service::HERO_NONE) {
            return self::CHOICE_NONE;
        }
        // A request with no hero, or one for a new Campaign the Lobby has not warned about, is a stale link or the
        // end screen's "Play again": the Lobby is where the choice begins.
        if ($hero === '' || ($stage === game_page_service::HERO_NEW && !$confirmed)) {
            return self::CHOICE_LOBBY;
        }
        if (!heroes::is_valid($hero)) {
            return self::CHOICE_INVALID;
        }
        game_page_service::choose_campaign_hero($instance, $userid, $hero);

        return self::CHOICE_SAVED;
    }

    /**
     * Builds the template context of the choice screen.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param string $mode One of self::MODES.
     * @param array $carry Fields to send on with the choice (difficulty, mobile, confirmnewcampaign), by name.
     * @param string $error A lang string key to show above the heroes, or ''.
     * @return array
     */
    public static function build_page_data(
        stdClass $cm,
        stdClass $instance,
        int $userid,
        string $mode,
        array $carry,
        string $error = ''
    ): array {
        $campaign = $mode === self::MODE_CAMPAIGN;
        $fields = $carry + ['mode' => $mode];
        if (!$campaign) {
            $fields['isdemo'] = $mode === self::MODE_DEMO ? 1 : 0;
        }

        $stage = game_page_service::hero_stage($instance, $userid);
        $intro = $campaign
            ? ($stage === game_page_service::HERO_NEW ? 'hero_intro_newcampaign' : 'hero_intro_campaign')
            : 'hero_intro_match';
        // A new Campaign comes marked with the hero of the one that ended, and a Single Match with the last one
        // chosen for it. The first Campaign has no hero to mark.
        $previous = $campaign ? user_hero::latest((int) $instance->id, $userid) : null;
        $marked = $campaign ? ($previous === null ? '' : $previous->hero) : last_hero::get($userid);

        $heroes = [];
        foreach (heroes::CATALOG as $key => $specialty) {
            $heroes[] = [
                'key' => $key,
                'name' => get_string('hero_name_' . $key, 'mod_playerpuzzle'),
                'specialty' => get_string(
                    'hero_specialty',
                    'mod_playerpuzzle',
                    get_string('hero_attribute_' . $specialty, 'mod_playerpuzzle')
                ),
                'imageurl' => heroes::sprite_url(heroes::sprite($key, 1))->out(false),
                'checked' => $key === $marked,
            ];
        }

        return [
            'formurl' => (new moodle_url('/mod/playerpuzzle/play.php', ['id' => $cm->id]))->out(false),
            'sesskey' => sesskey(),
            'fields' => array_map(
                static fn(string $name, $value): array => ['name' => $name, 'value' => (string) $value],
                array_keys($fields),
                array_values($fields)
            ),
            // The Lobby's own art, so the choice looks like part of the same game.
            'frameurl' => (new moodle_url('/mod/playerpuzzle/pix/lobby_frame.webp'))->out(false),
            'textureurl' => (new moodle_url('/mod/playerpuzzle/pix/lobby_texture.webp'))->out(false),
            'scrollbannerurl' => (new moodle_url('/mod/playerpuzzle/pix/scroll_banner.webp'))->out(false),
            'title' => get_string('hero_title', 'mod_playerpuzzle'),
            'intro' => get_string($intro, 'mod_playerpuzzle'),
            // Only a Campaign hero has an attribute that matters; in a Single Match it is a look, so a legend is noise.
            'attributeslegend' => $campaign ? get_string('hero_attributes_legend', 'mod_playerpuzzle') : '',
            'error' => $error === '' ? '' : get_string($error, 'mod_playerpuzzle'),
            'legend' => get_string('hero_title', 'mod_playerpuzzle'),
            'heroes' => $heroes,
            'submitlabel' => get_string('hero_play_button', 'mod_playerpuzzle'),
            // Every way to this screen starts from the Lobby's Play button, so Back leads there.
            'backurl' => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]))->out(false),
            'backlabel' => get_string('hero_back', 'mod_playerpuzzle'),
        ];
    }
}
