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
 * Service to build PlayerPuzzle game page (play.php) state.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context;
use context_module;
use mod_playerpuzzle\local\engine\combat;
use mod_playerpuzzle\local\engine\replay;
use mod_playerpuzzle\local\engine\security;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Handles the attempt-limit check and game config assembly for play.php.
 */
class game_page_service {
    /**
     * Keeps visitors to the Demo: the guest account, and a logged-in user who is in the
     * course only through guest access. A real attempt, its coins and its grade belong to a
     * participant, and the guest account is one record shared by every visitor, so they
     * could never be told apart; the Demo already counts for none of that. Same stance
     * mod_playerwords takes by letting guests play a round that persists nothing.
     *
     * @param bool $isdemo Whether the requested fight is the Demo.
     * @param moodle_url $returnurl Where the error page links back to.
     * @param context $context Module context.
     * @return void
     * @throws moodle_exception When a visitor asks for a real attempt.
     */
    public static function check_guest_demo_only(bool $isdemo, moodle_url $returnurl, context $context): void {
        if (!$isdemo && is_guest($context)) {
            throw new moodle_exception('guestdemoonly', 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Checks the attempt limit for the instance's own game mode, throwing when it has
     * already been reached. Single Match counts finished attempts against
     * `max_single_matches` (each match played, win or lose, spends one). Campaign limits the
     * number of Campaigns (`maxcampaigns`): `maxattempts` is spent inside one Campaign and,
     * once used up, ends it, so the next attempt starts the next Campaign, which is what this
     * refuses past the limit. Both limits are 0 = unlimited, and an in-progress attempt is
     * always resumed (resume_or_create_attempt_token()), never refused here.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the limit for this game mode has been reached.
     */
    public static function check_attempt_limit(stdClass $instance, int $userid, moodle_url $returnurl): void {
        global $DB;

        if ($instance->gamemode !== constants::GAMEMODE_SINGLE) {
            $refusal = self::campaign_limit_refusal($instance, $userid);
            if ($refusal !== null) {
                throw new moodle_exception($refusal, 'mod_playerpuzzle', $returnurl);
            }
            return;
        }

        $limit = (int) $instance->max_single_matches;
        if ($limit <= 0) {
            return;
        }

        // Demo attempts never count against this limit — see check_retry_cost()'s own
        // isdemo exclusion for the same rationale.
        $finishedattempts = $DB->count_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0',
            ['ppid' => $instance->id, 'uid' => $userid, 'inprogress' => 'inprogress']
        );
        if ($finishedattempts >= $limit) {
            throw new moodle_exception('maxsinglematchesreached', 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Why a Campaign-mode student may not start another attempt, if they may not: the next attempt
     * would begin a Campaign past `maxcampaigns`. The reason names how the last Campaign ended, so a
     * student who ran out of attempts is told so rather than that they played every Campaign.
     *
     * @param stdClass $instance Activity instance (id, maxlevels, maxattempts, maxcampaigns).
     * @param int $userid User ID.
     * @return string|null The lang string key of the refusal, or null when the student may play.
     */
    public static function campaign_limit_refusal(stdClass $instance, int $userid): ?string {
        $limit = (int) $instance->maxcampaigns;
        if ($limit <= 0 || security::has_inprogress_attempt((int) $instance->id, $userid)) {
            return null;
        }

        $start = self::next_campaign_start($instance, $userid);
        if ($start->campaign <= $limit) {
            return null;
        }

        return $start->ended === campaigns::ENDED_ATTEMPTS ? 'maxattemptsreached' : 'maxcampaignsreached';
    }

    /** @var string The Campaign has its hero, or there is no Campaign to start: nothing to choose. */
    public const HERO_NONE = 'none';

    /** @var string The first Campaign, or one in progress that has no hero: the choice screen comes first. */
    public const HERO_FIRST = 'first';

    /** @var string A new Campaign after an earlier one ended: the Lobby asks to confirm it, then the choice. */
    public const HERO_NEW = 'new';

    /**
     * What the student must still do before a Campaign attempt can start: choose the hero of the Campaign
     * they are about to play. A Campaign that has ended, with another allowed, is a new one: choosing its
     * hero is what starts it, so the Lobby warns first (see choose_campaign_hero()). Never for a Single
     * Match, which has no Campaign, and nothing while the activity allows no more Campaigns.
     *
     * @param stdClass $instance Activity instance (id, gamemode, maxlevels, maxattempts, maxcampaigns).
     * @param int $userid User ID.
     * @return string One of the self::HERO_* constants.
     */
    public static function hero_stage(stdClass $instance, int $userid): string {
        if ($instance->gamemode !== constants::GAMEMODE_CAMPAIGN || self::campaign_limit_refusal($instance, $userid) !== null) {
            return self::HERO_NONE;
        }

        $campaign = self::campaign_to_play($instance, $userid);
        if (user_hero::get((int) $instance->id, $userid, $campaign->number) !== null) {
            return self::HERO_NONE;
        }

        return $campaign->startsnew ? self::HERO_NEW : self::HERO_FIRST;
    }

    /**
     * Whether the student's next Play begins a new Campaign after an earlier one ended and has not yet
     * been confirmed: the Lobby warns that it empties their PuzzleCoin and stock, and only then goes on
     * to the choice of hero.
     *
     * @param stdClass $instance Activity instance (id, gamemode, maxlevels, maxattempts, maxcampaigns).
     * @param int $userid User ID.
     * @return bool
     */
    public static function needs_new_campaign_confirmation(stdClass $instance, int $userid): bool {
        return self::hero_stage($instance, $userid) === self::HERO_NEW;
    }

    /**
     * Chooses the hero of the Campaign the student is about to play, or is playing without one. Starting
     * a Campaign after an earlier one ended empties the student's PuzzleCoin and consumables, and that
     * happens here, once, under the stock lock so a purchase racing it never lands half on each side.
     *
     * @param stdClass $instance Activity instance (id, gamemode, maxlevels, maxattempts).
     * @param int $userid User ID.
     * @param string $hero A key of heroes::CATALOG.
     * @return stdClass The Campaign's hero row.
     * @throws moodle_exception When the stock lock could not be acquired, so the new Campaign never
     *  starts with the old balance still in place.
     */
    public static function choose_campaign_hero(stdClass $instance, int $userid, string $hero): stdClass {
        $ppid = (int) $instance->id;
        $campaign = self::campaign_to_play($instance, $userid);
        if (!$campaign->startsnew || user_hero::get($ppid, $userid, $campaign->number) !== null) {
            return user_hero::choose($ppid, $userid, $campaign->number, $hero);
        }

        $row = security::with_locked_user_stock($userid, $ppid, function () use ($ppid, $userid, $campaign, $hero): stdClass {
            user_stock::clear_for_user($userid, $ppid);
            return user_hero::choose($ppid, $userid, $campaign->number, $hero);
        });
        if ($row === false) {
            throw new moodle_exception('startbusy', 'mod_playerpuzzle');
        }

        return $row;
    }

    /**
     * The hero of the Campaign the student is playing, for showing it in the Lobby.
     *
     * @param stdClass $instance Activity instance (id, gamemode, maxlevels, maxattempts).
     * @param int $userid User ID.
     * @return stdClass|null The hero row, or null in a Single Match or while the Campaign has none.
     */
    public static function current_hero(stdClass $instance, int $userid): ?stdClass {
        if ($instance->gamemode !== constants::GAMEMODE_CAMPAIGN) {
            return null;
        }

        return user_hero::get((int) $instance->id, $userid, self::campaign_to_play($instance, $userid)->number);
    }

    /**
     * Which Campaign the student's next Campaign attempt belongs to: the one with an attempt in progress,
     * or else the one that attempt would start (see campaigns::next_start()).
     *
     * @param stdClass $instance Activity instance (id, maxlevels, maxattempts).
     * @param int $userid User ID.
     * @return stdClass ->number (the Campaign) and ->startsnew (whether it begins after an earlier one ended).
     */
    private static function campaign_to_play(stdClass $instance, int $userid): stdClass {
        global $DB;

        $inprogress = $DB->get_records(
            'playerpuzzle_attempts',
            ['playerpuzzleid' => $instance->id, 'userid' => $userid, 'status' => 'inprogress', 'isdemo' => 0],
            'timecreated DESC, id DESC',
            'id, campaign',
            0,
            1
        );
        $attempt = reset($inprogress);
        if ($attempt) {
            return (object) ['number' => (int) $attempt->campaign, 'startsnew' => false];
        }

        $start = self::next_campaign_start($instance, $userid);

        return (object) ['number' => $start->campaign, 'startsnew' => $start->startsnew];
    }

    /**
     * Where the student's next real attempt starts, see campaigns::next_start().
     *
     * @param stdClass $instance Activity instance (id, maxlevels, maxattempts).
     * @param int $userid User ID.
     * @return stdClass
     */
    public static function next_campaign_start(stdClass $instance, int $userid): stdClass {
        return campaigns::next_start(
            (int) $instance->id,
            $userid,
            max(1, (int) $instance->maxlevels),
            (int) $instance->maxattempts
        );
    }

    /**
     * Refuses a match that cannot be won: when the activity requires answering a minimum of questions
     * before a victory counts and has no approved question to draw, the boss is revived every time its HP
     * reaches zero and the server refuses the victory, so the student could only lose, spending an attempt
     * and the retry cost. A zero minimum needs no question and is never refused.
     *
     * @param stdClass $instance Activity instance.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the activity has a minimum of questions but none to draw.
     */
    public static function check_has_questions(stdClass $instance, moodle_url $returnurl): void {
        if (self::lacks_questions($instance)) {
            throw new moodle_exception('noapprovedquestions', 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Whether the activity requires answering questions but has none approved to draw.
     *
     * @param stdClass $instance Activity instance.
     * @return bool
     */
    public static function lacks_questions(stdClass $instance): bool {
        return (int) $instance->minquestions > 0 && !questions_repository::has_approved_question((int) $instance->id);
    }

    /**
     * Runs every gate a real (non-Demo) attempt must pass before play.php creates it.
     *
     * The order is part of the contract: check_retry_cost() actually debits the PlayerHUD
     * item, so it has to be the last gate. Any gate placed after it that throws would leave
     * the student charged for an attempt that never starts.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When any of the gates rejects the new attempt.
     */
    public static function check_can_start_attempt(stdClass $instance, int $userid, moodle_url $returnurl): void {
        self::check_has_questions($instance, $returnurl);
        self::check_attempt_limit($instance, $userid, $returnurl);
        self::check_cooldown($instance, $userid, $returnurl);
        self::check_retry_cost($instance, $userid, $returnurl);
    }

    /**
     * Charges the configured retry-cost item, from the 2nd attempt onwards, before a new
     * attempt is created — never for the very first attempt, and never for a play.php POST
     * that will only resume an already in-progress one (a plain reload, or the client's own
     * "Continue"/"Play again" form submit hitting the same phase again is not "a new try").
     *
     * Retries stay free whenever there is nothing configured to charge against: no item set,
     * or no block_playerhud instance in the course at all — this gate is meant as an optional
     * toll a teacher opts into, not a hard requirement of the activity. Only an actually-
     * configured item with insufficient balance blocks the retry.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the item is configured and available, but the student
     *  does not hold enough of it.
     */
    public static function check_retry_cost(stdClass $instance, int $userid, moodle_url $returnurl): void {
        global $DB;

        $itemid = (int) $instance->hud_retry_cost_item;
        if ($itemid <= 0) {
            return;
        }
        if (security::has_inprogress_attempt((int) $instance->id, $userid)) {
            return;
        }

        // Demo attempts never count as a real "finished attempt" here — a student who has
        // only ever played the Demo still gets a free first real try.
        $finishedattempts = $DB->count_records_select(
            'playerpuzzle_attempts',
            'playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0',
            ['ppid' => $instance->id, 'uid' => $userid, 'inprogress' => 'inprogress']
        );
        if ($finishedattempts === 0) {
            return;
        }

        $blockinstanceid = hud_service::get_block_instance_id((int) $instance->course);
        if ($blockinstanceid === null) {
            return;
        }

        $qty = max(1, (int) $instance->hud_retry_cost_qty);
        $consumed = hud_service::consume_item($blockinstanceid, $userid, $itemid, $qty);
        // Null means the item is gone from PlayerHUD (deleted, or the block was replaced): the cost
        // can never be paid, so it is waived. Only a genuine shortfall blocks the retry.
        if ($consumed === false) {
            throw new moodle_exception('insufficientretrycost', 'mod_playerpuzzle', $returnurl);
        }
    }

    /**
     * Enforces the configured cooldown between matches, Single Match mode only — a
     * continuous Campaign attempt has no equivalent "rodada curta" to farm, so the
     * setting is meaningless there and this is a no-op regardless of what is stored
     * (see the mod_form.php field, hidden the same way for Campaign).
     *
     * Anchored on the most recent finished (non-inprogress, non-Demo) attempt's
     * timefinished — never checked at all while an in-progress attempt exists to resume,
     * since resuming one never creates a new attempt and so never spends anything the
     * cooldown would need to protect.
     *
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @param moodle_url $returnurl URL to send the student back to on failure.
     * @return void
     * @throws moodle_exception When the cooldown since the last finished match has not
     *  elapsed yet.
     */
    public static function check_cooldown(stdClass $instance, int $userid, moodle_url $returnurl): void {
        global $DB;

        if ($instance->gamemode !== constants::GAMEMODE_SINGLE) {
            return;
        }

        $seconds = (int) $instance->cooldown_seconds;
        if ($seconds <= 0) {
            return;
        }
        if (security::has_inprogress_attempt((int) $instance->id, $userid)) {
            return;
        }

        $lastfinished = $DB->get_field_sql(
            "SELECT MAX(timefinished)
               FROM {playerpuzzle_attempts}
              WHERE playerpuzzleid = :ppid AND userid = :uid AND status <> :inprogress AND isdemo = 0",
            ['ppid' => $instance->id, 'uid' => $userid, 'inprogress' => 'inprogress']
        );
        if (empty($lastfinished)) {
            return;
        }

        $until = (int) $lastfinished + $seconds;
        if ($until > time()) {
            throw new moodle_exception('cooldownactive', 'mod_playerpuzzle', $returnurl, format_time($until - time()));
        }
    }

    /**
     * Starts a match for the Lobby's Play button: for a real attempt, runs the gates that decide whether
     * a new one may begin and then resumes or creates the attempt, all under one lock per student and
     * activity. Two requests of the same student arriving together (two tabs, a double click, a repeated
     * request) would otherwise both pass the limit, the cooldown and the retry cost before either created
     * its row, and both would create an attempt. A Demo has nothing at stake and takes no lock.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid Current user ID.
     * @param bool $ismobile Whether the request is from a mobile device.
     * @param string $difficulty Student-chosen difficulty for a fresh attempt.
     * @param bool $isdemo Whether this is a disposable Demo attempt.
     * @param moodle_url $returnurl URL to send the student back to when a gate rejects the attempt.
     * @param string $hero The hero a new Single Match or Demo is fought with, see build_game_config().
     * @param string|null $chosendifficulty The difficulty picked in the Lobby for a resumed attempt, see build_game_config().
     * @return array JS game config for game_boot.js, see build_game_config().
     * @throws moodle_exception When a gate rejects the attempt, or another start of the same student
     *  does not finish in time.
     */
    public static function start_game(
        stdClass $cm,
        stdClass $instance,
        context_module $context,
        int $userid,
        bool $ismobile,
        string $difficulty,
        bool $isdemo,
        moodle_url $returnurl,
        string $hero = '',
        ?string $chosendifficulty = null
    ): array {
        if ($isdemo) {
            self::check_has_questions($instance, $returnurl);
            return self::build_game_config($cm, $instance, $context, $userid, $ismobile, $difficulty, true, $hero);
        }

        $config = security::with_locked_attempt_start(
            $userid,
            (int) $instance->id,
            function () use (
                $cm,
                $instance,
                $context,
                $userid,
                $ismobile,
                $difficulty,
                $returnurl,
                $hero,
                $chosendifficulty
            ): array {
                self::check_can_start_attempt($instance, $userid, $returnurl);
                return self::build_game_config(
                    $cm,
                    $instance,
                    $context,
                    $userid,
                    $ismobile,
                    $difficulty,
                    false,
                    $hero,
                    $chosendifficulty
                );
            }
        );
        if ($config === false) {
            throw new moodle_exception('startbusy', 'mod_playerpuzzle', $returnurl);
        }

        return $config;
    }

    /**
     * Resumes or creates the attempt, and assembles the full JS game config: the scaled
     * boss/student HP and combat damage for the attempt's current level/phase (Single Match
     * always resolves to the base values unchanged, since its attempts stay at Level 1,
     * Phase 1), the anti-replay token, and sprites. Questions are no longer bundled here —
     * the client draws one at a time from mod_playerpuzzle_draw_question, server-picked, so
     * it never learns which question the next challenge will be ahead of time.
     *
     * @param stdClass $cm Course module.
     * @param stdClass $instance Activity instance.
     * @param context_module $context Module context.
     * @param int $userid Current user ID.
     * @param bool $ismobile Whether the request is from a mobile device.
     * @param string $difficulty Student-chosen difficulty for a fresh attempt; not applied
     *  when an in-progress attempt is resumed (that attempt keeps its current phase's own
     *  difficulty, which advance_phase changes between phases). Ignored entirely for a Demo
     *  attempt, which always fights at a fixed HP regardless of difficulty.
     * @param bool $isdemo Whether this is a disposable Demo attempt (Lobby's "Play Demo"
     *  button) — fixed HP on both sides, always Level 1/Phase 1, never counted for grade,
     *  coins, completion, or the attempt limit.
     * @param string $hero The hero a new Single Match or Demo is fought with (a key of heroes::CATALOG);
     *  a Campaign fights with the student's own, and a resumed attempt with the one it started with.
     * @param string|null $chosendifficulty The difficulty the student picked in the Lobby for a resumed attempt whose
     *  phase has not started (see security::phase_not_started()); null when the Lobby offered no choice.
     * @return array JS game config for game_boot.js.
     */
    public static function build_game_config(
        stdClass $cm,
        stdClass $instance,
        context_module $context,
        int $userid,
        bool $ismobile,
        string $difficulty = 'normal',
        bool $isdemo = false,
        string $hero = '',
        ?string $chosendifficulty = null
    ): array {
        global $OUTPUT;

        $attemptinfo = security::resume_or_create_attempt_token(
            (int) $instance->id,
            $userid,
            $difficulty,
            (int) $instance->maxlevels,
            $isdemo,
            $hero,
            $chosendifficulty
        );
        $difficulty = $attemptinfo->difficulty;

        if ($attemptinfo->isnew) {
            $event = \mod_playerpuzzle\event\game_started::create([
                'objectid' => $attemptinfo->attemptid,
                'context'  => $context,
                'other'    => ['gamemode' => $instance->gamemode, 'difficulty' => $difficulty],
            ]);
            $event->trigger();
        }

        // The opponent's HP, the phase's base damage and the profile's attack and armor come from
        // the same calculation the server replay uses (combat::phase_opponent()), from the config
        // frozen when the phase started, so a teacher editing the activity mid-phase never changes
        // what a resumed client fights. A Demo fights at a fixed HP with no profile. Student HP is
        // never touched by difficulty, and a Demo uses the fixed one too.
        $opponentside = combat::phase_opponent($attemptinfo, $instance);
        $bosshp = $opponentside['maxbosshp'];
        $bossdamage = $opponentside['basedamage'];
        $playerdamage = $opponentside['playerbasedamage'];
        if ($isdemo) {
            $studenthp = combat::DEMO_HP;
            $student = ['attack' => 1.0, 'armor' => 0.0];
            $coingain = (int) $instance->coingain;
        } else {
            $student = combat::phase_student($attemptinfo, $instance);
            $studenthp = $student['maxhp'];
            $coingain = $attemptinfo->frozencoingain;
        }

        // A resumed real phase is rebuilt by the server's own replay rather than taken from
        // the client's last checkpoint (see replay::snapshot()). A Demo keeps the checkpoint:
        // it fights at a fixed HP the replay does not model, and has nothing at stake.
        $snapshot = (!$attemptinfo->isnew && !$isdemo)
            ? self::rebuild_resume_point($attemptinfo, $instance, $userid)
            : null;

        $consumableuses = attempt_consumables::get_uses_by_type($attemptinfo->attemptid);

        // Units of each type currently owned (bought pre-match in the Lobby) — lets the
        // in-combat badges show and gate on real ownership from the moment the page loads,
        // without needing a failed use_stock call first. A single bulk read for all five
        // types, same shape as get_uses_by_type() just above.
        $consumablestock = user_stock::get_all($userid, (int) $instance->id);

        $spriteurls = [];
        for ($i = 0; $i < 7; $i++) {
            $spriteurls[] = $OUTPUT->image_url('sprites/item' . $i, 'mod_playerpuzzle')->out(false);
        }

        // The opponent was fixed when the phase started; a row from before opponents were frozen
        // falls back to the one the phase would get today.
        $opponent = $attemptinfo->frozenopponent !== ''
            ? $attemptinfo->frozenopponent
            : opponents::for_phase($instance, $attemptinfo->currentlevel, $attemptinfo->currentphase);
        $bossurl = opponents::sprite_url($opponent)->out(false);
        // The hero the phase was started with; a row from before heroes existed gets the first hero of the catalog.
        $playerurl = heroes::sprite_url(
            $attemptinfo->frozenhero !== '' ? $attemptinfo->frozenhero : heroes::sprite(array_key_first(heroes::CATALOG), 1)
        )->out(false);

        // A phase no move has been played in yet: just started, advanced to, or started again
        // (after a defeat, or on a newer engine). Its opponent's powers are listed in the
        // opponent's history, and explained in a balloon only the first time the student meets
        // that opponent, in any activity. A Demo never counts as meeting it.
        // The opening question, which decides who moves first, is asked before any move: a phase waiting on it
        // is still a fresh one.
        $opening = $snapshot !== null && $snapshot['pendingquestion'] === replay::OPENING;
        $freshphase = $snapshot !== null
            ? $snapshot['eventcount'] === 0
                && ($snapshot['pendingquestion'] === null || $opening)
                && !$snapshot['terminal']
            : empty($attemptinfo->combatstate);
        // A real phase opens with that question: one just created, or one rebuilt that is still waiting on it. A
        // Demo has none, and a phase whose log could not be rebuilt cannot be told to ask it again.
        $openingquestion = !$isdemo && ($snapshot === null ? $attemptinfo->isnew : $opening);
        $powernotice = $freshphase && !$isdemo && seen_opponents::first_meeting($opponent, $userid);
        $stagebgurl = opponents::stage_url($opponent)->out(false);

        return [
            'cmid'                 => $cm->id,
            'token'                => $attemptinfo->token,
            // A Demo is always reported as 'single' to the client regardless of the
            // instance's own configured gamemode: it is a one-shot fight (no advance_phase),
            // exactly the flow combat.js already has for Single Match — see
            // combat.js::hasNextPhase()'s gamemode gate.
            'gamemode'             => $isdemo ? constants::GAMEMODE_SINGLE : $instance->gamemode,
            'difficulty'           => $difficulty,
            // The client never counts its own answered questions for the "Perguntas: X/N" HUD
            // counter or the boss-revive rule — it only ever mirrors this server-reported total,
            // updated on every validate_answer call. A Demo has no minimum: it is a practice
            // fight with nothing at stake, so its opponent never revives.
            'minquestions'         => $isdemo ? 0 : (int) $instance->minquestions,
            'questionstotal'       => $attemptinfo->questionstotal,
            // Coin ledger counts already on the attempt (0 for a fresh one, whatever the
            // current phase/match carries for a resumed one) — lets the coin display render
            // its correct state on load.
            'coinsearnedsofar'     => $attemptinfo->coinsearned,
            'bosscoinsearnedsofar' => $attemptinfo->bosscoinsearned,
            // Where a resumed phase stands, rebuilt by the server (see replay::snapshot()):
            // board, PRNG position, HP/meters/gold, whose turn, a question still open, or a
            // match already over. When present, the client loads it instead of combatstate.
            'snapshot'             => $snapshot === null ? null : [
                'grid'            => $snapshot['grid'],
                'rngstate'        => $snapshot['rngstate'],
                'turn'            => $snapshot['turn'],
                'pendingquestion' => $snapshot['pendingquestion'],
                'terminal'        => $snapshot['terminal'],
                'state'           => $snapshot['state'],
            ],
            // The board/HP/meters/turn the client's last checkpoint declared (null for a phase
            // that never got one, or that was just started/advanced) — only used to resume
            // when there is no server-rebuilt snapshot above (a Demo, or a log that does not
            // replay consistently).
            'combatstate'          => $snapshot === null ? $attemptinfo->combatstate : null,
            'freshphase'           => $freshphase,
            'openingquestion'      => $openingquestion,
            'powernotice'          => $powernotice,
            'consumableuses'       => $consumableuses,
            'consumablestock'      => $consumablestock,
            // Coin multiplier the client applies to its own gold display so the end screen
            // matches what the server will actually bank (Easy 0.5x, Hard 3x). The client
            // still sends the raw, unmultiplied gold; the server re-applies this factor.
            'coinfactor'           => combat::difficulty_coin_factor($difficulty),
            'currentlevel'         => $attemptinfo->currentlevel,
            'currentphase'         => $attemptinfo->currentphase,
            'maxlevels'            => (int) $instance->maxlevels,
            'bosshp'               => $bosshp,
            'studenthp'            => $studenthp,
            // The multiplier on the hits the student lands and the share of each hit they take that
            // their armor absorbs, fixed when the phase started like the HP above.
            'playerattack'         => $student['attack'],
            'playerarmor'          => $student['armor'],
            'bossdamage'           => $bossdamage,
            // The unit the student's own hits, Potions and poison are counted in: the opponent's, except on Easy.
            'playerdamage'         => $playerdamage,
            // The opponent profile's multiplier on the hits it lands and the share of each hit it
            // takes that its armor absorbs (1 and 0 without a profile). The client only uses them
            // to play the fight; the server replay reads its own from the frozen opponent.
            'bossattack'           => $opponentside['bossattack'],
            'bossarmor'            => $opponentside['bossarmor'],
            // The opponent's powers (none in a Demo), for the client's own rules and the notice
            // that explains each one when the phase starts.
            'bosspowers'           => $opponentside['bosspowers'],
            // The Potions and Swords the opponent starts the phase with (none in a Demo).
            'bossbreathevery'      => $opponentside['bossbreathevery'],
            'bosskit'              => $opponentside['bosskit'],
            // Deliberately not scaled by level/phase, unlike bossdamage above: a teacher's
            // fixed-price consumable shop would otherwise get proportionally cheaper as a
            // campaign progresses.
            'coingain'             => $coingain,
            'bossavatar'           => $opponent,
            // The name the opponent's HP bar carries, in place of a generic "Opponent".
            'opponentname'         => get_string('bossavatar_' . $opponent, 'mod_playerpuzzle'),
            'bossurl'              => $bossurl,
            'playerurl'            => $playerurl,
            'stagebgurl'           => $stagebgurl,
            'spriteurls'           => $spriteurls,
            'mobile'               => $ismobile,
            'viewurl'              => (new moodle_url('/mod/playerpuzzle/view.php', ['id' => $cm->id]))->out(false),
            // Seeds the client's deterministic PRNG (engine/prng.js), replacing Math.random
            // for board generation/gravity/shuffle — reset every new attempt/phase (see
            // security::seed_replay_state()), so a future server-side replay of this phase's
            // recorded moves reaches the same board states the client did.
            'rngseed'              => $attemptinfo->rngseed,
            // Events already stored for this phase — the offset the client's next batch starts at.
            'moveseq'              => $snapshot === null ? $attemptinfo->moveseq : $snapshot['eventcount'],
            'enablespeech'         => sound_preferences::is_enabled('speech', $userid),
            'musicenabled'         => sound_preferences::is_enabled('music', $userid),
            'sfxenabled'           => sound_preferences::is_enabled('sfx', $userid),
            'isdemo'               => $attemptinfo->isdemo,
        ];
    }

    /**
     * What the dialog that confirms giving up a match says. Giving up ends the attempt as a defeat, with the
     * attempt spent and the phase's coins lost; a Demo grants and costs nothing, so it says only that it ends.
     *
     * @param bool $isdemo Whether the match is a Demo.
     * @return array Template data for mod_playerpuzzle/giveup_dialog.
     */
    public static function giveup_dialog(bool $isdemo): array {
        return [
            'title' => get_string('giveup_title', 'mod_playerpuzzle'),
            'text' => get_string($isdemo ? 'giveup_demo' : 'giveup_text', 'mod_playerpuzzle'),
            'confirmlabel' => get_string('giveup_confirm', 'mod_playerpuzzle'),
            'cancellabel' => get_string('giveup_cancel', 'mod_playerpuzzle'),
        ];
    }

    /**
     * Rebuilds a resumed attempt's current phase through the server-side replay, storing any
     * event the replay had to add (a question judged or a consumable counted before the
     * reload, but never logged) — under the attempt's lock, like every other writer of its
     * event log.
     *
     * @param stdClass $attemptinfo The resumed attempt, as returned by
     *  security::resume_or_create_attempt_token().
     * @param stdClass $instance Activity instance.
     * @param int $userid Current user ID.
     * @return array|null The snapshot (see replay::snapshot()), or null when the phase cannot
     *  be rebuilt from its log.
     */
    private static function rebuild_resume_point(stdClass $attemptinfo, stdClass $instance, int $userid): ?array {
        global $DB;

        $result = security::with_locked_attempt(
            $attemptinfo->token,
            (int) $instance->id,
            $userid,
            function (stdClass $attempt) use ($DB, $instance): ?array {
                $snapshot = replay::snapshot($attempt, $instance);
                if ($snapshot === null || $snapshot['appendedevents'] === []) {
                    return $snapshot;
                }

                $stored = count(move_log::decode($attempt->movelog));
                if (!move_log::merge_into_attempt($attempt, $stored, $snapshot['appendedevents'])) {
                    return null;
                }
                $DB->update_record('playerpuzzle_attempts', (object) [
                    'id' => $attempt->id,
                    'movelog' => $attempt->movelog,
                    'moveseq' => $attempt->moveseq,
                ]);

                return $snapshot;
            }
        );

        return $result === false ? null : $result;
    }
}
