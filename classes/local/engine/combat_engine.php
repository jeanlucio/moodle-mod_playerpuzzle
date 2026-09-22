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
 * Pure combat rules, ported from amd/src/engine/combat_rules.js for the server-side replay
 * engine.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Bit-for-bit mirror of amd/src/engine/combat_rules.js. Every function takes plain data
 * (numbers, plain state arrays) and returns plain data — no rendering, no logging, no AJAX.
 * None of these functions consume rng: combat outcomes depend only on which pieces matched
 * and the frozen instance config, so — unlike board_engine — a faithful port needs no
 * draw-order discipline, only arithmetic parity.
 *
 * JavaScript's Math.round() and PHP's round() disagree on ties: JS always rounds a .5 toward
 * +Infinity (floor(x + 0.5) by spec), while PHP's round() rounds a .5 away from zero — the two
 * only actually diverge for negative values, which never occur here (multipliers only ever
 * grow from a positive baseline), but self::round_half_up() is used instead of the native
 * round() anyway so this file never depends on that native behaviour matching JS by
 * coincidence.
 */
class combat_engine {
    /** @var array<string, int> Mirrors attempt_consumables::PHASE_LIMITS. */
    public const PHASE_LIMITS = ['shield' => 1, 'magic' => 1, 'potion' => 3, 'sword' => 3];

    /**
     * Combo-size multiplier for pieces whose effect scales with match size (Sword/Coin).
     *
     * @param int $size Number of pieces in the matched run.
     * @return float The combo multiplier.
     */
    public static function combo_multiplier(int $size): float {
        return 1 + (0.5 * max(0, $size - 3));
    }

    /**
     * Rounds a value the same way JavaScript's Math.round() does: a tie always rounds toward
     * +Infinity, never away from zero.
     *
     * @param float $value Value to round.
     * @param int $precision Number of decimal places to keep.
     * @return float The rounded value.
     */
    private static function round_half_up(float $value, int $precision): float {
        $factor = 10 ** $precision;
        return floor(($value * $factor) + 0.5) / $factor;
    }

    /**
     * Resolves both poison meters: filling one arms 3 damage rounds against the opponent and
     * resets subtracting 100, preserving any overshoot for the next fill.
     *
     * @param array $state Keys playerPoisonMeter/bossPoisonMeter/bossPoisonRounds/
     *  playerPoisonRounds (mutated in place).
     * @return array The same state array, for chaining.
     */
    public static function resolve_poison_meters(array &$state): array {
        if ($state['playerPoisonMeter'] >= 100) {
            $state['playerPoisonMeter'] -= 100;
            $state['bossPoisonRounds'] += 3;
        }
        if ($state['bossPoisonMeter'] >= 100) {
            $state['bossPoisonMeter'] -= 100;
            $state['playerPoisonRounds'] += 3;
        }
        return $state;
    }

    /**
     * Resolves both shield meters: filling one arms a single full block of the next hit
     * received by that same side and resets to 0 flat.
     *
     * @param array $state Keys playerShieldMeter/playerShieldReady/bossShieldMeter/
     *  bossShieldReady (mutated in place).
     * @return array The same state array, for chaining.
     */
    public static function resolve_shield_meters(array &$state): array {
        if ($state['playerShieldMeter'] >= 100) {
            $state['playerShieldMeter'] = 0;
            $state['playerShieldReady'] = true;
        }
        if ($state['bossShieldMeter'] >= 100) {
            $state['bossShieldMeter'] = 0;
            $state['bossShieldReady'] = true;
        }
        return $state;
    }

    /**
     * Fixed maximum uses per phase/match for a consumable type. A type absent from
     * PHASE_LIMITS (only 'hint') has no fixed limit, so this returns INF.
     *
     * @param string $type Consumable type.
     * @return float
     */
    public static function phase_limit(string $type): float {
        return array_key_exists($type, self::PHASE_LIMITS) ? (float) self::PHASE_LIMITS[$type] : INF;
    }

    /**
     * Whether the boss reaching 0 HP right now should revive it instead of ending the match.
     *
     * @param int $minquestions The instance's configured minimum, 0 = no minimum.
     * @param int $questionstotal Questions answered so far this attempt.
     * @return bool True when the boss should revive instead of the match ending.
     */
    public static function needs_revive(int $minquestions, int $questionstotal): bool {
        return $minquestions > 0 && $questionstotal < $minquestions;
    }

