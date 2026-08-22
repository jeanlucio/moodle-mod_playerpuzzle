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
 * English strings for PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['basebosshp'] = 'Base boss HP';
$string['basebosshp_help'] = 'Boss HP at Level 1, Phase 1. Scales automatically for later phases.';
$string['basestudenthp'] = 'Base student HP';
$string['basestudenthp_help'] = 'Student HP at Level 1, Phase 1. Scales automatically for later phases.';
$string['bossansweredcorrect'] = '✓ {$a} (Boss answered correctly!)';
$string['bossansweredwrong'] = '✗ {$a} (Boss answered incorrectly!)';
$string['bossavatar'] = 'Boss avatar';
$string['bosscorrectfeedback'] = '💥 The boss answered correctly! You take {$a} damage!';
$string['bossdamage'] = 'Boss damage';
$string['bosssettings'] = 'Boss settings';
$string['bosstrigger'] = '👹 The boss triggered a question!';
$string['bosswrongfeedback'] = '😅 The boss answered incorrectly! Lucky escape!';
$string['btnattack'] = '⚔️ Attack!';
$string['btncontinue'] = 'Continue';
$string['btnexit'] = '✕ Exit';
$string['btnexitgame'] = '🚪 Exit game';
$string['btnplayagain'] = '🎮 Play again';
$string['btnquit'] = 'Quit';
$string['close'] = 'Close';
$string['coinscollected'] = 'Coins collected:';
$string['courseinstanceslist'] = 'All PlayerPuzzle activities in this course will be listed here.';
$string['defeat'] = '💔 DEFEAT 💔';
$string['exitwarning'] = "Quitting now will\nlose your progress!";
$string['expand'] = '[ Expand ]';
$string['general'] = 'General settings';
$string['hpboss'] = 'Boss:';
$string['hpyou'] = 'You:';
$string['invalidattempttoken'] = 'This attempt is invalid or has already been submitted.';
$string['levelsandphases'] = 'Levels and phases';
$string['loading'] = 'Loading...';
$string['loadinggame'] = 'Loading the game engine...';
$string['lobbywelcome'] = 'Welcome to the Lobby! The Shop and the Play button will be here soon.';
$string['maxattempts'] = 'Maximum attempts';
$string['maxattemptsreached'] = 'You have used all the attempts available for this activity.';
$string['maxlevels'] = 'Number of levels';
$string['maxlevels_help'] = 'Each level contains 10 phases of increasing difficulty. 1 level = 10 phases; 10 levels = 100 phases.';
$string['maxmultiplier'] = 'Max multiplier:';
$string['modulename'] = 'PlayerPuzzle';
$string['modulename_help'] = 'The PlayerPuzzle activity enables a teacher to create a Match-3 RPG game where students combine gems to defeat a boss, answer questions, and earn coins.';
$string['modulenameplural'] = 'PlayerPuzzles';
$string['musicoff'] = '🔇 Music';
$string['musicon'] = '🎵 Music';
$string['name'] = 'Phase name';
$string['noanswers'] = 'No answers available.';
$string['nocategories'] = 'No question categories found.';
$string['playercorrect'] = '✓ Correct! The boss takes damage!';
$string['playerpuzzle:addinstance'] = 'Add a new PlayerPuzzle';
$string['playerpuzzle:view'] = 'View PlayerPuzzle activity';
$string['playerwrong'] = '✗ Wrong! You take {$a} damage!';
$string['playgame'] = 'Play Game';
$string['pluginadministration'] = 'PlayerPuzzle administration';
$string['pluginname'] = 'PlayerPuzzle';
$string['privacy:metadata:bosshp_remaining'] = 'The boss HP remaining when this attempt ended.';
$string['privacy:metadata:coins'] = 'The number of coins the user has collected.';
$string['privacy:metadata:currentlevel'] = 'The level the user was playing when this attempt was recorded.';
$string['privacy:metadata:currentphase'] = 'The phase the user was playing when this attempt was recorded.';
$string['privacy:metadata:playerpuzzle_attempts'] = 'Stores each attempt a student makes at a PlayerPuzzle activity, including progress and outcome.';
$string['privacy:metadata:playerpuzzle_inventory'] = 'Stores the coins and upgrade levels a student has accumulated across all PlayerPuzzle activities.';
$string['privacy:metadata:questions_correct'] = 'The number of questions answered correctly in this attempt.';
$string['privacy:metadata:questions_total'] = 'The total number of questions asked in this attempt.';
$string['privacy:metadata:score'] = 'The final score calculated for this attempt.';
$string['privacy:metadata:shieldlevel'] = 'The level of the shield upgrade the user has purchased.';
$string['privacy:metadata:status'] = 'The status of this attempt: in progress, won, lost, timed out, or abandoned.';
$string['privacy:metadata:swordlevel'] = 'The level of the sword upgrade the user has purchased.';
$string['privacy:metadata:timecreated'] = 'The time at which this record was created.';
$string['privacy:metadata:timefinished'] = 'The time at which the attempt ended.';
$string['privacy:metadata:timemodified'] = 'The time at which this record was last modified.';
$string['privacy:metadata:userid'] = 'The ID of the user this record belongs to.';
$string['progresssaved'] = 'Progress saved! (Total coins: {$a})';
$string['questioncategory'] = 'Question category';
$string['questionchallenge'] = '⚔️ Magic Challenge!';
$string['questionerror'] = 'Question error.';
$string['questionsettings'] = 'Question settings';
$string['requirejserror'] = 'Critical error: could not load the game engine.';
$string['rules'] = 'Game rules';
$string['saveerror'] = 'Error saving to server.';
$string['savingprogress'] = 'Saving progress...';
$string['sfxoff'] = '🔈 Effects';
$string['sfxon'] = '🔊 Effects';
$string['shrink'] = '[ Shrink ]';
$string['shuffling'] = 'SHUFFLING...';
$string['skip'] = 'Skip';
$string['timelimit'] = 'Time limit (minutes)';
$string['timelimit_help'] = 'Set to 0 for no time limit.';
$string['unlimited'] = 'Unlimited';
$string['victory'] = '🌟 VICTORY! 🌟';
