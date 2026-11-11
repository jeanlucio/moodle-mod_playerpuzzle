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
 * The "How to play" help the student reads in the Lobby and during the fight.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use stdClass;

/**
 * Builds the context of the how-to-play template. What the activity does not have is left out: the question
 * sections when its bank is empty, the minimum of questions when none is required, the shop for a visitor, and the
 * Campaign text for a Single Match (and the other way round).
 */
class howtoplay {
    /** @var string[] The string of each piece type, in the order of the sprites (item0 to item6). */
    private const PIECES = ['star', 'grimoire', 'orb', 'sword', 'shield', 'potion', 'coin'];

    /** @var array[] The buttons of the stage, in the order they sit in, with the strings that name and explain them. */
    private const BUTTONS = [
        ['icon' => 'fa-flag', 'label' => 'stage_giveup', 'text' => 'howto_btn_giveup'],
        ['icon' => 'fa-question', 'label' => 'stage_howtoplay', 'text' => 'howto_btn_howto'],
        ['icon' => 'fa-dragon', 'label' => 'stage_opponent', 'text' => 'howto_btn_opponent'],
        ['icon' => 'fa-volume-high', 'label' => 'lobby_music_label', 'text' => 'howto_btn_music'],
        ['icon' => 'fa-bell', 'label' => 'lobby_sfx_label', 'text' => 'howto_btn_effects'],
        ['icon' => 'fa-expand', 'label' => 'stage_fullscreen', 'text' => 'howto_btn_fullscreen'],
    ];

    /**
     * The variables of mod_playerpuzzle/howtoplay_body for an activity.
     *
     * @param stdClass $instance Activity instance (gamemode, maxlevels, minquestions and the question bank).
     * @param bool $isguest Whether the reader is a visitor, who has only the Demo and so no shop.
     * @return array
     */
    public static function context(stdClass $instance, bool $isguest): array {
        global $OUTPUT;

        $pieces = [];
        foreach (self::PIECES as $type => $name) {
            $pieces[] = [
                'imageurl' => $OUTPUT->image_url('sprites/item' . $type, 'mod_playerpuzzle')->out(false),
                // The same sentence the Demo's balloon says when the piece first matches.
                'text' => get_string('tutorialballoon_' . $name, 'mod_playerpuzzle'),
            ];
        }

        $difficulties = [];
        foreach (playerpuzzle_get_difficulty_options() as $value => $label) {
            $difficulties[] = [
                'label' => $label,
                'text' => get_string('lobby_difficulty_desc_' . $value, 'mod_playerpuzzle'),
            ];
        }

        $buttons = [];
        foreach (self::BUTTONS as $button) {
            $buttons[] = [
                'icon' => $button['icon'],
                'label' => get_string($button['label'], 'mod_playerpuzzle'),
                'text' => get_string($button['text'], 'mod_playerpuzzle'),
            ];
        }

        $iscampaign = $instance->gamemode === constants::GAMEMODE_CAMPAIGN;
        $minquestions = (int) $instance->minquestions;

        return [
            'title' => get_string('howto_title', 'mod_playerpuzzle'),
            'closelabel' => get_string('close', 'mod_playerpuzzle'),
            'piecesheading' => get_string('howto_pieces_heading', 'mod_playerpuzzle'),
            'pieces' => $pieces,
            'showquestions' => questions_repository::has_approved_question((int) $instance->id),
            'questionsheading' => get_string('howto_questions_heading', 'mod_playerpuzzle'),
            'showminquestions' => $minquestions > 0,
            'minquestionstext' => get_string('howto_minquestions_text', 'mod_playerpuzzle', $minquestions),
            'difficultyheading' => get_string('howto_difficulty_heading', 'mod_playerpuzzle'),
            'difficulties' => $difficulties,
            'showcampaigndifficulty' => $iscampaign,
            'showshop' => !$isguest,
            'shopheading' => get_string('howto_shop_heading', 'mod_playerpuzzle'),
            'modeheading' => get_string('howto_mode_heading', 'mod_playerpuzzle'),
            'modetext' => $iscampaign
                ? get_string('howto_campaign_text', 'mod_playerpuzzle', max(1, (int) $instance->maxlevels))
                : get_string('howto_single_text', 'mod_playerpuzzle'),
            'buttonsheading' => get_string('howto_buttons_heading', 'mod_playerpuzzle'),
            'buttons' => $buttons,
            'controlsheading' => get_string('howto_controls_heading', 'mod_playerpuzzle'),
            'goalheading' => get_string('howto_goal_heading', 'mod_playerpuzzle'),
            'goaltext' => get_string('howto_goal_text', 'mod_playerpuzzle'),
            'turntext' => get_string('howto_turn_text', 'mod_playerpuzzle'),
            'questionstext' => get_string('howto_questions_text', 'mod_playerpuzzle'),
            'openingtext' => get_string('howto_opening_text', 'mod_playerpuzzle'),
            'difficultytext' => get_string('howto_difficulty_text', 'mod_playerpuzzle'),
            'campaigndifficultytext' => get_string('howto_difficulty_campaign', 'mod_playerpuzzle'),
            'shoptext' => get_string('howto_shop_text', 'mod_playerpuzzle'),
            'pointertext' => get_string('howto_controls_pointer', 'mod_playerpuzzle'),
            'keyboardtext' => get_string('howto_controls_keyboard', 'mod_playerpuzzle'),
        ];
    }
}