    /**
     * The HP the boss comes back at when revived: 50% of this match's own max HP.
     *
     * @param int $maxbosshp This match's max boss HP.
     * @return int
     */
    public static function revive_hp(int $maxbosshp): int {
        return (int) ceil($maxbosshp * 0.5);
    }

    /**
     * Whether this victory is a mid-Campaign phase win rather than the end of the whole
     * attempt.
     *
     * @param array $gameconfig Keys gamemode/currentphase/currentlevel/maxlevels.
     * @return bool True when a next phase or level exists to advance to.
     */
    public static function has_next_phase(array $gameconfig): bool {
        if (($gameconfig['gamemode'] ?? null) !== 'campaign') {
            return false;
        }
        $phase = (int) ($gameconfig['currentphase'] ?? 1) ?: 1;
        $level = (int) ($gameconfig['currentlevel'] ?? 1) ?: 1;
        $maxlevels = (int) ($gameconfig['maxlevels'] ?? 1) ?: 1;
        return $phase < 10 || $level < $maxlevels;
    }

    /**
     * Resolves one side taking damage: a ready shield blocks the hit entirely instead of
     * reducing HP.
     *
     * @param int $hp Current HP of the side being hit.
     * @param bool $shieldready Whether that side's shield is currently armed.
     * @param float $amount Damage amount before the shield check.
     * @return array ['newHp' => float, 'shieldConsumed' => bool, 'appliedAmount' => float]
     */
    public static function resolve_damage(int $hp, bool $shieldready, float $amount): array {
        $appliedamount = $amount;
        $shieldconsumed = false;
        if ($shieldready) {
            $shieldconsumed = true;
            $appliedamount = 0;
        }
        return [
            'newHp' => max(0, $hp - $appliedamount),
            'shieldConsumed' => $shieldconsumed,
            'appliedAmount' => $appliedamount,
        ];
    }

    /**
     * Resolves one side's poison tick at the start of their turn.
     *
     * @param int $hp Current HP of the side ticking.
     * @param int $poisonrounds Rounds of poison damage still armed for that side.
     * @param float $tickdamage Damage dealt by a single tick (baseDamage).
     * @return array ['newHp' => float, 'newPoisonRounds' => int, 'ticked' => bool]
     */
    public static function resolve_poison_tick(int $hp, int $poisonrounds, float $tickdamage): array {
        if ($poisonrounds <= 0) {
            return ['newHp' => $hp, 'newPoisonRounds' => $poisonrounds, 'ticked' => false];
        }
        return [
            'newHp' => max(0, $hp - $tickdamage),
            'newPoisonRounds' => $poisonrounds - 1,
            'ticked' => true,
        ];
    }

