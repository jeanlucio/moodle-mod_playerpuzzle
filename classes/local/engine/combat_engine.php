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

    /** @var string[] Every field of the combat state — mirrors combat_rules.js's STATE_FIELDS. */
    public const STATE_FIELDS = [
        'currentPlayerHp', 'maxPlayerHp', 'currentHp', 'maxBossHp',
        'playerMultiplier', 'bossMultiplier',
        'playerShieldMeter', 'playerShieldReady', 'bossShieldMeter', 'bossShieldReady',
        'playerPoisonMeter', 'playerPoisonRounds', 'bossPoisonMeter', 'bossPoisonRounds',
        'playerMana', 'bossMana', 'playerGold', 'bossGold',
    ];

    /** @var int A right answer's critical hit, in multiples of the phase's base damage. */
    public const CRITICAL_MULTIPLIER = 3;

    /** @var int HP a wrong player answer always costs. */
    public const WRONG_ANSWER_DAMAGE = 30;

    /** @var float The most of a hit any armor can absorb, so a hit always does some damage. */
    public const ARMOR_CAP = 0.6;

    /** @var string Power: the opponent starts the fight with its Shield armed. */
    public const POWER_ICESHIELD = 'iceshield';

    /** @var string Power: the opponent's Grimoire poison lasts one round longer. */
    public const POWER_LONGPOISON = 'longpoison';

    /** @var int Rounds of poison a full Grimoire meter arms against the other side. */
    public const POISON_ROUNDS = 3;

    /** @var int The same, for an opponent with the longpoison power. */
    public const LONG_POISON_ROUNDS = 4;

    /**
     * The combat state a phase starts from: both sides at full HP, every meter empty,
     * multipliers at 1 and no gold yet, except what the opponent's powers change at the start.
     *
     * @param float $maxplayerhp The student's max HP for this phase.
     * @param float $maxbosshp The boss's max HP for this phase.
     * @param array $config Key bossPowers, optional.
     * @return array A state keyed by exactly self::STATE_FIELDS.
     */
    public static function initial_state(float $maxplayerhp, float $maxbosshp, array $config = []): array {
        $state = [
            'currentPlayerHp' => $maxplayerhp, 'maxPlayerHp' => $maxplayerhp,
            'currentHp' => $maxbosshp, 'maxBossHp' => $maxbosshp,
            'playerMultiplier' => 1.0, 'bossMultiplier' => 1.0,
            'playerShieldMeter' => 0, 'playerShieldReady' => false,
            'bossShieldMeter' => 0, 'bossShieldReady' => false,
            'playerPoisonMeter' => 0, 'playerPoisonRounds' => 0,
            'bossPoisonMeter' => 0, 'bossPoisonRounds' => 0,
            'playerMana' => 0, 'bossMana' => 0,
            'playerGold' => 0.0, 'bossGold' => 0.0,
        ];
        if (self::has_power($config, self::POWER_ICESHIELD)) {
            $state['bossShieldReady'] = true;
        }
        return $state;
    }

    /**
     * Whether the opponent has a power this phase.
     *
     * @param array $config The phase's rule config; key bossPowers, optional.
     * @param string $power A POWER_* value.
     * @return bool
     */
    public static function has_power(array $config, string $power): bool {
        return in_array($power, $config['bossPowers'] ?? [], true);
    }

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
     * Resolves both poison meters: filling one arms POISON_ROUNDS damage rounds against the other
     * side (LONG_POISON_ROUNDS for an opponent with longpoison) and resets subtracting 100,
     * preserving any overshoot for the next fill.
     *
     * @param array $state Keys playerPoisonMeter/bossPoisonMeter/bossPoisonRounds/
     *  playerPoisonRounds (mutated in place).
     * @param array $config The phase's rule config; key bossPowers, optional.
     * @return array The same state array, for chaining.
     */
    public static function resolve_poison_meters(array &$state, array $config = []): array {
        if ($state['playerPoisonMeter'] >= 100) {
            $state['playerPoisonMeter'] -= 100;
            $state['bossPoisonRounds'] += self::POISON_ROUNDS;
        }
        if ($state['bossPoisonMeter'] >= 100) {
            $state['bossPoisonMeter'] -= 100;
            $state['playerPoisonRounds'] += self::has_power($config, self::POWER_LONGPOISON)
                ? self::LONG_POISON_ROUNDS
                : self::POISON_ROUNDS;
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
     * @param float $hp Current HP of the side being hit — a float, not an int: repeated
     *  fractional damage/heal amounts (a Potion's baseDamage / 4, a crit's baseDamage * 3 *
     *  a multiplier ending in a tenth) accumulate across a whole phase without ever being
     *  rounded back to a whole number mid-match, exactly like the JS side's untyped number.
     * @param bool $shieldready Whether that side's shield is currently armed.
     * @param float $amount Damage amount before the shield check.
     * @return array ['newHp' => float, 'shieldConsumed' => bool, 'appliedAmount' => float]
     */
    public static function resolve_damage(float $hp, bool $shieldready, float $amount): array {
        $appliedamount = $amount;
        $shieldconsumed = false;
        if ($shieldready) {
            $shieldconsumed = true;
            $appliedamount = 0.0;
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
     * @param float $hp Current HP of the side ticking — see resolve_damage() for why this is
     *  a float, not an int.
     * @param int $poisonrounds Rounds of poison damage still armed for that side.
     * @param float $tickdamage Damage dealt by a single tick (baseDamage).
     * @return array ['newHp' => float, 'newPoisonRounds' => int, 'ticked' => bool]
     */
    public static function resolve_poison_tick(float $hp, int $poisonrounds, float $tickdamage): array {
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
        self::resolve_poison_meters($next, $config);
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
     * The hit a match's Sword damage lands as: scaled by the player's own multiplier (never the
     * boss's), rounded halves up exactly like the client's Math.round(), and last the phase's
     * attack and armor apply (see hit_amount()).
     *
     * @param string $currentturn 'player' or 'boss' — whose match dealt it.
     * @param float $damagedealt resolve_match_effects()'s damageDealt.
     * @param array $state Combat state after resolve_match_effects().
     * @param array $config The phase's attack and armor (see hit_amount()).
     * @return float The hit to apply to the other side.
     */
    public static function match_hit_amount(string $currentturn, float $damagedealt, array $state, array $config): float {
        $multiplied = floor($damagedealt * ($currentturn === 'player' ? $state['playerMultiplier'] : 1) + 0.5);
        return self::hit_amount($multiplied, $currentturn, $config);
    }

    /**
     * A hit as it lands after the attacker's attack and the defender's armor: the attack
     * multiplies it, the armor takes away its share (never more than ARMOR_CAP), and the result
     * is rounded halves up. Only hits go through here (Swords from the board, critical answers,
     * the Sword consumable); the wrong-answer penalty and poison do not. A hit with no attack or
     * armor to apply keeps its exact value. The shield is checked afterwards, by resolve_damage().
     *
     * @param float $amount The hit before attack and armor.
     * @param string $attacker 'player' or 'boss' — who lands the hit.
     * @param array $config Keys playerAttack, bossAttack, playerArmor, bossArmor, each optional:
     *  a missing attack is 1, a missing armor 0.
     * @return float
     */
    public static function hit_amount(float $amount, string $attacker, array $config): float {
        $defender = $attacker === 'player' ? 'boss' : 'player';
        $attack = (float) (($config[$attacker . 'Attack'] ?? 0) ?: 1);
        $armor = min(self::ARMOR_CAP, (float) ($config[$defender . 'Armor'] ?? 0));
        if ($attack === 1.0 && $armor === 0.0) {
            return $amount;
        }
        return floor(($amount * $attack * (1 - $armor)) + 0.5);
    }

    /**
     * What a judged question does: a right answer is a critical hit on the other side; a wrong
     * one resets the answering side's multiplier, and costs the player WRONG_ANSWER_DAMAGE too.
     * Returns the amounts only — applying a hit goes through resolve_damage(). A side that is not
     * hit gets null, not 0: a hit of 0 would still spend a ready shield.
     *
     * @param string $side 'player' or 'boss' — who answered.
     * @param bool $correct Whether the answer was right.
     * @param array $state Current combat state.
     * @param array $config Key baseDamage, plus the phase's attack and armor (see hit_amount()).
     * @return array ['hitBoss' => float|null, 'hitPlayer' => float|null, 'resetPlayerMultiplier' => bool,
     *  'resetBossMultiplier' => bool]
     */
    public static function question_outcome(string $side, bool $correct, array $state, array $config): array {
        $outcome = ['hitBoss' => null, 'hitPlayer' => null, 'resetPlayerMultiplier' => false, 'resetBossMultiplier' => false];
        if ($side === 'player') {
            if ($correct) {
                $outcome['hitBoss'] = self::hit_amount(
                    $config['baseDamage'] * self::CRITICAL_MULTIPLIER * $state['playerMultiplier'],
                    'player',
                    $config
                );
            } else {
                $outcome['hitPlayer'] = (float) self::WRONG_ANSWER_DAMAGE;
                $outcome['resetPlayerMultiplier'] = true;
            }
        } else if ($correct) {
            $outcome['hitPlayer'] = self::hit_amount(
                $config['baseDamage'] * self::CRITICAL_MULTIPLIER * $state['bossMultiplier'],
                'boss',
                $config
            );
        } else {
            $outcome['resetBossMultiplier'] = true;
        }
        return $outcome;
    }

    /**
     * Everything that happens to a side as its turn begins, before it moves. Today that is only
     * its pending poison tick (see resolve_poison_tick()).
     *
     * @param string $side 'player' or 'boss' — whose turn begins.
     * @param array $state Current combat state — copied, never mutated.
     * @param array $config Keys baseDamage.
     * @return array ['state' => array, 'poisonTicked' => bool]
     */
    public static function start_turn(string $side, array $state, array $config): array {
        $next = $state;
        $hpfield = $side === 'player' ? 'currentPlayerHp' : 'currentHp';
        $roundsfield = $side === 'player' ? 'playerPoisonRounds' : 'bossPoisonRounds';
        $tick = self::resolve_poison_tick($next[$hpfield], $next[$roundsfield], (float) $config['baseDamage']);
        $next[$hpfield] = $tick['newHp'];
        $next[$roundsfield] = $tick['newPoisonRounds'];
        return ['state' => $next, 'poisonTicked' => $tick['ticked']];
    }

    /**
     * Resolves a used consumable's effect on combat state. Sword deliberately returns only the
     * damage amount rather than applying it: applying damage always goes through
     * resolve_damage() (the shield-check/clamp logic).
     *
     * @param string $type One of 'potion', 'shield', 'magic', 'sword'.
     * @param array $state Current combat state — copied, never mutated.
     * @param array $config Key baseDamage, plus the phase's attack and armor (see hit_amount()).
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
            self::resolve_poison_meters($next, $config);
        } else if ($type === 'sword') {
            $damageamount = self::hit_amount((float) $config['baseDamage'], 'player', $config);
        }

        return ['state' => $next, 'healAmount' => $healamount, 'damageAmount' => $damageamount];
    }
}
