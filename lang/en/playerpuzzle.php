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

$string['advancingphase'] = 'Advancing to the next phase...';
$string['basebosshp'] = 'Base boss HP';
$string['basebosshp_help'] = 'Boss HP at Level 1, Phase 1. Scales automatically for later phases.';
$string['basestudenthp'] = 'Base student HP';
$string['basestudenthp_help'] = 'Student HP at Level 1, Phase 1. Scales automatically for later phases.';
$string['bossansweredcorrect'] = '✓ {$a} (Boss answered correctly!)';
$string['bossansweredwrong'] = '✗ {$a} (Boss answered incorrectly!)';
$string['bossavatar'] = 'Boss avatar';
$string['bosscorrectfeedback'] = '💥 The boss answered correctly! You take {$a} damage!';
$string['bossdamage'] = 'Boss damage';
$string['bossdamage_help'] = 'Damage dealt by a 3-piece Sword combination (or the Star critical hit, or one Grimoire poison tick) at Level 1, Phase 1. Scales automatically for later phases, same as boss/student HP. A 4-piece combination deals 1.5x this value, and a 5-piece combination deals 2x.';
$string['bosslostmultiplier'] = 'The boss lost its {$a}x multiplier!';
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
$string['coingain'] = 'Coin gain';
$string['coingain_help'] = 'Base coins earned from a 3-piece Coin combination. A 4-piece combination earns 1.5x this value, and a 5-piece combination earns 2x. Unlike boss/student HP and damage, this does not scale by level or phase, so a shop with fixed consumable prices stays balanced throughout a campaign.';
$string['coinscollected'] = 'Coins collected:';
$string['considererrors'] = 'Consider errors in the grade calculation';
$string['considererrors_help'] = 'When enabled, the number of wrong answers is factored into the final score, not just whether the Minimum Questions requirement was met. Only available once "Minimum questions per match" is set to 1 or more.';
$string['courseinstanceslist'] = 'All PlayerPuzzle activities in this course will be listed here.';
$string['defeat'] = '💔 DEFEAT 💔';
$string['error_hud_cost_qty'] = 'The quantity must be at least 1 when an item is configured.';
$string['exitwarning'] = "Quitting now will\nlose your progress!";
$string['expand'] = '[ Expand ]';
$string['gamemode'] = 'Game mode';
$string['gamemode_campaign'] = 'Campaign';
$string['gamemode_help'] = 'Campaign: the instance is a full game with levels and phases, HP scaling automatically as the student progresses. Single Match: the instance is one self-contained, repeatable match with no levels or phases.';
$string['gamemode_single'] = 'Single Match';
$string['general'] = 'General settings';
$string['grademethod'] = 'Grading method';
$string['grademethod_average'] = 'Average score';
$string['grademethod_average_all'] = 'Average over all required matches';
$string['grademethod_first'] = 'First attempt';
$string['grademethod_help'] = 'Defines how the final grade is calculated from the student\'s match attempts (Single Match mode only): <ul><li><strong>Highest score:</strong> the best score among all attempts.</li><li><strong>Average score:</strong> the average of the attempts actually made.</li><li><strong>First attempt:</strong> the score of the first attempt only.</li><li><strong>Last attempt:</strong> the score of the most recent attempt only.</li><li><strong>Average over all required matches:</strong> the sum of attempt scores divided by the configured maximum matches, so any match not attempted counts as zero. Requires the maximum matches to not be set to Unlimited.</li></ul>';
$string['grademethod_highest'] = 'Highest score';
$string['grademethod_last'] = 'Last attempt';
$string['historylogattack'] = '⚔️ Attack +{$a}';
$string['historylogcoins'] = '🪙 Coins +{$a}';
$string['historylogcritical'] = '💥 Critical! -{$a} HP';
$string['historylogempty'] = 'No moves yet';
$string['historylogheal'] = '💚 Heal +{$a}';
$string['historylogmana'] = '🔮 Mana +{$a}';
$string['historylogmultiplier'] = '⭐ Multiplier x{$a}';
$string['historylogmultiplierlost'] = '⭐ Multiplier lost';
$string['historylogpoisoncharge'] = '☠️ Poison +{$a}%';
$string['historylogpoisontick'] = '☠️ Poison: -{$a} HP';
$string['historylogshieldblock'] = '🛡️ Shield blocked!';
$string['historylogshieldcharge'] = '🛡️ Shield +{$a}%';
$string['historylogtitle'] = 'History';
$string['historylogwronganswer'] = '⚠️ Wrong! -{$a} HP';
$string['hpboss'] = 'Boss:';
$string['hpyou'] = 'You:';
$string['hud_coin_item'] = 'Item representing coins';
$string['hud_coin_item_help'] = 'Leftover coins from a winning attempt are granted as units of this PlayerHUD item. If not set, coins are discarded even when a session ends in victory.';
$string['hud_header'] = 'PlayerHUD integration';
$string['hud_item_deleted'] = 'Deleted item (please reconfigure)';
$string['hud_item_disabled'] = '{$a} (disabled)';
$string['hud_noitem'] = 'None configured';
$string['hud_notincourse'] = 'PlayerHUD integration will appear here once the PlayerHUD block is added to this course.';
$string['hud_potion_item'] = 'Item representing Potion';
$string['hud_potion_item_help'] = 'Optional. Each unit of this PlayerHUD item held is one consumable Potion use during combat, alongside the local-coin purchase.';
$string['hud_retry_cost_item'] = 'Item cost per retry';
$string['hud_retry_cost_item_help'] = 'Optional. Charged when clicking "Try Again" after a defeat, starting from the second attempt — the very first attempt is always free. Never extends the maximum attempts/matches configured, only charges a toll within that limit. Consider configuring an item the student earns through outside-the-game reinforcement content, not through playing PlayerPuzzle itself.';
$string['hud_retry_cost_qty'] = 'Quantity per retry';
$string['hud_shield_item'] = 'Item representing Shield level';
$string['hud_shield_item_help'] = 'The quantity of this PlayerHUD item a student holds is read as their Shield upgrade level.';
$string['hud_sword_item'] = 'Item representing Sword level';
$string['hud_sword_item_help'] = 'The quantity of this PlayerHUD item a student holds is read as their Sword upgrade level.';
$string['hud_win_grant_item'] = 'Item awarded on victory';
$string['hud_win_grant_item_help'] = 'Granted every time a match is won (every phase, in Campaign mode), separate from the coin balance. No XP is awarded for this item when the relevant attempt limit is set to Unlimited, mirroring PlayerHUD\'s own anti-farming rule for infinite drops.';
$string['hud_win_grant_qty'] = 'Quantity awarded on victory';
$string['invalidattempttoken'] = 'This attempt is invalid or has already been submitted.';
$string['levelsandphases'] = 'Levels and phases';
$string['loading'] = 'Loading...';
$string['loadinggame'] = 'Loading the game engine...';
$string['lobby_coinbalance'] = 'Coins: {$a}';
$string['lobby_currentprogress'] = 'Current progress: Level {$a->level}, Phase {$a->phase}';
$string['lobby_minquestions_notice'] = 'This match requires answering at least {$a} question(s).';
$string['lobby_shieldlevel'] = 'Shield level: {$a}';
$string['lobby_swordlevel'] = 'Sword level: {$a}';
$string['lobbywelcome'] = 'Welcome to the Lobby! The Shop and the Play button will be here soon.';
$string['max_single_matches'] = 'Number of single matches';
$string['maxattempts'] = 'Maximum attempts';
$string['maxattemptsreached'] = 'You have used all the attempts available for this activity.';
$string['maxlevels'] = 'Number of levels';
$string['maxlevels_help'] = 'Each level contains 10 phases of increasing difficulty. 1 level = 10 phases; 10 levels = 100 phases.';
$string['maxmultiplier'] = 'Max multiplier:';
$string['maxsinglematchesreached'] = 'You have used all the matches available for this activity.';
$string['minquestions'] = 'Minimum questions per match';
$string['minquestions_help'] = 'The student must answer at least this many questions (right or wrong) before the match can end in victory. If the boss reaches 0 HP before the counter is reached, the boss revives at 50% HP and combat continues. Set to 0 to disable this requirement.';
$string['modulename'] = 'PlayerPuzzle';
$string['modulename_help'] = 'The PlayerPuzzle activity enables a teacher to create a Match-3 RPG game where students combine gems to defeat a boss, answer questions, and earn coins.';
$string['modulenameplural'] = 'PlayerPuzzles';
$string['musicoff'] = '🔇 Music';
$string['musicon'] = '🎵 Music';
$string['name'] = 'Phase name';
$string['nextlevel'] = 'Next level: {$a}';
$string['nextphase'] = 'Next phase: {$a}';
$string['noanswers'] = 'No answers available.';
$string['nocategories'] = 'No question categories found.';
$string['nonextphase'] = 'There is no next phase to advance to — finish the campaign instead.';
$string['phaseadvanceerror'] = 'Error advancing to the next phase.';
$string['phasecompletetitle'] = '🏆 Phase complete!';
$string['phasenotwon'] = 'The reported damage does not clear this phase\'s boss HP.';
$string['playercorrect'] = '✓ Correct! The boss takes damage!';
$string['playerlostmultiplier'] = 'You lost your {$a}x multiplier!';
$string['playerpuzzle:addinstance'] = 'Add a new PlayerPuzzle';
$string['playerpuzzle:view'] = 'View PlayerPuzzle activity';
$string['playerwrong'] = '✗ Wrong! You take {$a} damage!';
$string['playgame'] = 'Play Game';
$string['pluginadministration'] = 'PlayerPuzzle administration';
$string['pluginname'] = 'PlayerPuzzle';
$string['privacy:metadata:bosshp_remaining'] = 'The boss HP remaining when this attempt ended.';
$string['privacy:metadata:currentlevel'] = 'The level the user was playing when this attempt was recorded.';
$string['privacy:metadata:currentphase'] = 'The phase the user was playing when this attempt was recorded.';
$string['privacy:metadata:playerpuzzle_attempts'] = 'Stores each attempt a student makes at a PlayerPuzzle activity, including progress and outcome.';
$string['privacy:metadata:questions_correct'] = 'The number of questions answered correctly in this attempt.';
$string['privacy:metadata:questions_total'] = 'The total number of questions asked in this attempt.';
$string['privacy:metadata:score'] = 'The final score calculated for this attempt.';
$string['privacy:metadata:status'] = 'The status of this attempt: in progress, won, lost, timed out, or abandoned.';
$string['privacy:metadata:timecreated'] = 'The time at which this record was created.';
$string['privacy:metadata:timefinished'] = 'The time at which the attempt ended.';
$string['privacy:metadata:timemodified'] = 'The time at which this record was last modified.';
$string['privacy:metadata:userid'] = 'The ID of the user this record belongs to.';
$string['progressindicator'] = 'Level {$a->level} — Phase {$a->phase} of 10';
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
$string['singlematchheader'] = 'Single Match';
$string['skip'] = 'Skip';
$string['studentsettings'] = 'Student settings';
$string['timelimit'] = 'Time limit (minutes)';
$string['timelimit_help'] = 'Set to 0 for no time limit.';
$string['unlimited'] = 'Unlimited';
$string['victory'] = '🌟 VICTORY! 🌟';