    /**
     * Resolves everything a single match (one turn's destroyed pieces + match groups) changes
     * about combat state.
     *
     * @param string $currentturn 'player' or 'boss' — whose match this is.
     * @param array $destroyedtypes Piece types destroyed this turn (0=Star, 1=Grimoire, 2=Orb,
     *  3=Sword, 4=Shield, 5=Potion, 6=Coin).
     * @param array $matchgroups Match groups this turn, each ['type' => int, 'size' => int].
     * @param array $state Current combat state — copied, never mutated.
     * @param array $config Keys baseDamage/coinGain/coinFactor.
     * @return array ['state', 'damageDealt', 'coinsGained', 'healGained', 'multiplierGained',
     *  'shieldGained', 'poisonGained', 'manaGained', 'questionTriggered', 'triggeredBy']
     */
    public static function resolve_match_effects(
        string $currentturn,
        array $destroyedtypes,
        array $matchgroups,
        array $state,
        array $config
    ): array {
        $next = $state;
        $damagedealt = 0;
        $coinsgained = 0;
        $healgained = 0;
        $multipliergained = 0;
        $shieldgained = 0;
        $poisongained = 0;
        $managained = 0;

        foreach ($destroyedtypes as $type) {
            if ($currentturn === 'player') {
                if ($type === 5) {
                    $heal = $config['baseDamage'] / 4;
                    $next['currentPlayerHp'] = min($next['maxPlayerHp'], $next['currentPlayerHp'] + $heal);
                    $healgained += $heal;
                } else if ($type === 0) {
                    $next['playerMultiplier'] += 0.1;
                    $multipliergained += 0.1;
                } else if ($type === 4) {
                    $next['playerShieldMeter'] += 10;
                    $shieldgained += 10;
                } else if ($type === 1) {
                    $next['playerPoisonMeter'] += 10;
                    $poisongained += 10;
                } else if ($type === 2) {
                    $next['playerMana'] += 20;
                    $managained += 20;
                }
            } else {
                if ($type === 2) {
                    $next['bossMana'] += 20;
                    $managained += 20;
                } else if ($type === 0) {
                    $next['bossMultiplier'] += 0.1;
                    $multipliergained += 0.1;
                } else if ($type === 1) {
                    $next['bossPoisonMeter'] += 10;
                    $poisongained += 10;
                } else if ($type === 4) {
                    $next['bossShieldMeter'] += 10;
                    $shieldgained += 10;
                } else if ($type === 5) {
                    $heal = $config['baseDamage'] / 4;
                    $next['currentHp'] = min($next['maxBossHp'], $next['currentHp'] + $heal);
                    $healgained += $heal;
                }
            }
        }

        // Sword damage is computed per match group (not per piece) so combo size drives the
        // multiplier directly: a 3-piece group always resolves to exactly baseDamage.
        foreach ($matchgroups as $group) {
            if ($group['type'] === 3) {
                $damagedealt += $config['baseDamage'] * self::combo_multiplier($group['size']);
            }
        }

        // Coin reuses the same combo curve as Sword, over the separately configurable
        // coinGain base. The boss's own Coin total never buys anything — it exists purely to
        // net against the student's balance at payout time.
        foreach ($matchgroups as $group) {
            if ($group['type'] === 6) {
                $coins = $config['coinGain'] * self::combo_multiplier($group['size']) * $config['coinFactor'];
                $coinsgained += $coins;
                if ($currentturn === 'player') {
                    $next['playerGold'] += $coins;
                } else {
                    $next['bossGold'] += $coins;
                }
            }
        }

        $next['playerMultiplier'] = self::round_half_up($next['playerMultiplier'], 1);
        $next['bossMultiplier'] = self::round_half_up($next['bossMultiplier'], 1);
        self::resolve_poison_meters($next);
        self::resolve_shield_meters($next);

        $questiontriggered = false;
        $triggeredby = null;
        if ($next['playerMana'] >= 100) {
            $next['playerMana'] -= 100;
            $questiontriggered = true;
            $triggeredby = 'player';
        } else if ($next['bossMana'] >= 100) {
            $next['bossMana'] -= 100;
            $questiontriggered = true;
            $triggeredby = 'boss';
        }

        return [
            'state' => $next,
            'damageDealt' => $damagedealt,
            'coinsGained' => $coinsgained,
            'healGained' => $healgained,
            'multiplierGained' => $multipliergained,
            'shieldGained' => $shieldgained,
            'poisonGained' => $poisongained,
            'manaGained' => $managained,
            'questionTriggered' => $questiontriggered,
            'triggeredBy' => $triggeredby,
        ];
    }

    /**
     * Resolves a used consumable's effect on combat state. Sword deliberately returns only the
     * damage amount rather than applying it: applying damage always goes through
     * resolve_damage() (the shield-check/clamp logic).
     *
     * @param string $type One of 'potion', 'shield', 'magic', 'sword'.
     * @param array $state Current combat state — copied, never mutated.
     * @param array $config Keys baseDamage.
     * @return array ['state', 'healAmount', 'damageAmount']
     */
    public static function resolve_consumable_effect(string $type, array $state, array $config): array {
        $next = $state;
        $healamount = 0;
        $damageamount = 0;

        if ($type === 'potion') {
            $healamount = $config['baseDamage'] * 1.25;
            $next['currentPlayerHp'] = min($next['maxPlayerHp'], $next['currentPlayerHp'] + $healamount);
        } else if ($type === 'shield') {
            $next['playerShieldMeter'] += 100;
            self::resolve_shield_meters($next);
        } else if ($type === 'magic') {
            $next['playerPoisonMeter'] += 100;
            self::resolve_poison_meters($next);
        } else if ($type === 'sword') {
            $damageamount = $config['baseDamage'];
        }

        return ['state' => $next, 'healAmount' => $healamount, 'damageAmount' => $damageamount];
    }
}
