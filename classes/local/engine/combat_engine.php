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
        'bossTurnCount', 'bossPotions', 'bossSwords',
    ];

    /** @var int A right answer's critical hit, in multiples of the phase's base damage. */
    public const CRITICAL_MULTIPLIER = 3;

    /** @var int HP a wrong player answer always costs. */
    public const WRONG_ANSWER_DAMAGE = 30;

    /** @var float The most of a hit any armor can absorb, so a hit always does some damage. */
    public const ARMOR_CAP = 0.6;

    /** @var string Power: the opponent starts the fight with its Shield armed. */
    public const POWER_ICESHIELD = 'iceshield';

    /** @var string Power: the opponent's Grimoire starts partly filled and its poison lasts one round longer. */
    public const POWER_LONGPOISON = 'longpoison';

    /** @var int How much of its poison meter an opponent with the longpoison power starts with. */
    public const LONG_POISON_HEAD_START = 20;

    /** @var int Rounds of poison a full Grimoire meter arms against the other side. */
    public const POISON_ROUNDS = 3;

    /** @var int The same, for an opponent with the longpoison power. */
    public const LONG_POISON_ROUNDS = 4;

    /** @var string Power: each Grimoire the opponent matches fills more of its poison meter. */
    public const POWER_FASTGRIMOIRE = 'fastgrimoire';

    /** @var int How much of the poison meter each matched Grimoire fills. */
    public const GRIMOIRE_POISON = 10;

    /** @var int The same, for an opponent with the fastgrimoire power. */
    public const FAST_GRIMOIRE_POISON = 15;

    /** @var string Power: each Orb the opponent matches gives it more mana. */
    public const POWER_MANABOOST = 'manaboost';

    /** @var int How much mana each matched Orb gives. */
    public const ORB_MANA = 20;

    /** @var int The same, for an opponent with the manaboost power. */
    public const BOOSTED_ORB_MANA = 30;

    /** @var string Power: the Potions the opponent matches heal half as much again. */
    public const POWER_ROOTHEAL = 'rootheal';

    /** @var string Power: the opponent recovers part of its max HP as each of its turns begins. */
    public const POWER_REGEN = 'regen';

    /**
     * @var float The opponent drinks a Potion below this share of its max HP. Its starting items: none
     *  on level 1, one more per level after it, one more on Hard and one more for the final boss, none
     *  at all on Easy; Potions and Swords alternate, a Potion first, never past the student's own limit
     *  per phase.
     */
    public const ITEM_POTION_BELOW = 0.4;

    /** @var float The opponent draws a Sword when it wins the fight or the student is below this share. */
    public const ITEM_SWORD_BELOW = 0.3;

    /** @var float The opponent's Potion heals this many times the base damage, like the student's. */
    public const ITEM_POTION_HEAL = 1.25;

    /**
     * @var float[] How much the opponent values each piece type (0 Star, 1 Grimoire, 2 Orb, 3 Sword, 4 Shield,
     *  5 Potion, 6 Coin) when it picks a move on Normal and Hard. The Potion's is scaled by how hurt the
     *  opponent is, and a Shield is worth less while one is armed.
     */
    public const MOVE_WEIGHTS = [1.5, 1.5, 1.5, 3, 1.5, 3, 1];

    /** @var float The Shield's weight while the opponent has one armed. */
    public const ARMED_SHIELD_WEIGHT = 0.5;

    /** @var array On Hard, each power makes the opponent value the pieces that feed it more: power => [type => factor]. */
    public const PERSONALITY = [
        'rootheal' => [5 => 2],
        'regen' => [5 => 1.5],
        'manaboost' => [2 => 2],
        'fastgrimoire' => [1 => 2],
        'longpoison' => [1 => 2],
        'iceshield' => [4 => 2],
        'intangible' => [4 => 2],
        'ambush' => [3 => 1.5],
        'breath' => [3 => 1.5],
        'drain' => [3 => 1.5],
        'loot' => [6 => 3],
    ];

    /** @var string Power: every few of its turns, the opponent takes some of the student's coins. */
    public const POWER_LOOT = 'loot';

    /** @var int Every how many of its turns an opponent with the loot power takes the student's coins. */
    public const LOOT_EVERY = 4;

    /** @var int The percentage of the student's coins the loot takes each time. */
    public const LOOT_PERCENT = 10;

    /** @var string Power: every few of its turns, the opponent lands a strong hit before it moves. */
    public const POWER_BREATH = 'breath';

    /** @var int Every how many of its turns an opponent with the breath power lands it. */
    public const BREATH_EVERY = 10;

    /** @var float How many times the phase's base damage the breath hits for. */
    public const BREATH_MULTIPLIER = 1.5;

    /** @var string Power: the opponent's Shield arms by itself every few of its turns. */
    public const POWER_INTANGIBLE = 'intangible';

    /** @var int Every how many of its turns an opponent with the intangible power has its Shield armed. */
    public const INTANGIBLE_EVERY = 10;

    /** @var string Power: the opponent's Swords hit harder on its first turn. */
    public const POWER_AMBUSH = 'ambush';

    /** @var int How much harder an opponent with the ambush power's Swords hit on its first turn. */
    public const AMBUSH_MULTIPLIER = 2;

    /** @var string Power: the opponent recovers part of the damage its hits deal. */
    public const POWER_DRAIN = 'drain';

    /** @var int The percentage of the damage its hits deal that an opponent with the drain power recovers. */
    public const DRAIN_PERCENT = 30;

    /** @var int The percentage of its max HP an opponent with the regen power recovers per turn. */
    public const REGEN_PERCENT = 1;

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
            'bossTurnCount' => 0,
            'bossPotions' => (int) ($config['bossKit']['potion'] ?? 0),
            'bossSwords' => (int) ($config['bossKit']['sword'] ?? 0),
        ];
        if (self::has_power($config, self::POWER_ICESHIELD)) {
            $state['bossShieldReady'] = true;
        }
        if (self::has_power($config, self::POWER_LONGPOISON)) {
            $state['bossPoisonMeter'] = self::LONG_POISON_HEAD_START;
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
     * What each piece a side destroys gives that side: the HP a Potion heals, the multiplier a
     * Star adds, and how much of its meter a Shield, Grimoire or Orb fills. The opponent's powers
     * change some of them for the opponent only.
     *
     * @param string $side 'player' or 'boss'.
     * @param array $config The phase's rule config: baseDamage and bossPowers are read.
     * @return array ['heal' => float, 'multiplier' => float, 'shield' => int, 'poison' => int, 'mana' => int]
     */
    public static function piece_gains(string $side, array $config): array {
        $boss = $side === 'boss';
        return [
            // A quarter of baseDamage, or three eighths with rootheal. Kept as a division, like
            // the JS twin, so an exact result stays an integer in PHP.
            'heal' => $boss && self::has_power($config, self::POWER_ROOTHEAL)
                ? $config['baseDamage'] * 3 / 8
                : $config['baseDamage'] / 4,
            'multiplier' => 0.1,
            'shield' => 10,
            'poison' => $boss && self::has_power($config, self::POWER_FASTGRIMOIRE)
                ? self::FAST_GRIMOIRE_POISON
                : self::GRIMOIRE_POISON,
            'mana' => $boss && self::has_power($config, self::POWER_MANABOOST) ? self::BOOSTED_ORB_MANA : self::ORB_MANA,
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

        $side = $currentturn === 'player' ? 'player' : 'boss';
        $hpfield = $side === 'player' ? 'currentPlayerHp' : 'currentHp';
        $maxhpfield = $side === 'player' ? 'maxPlayerHp' : 'maxBossHp';
        $gains = self::piece_gains($side, $config);
        foreach ($destroyedtypes as $type) {
            if ($type === 5) {
                $next[$hpfield] = min($next[$maxhpfield], $next[$hpfield] + $gains['heal']);
                $healgained += $gains['heal'];
            } else if ($type === 0) {
                $next[$side . 'Multiplier'] += $gains['multiplier'];
                $multipliergained += $gains['multiplier'];
            } else if ($type === 4) {
                $next[$side . 'ShieldMeter'] += $gains['shield'];
                $shieldgained += $gains['shield'];
            } else if ($type === 1) {
                $next[$side . 'PoisonMeter'] += $gains['poison'];
                $poisongained += $gains['poison'];
            } else if ($type === 2) {
                $next[$side . 'Mana'] += $gains['mana'];
                $managained += $gains['mana'];
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
     * boss's), rounded halves up exactly like the client's Math.round(); an opponent with the
     * ambush power doubles it on its first turn; and last the phase's attack and armor apply (see
     * hit_amount()).
     *
     * @param string $currentturn 'player' or 'boss' — whose match dealt it.
     * @param float $damagedealt resolve_match_effects()'s damageDealt.
     * @param array $state Combat state after resolve_match_effects().
     * @param array $config The phase's attack and armor (see hit_amount()); bossPowers is read.
     * @return float The hit to apply to the other side.
     */
    public static function match_hit_amount(string $currentturn, float $damagedealt, array $state, array $config): float {
        $multiplied = floor($damagedealt * ($currentturn === 'player' ? $state['playerMultiplier'] : 1) + 0.5);
        if (self::is_ambush($currentturn, $state, $config)) {
            $multiplied *= self::AMBUSH_MULTIPLIER;
        }
        return self::hit_amount($multiplied, $currentturn, $config);
    }

    /**
     * How the opponent values each piece type when it picks a move (see
     * board_engine::choose_move()) — mirrors combat_rules.js::opponentMoveWeights(): none on Easy,
     * so it plays the first Sword match it finds; MOVE_WEIGHTS on Normal, with the Potion's scaled
     * by how hurt it is and less for a Shield while one is armed; on Hard, those times the
     * PERSONALITY of each of its powers. A config without a difficulty plays as Normal.
     *
     * @param array $state Combat state: currentHp, maxBossHp and bossShieldReady are read.
     * @param array $config The phase's rule config: difficulty and bossPowers are read.
     * @return array|null Weight per piece type (index 0-6), or null on Easy.
     */
    public static function opponent_move_weights(array $state, array $config): ?array {
        $difficulty = $config['difficulty'] ?? 'normal';
        if ($difficulty === 'easy') {
            return null;
        }
        $weights = self::MOVE_WEIGHTS;
        if ($state['bossShieldReady']) {
            $weights[4] = self::ARMED_SHIELD_WEIGHT;
        }
        $weights[5] = self::MOVE_WEIGHTS[5] * ($state['maxBossHp'] - $state['currentHp']) / $state['maxBossHp'];
        if ($difficulty === 'hard') {
            foreach ($config['bossPowers'] ?? [] as $power) {
                foreach (self::PERSONALITY[$power] ?? [] as $type => $factor) {
                    $weights[$type] *= $factor;
                }
            }
        }
        return $weights;
    }

    /**
     * The opponent's starting items for a phase (see ITEM_POTION_BELOW) — mirrors
     * combat_rules.js::opponentKit().
     *
     * @param int $level The phase's level.
     * @param string $difficulty 'easy', 'normal' or 'hard'.
     * @param bool $finalboss Whether the opponent is the final boss.
     * @return array ['potion' => int, 'sword' => int]
     */
    public static function opponent_kit(int $level, string $difficulty, bool $finalboss): array {
        if ($difficulty === 'easy') {
            return ['potion' => 0, 'sword' => 0];
        }
        $items = max(0, $level - 1) + ($difficulty === 'hard' ? 1 : 0) + ($finalboss ? 1 : 0);
        return [
            'potion' => min(self::PHASE_LIMITS['potion'], (int) ceil($items / 2)),
            'sword' => min(self::PHASE_LIMITS['sword'], intdiv($items, 2)),
        ];
    }

    /**
     * The item the opponent uses as its turn begins, if any — mirrors
     * combat_rules.js::opponentItemUse(): a Sword when it has one, the student has no Shield
     * armed, and the hit wins the fight or the student is below ITEM_SWORD_BELOW of their max HP;
     * otherwise a Potion when it has one and is below ITEM_POTION_BELOW of its own.
     *
     * @param array $state Combat state.
     * @param array $config The phase's rule config: baseDamage and the attack and armor (see
     *  hit_amount()) are read.
     * @return string|null 'sword', 'potion' or null.
     */
    public static function opponent_item_use(array $state, array $config): ?string {
        if ($state['bossSwords'] > 0 && !$state['playerShieldReady']) {
            $hit = self::hit_amount($config['baseDamage'], 'boss', $config);
            $low = $state['currentPlayerHp'] < $state['maxPlayerHp'] * self::ITEM_SWORD_BELOW;
            if ($hit >= $state['currentPlayerHp'] || $low) {
                return 'sword';
            }
        }
        if ($state['bossPotions'] > 0 && $state['currentHp'] < $state['maxBossHp'] * self::ITEM_POTION_BELOW) {
            return 'potion';
        }
        return null;
    }

    /**
     * Uses the opponent's item as its turn begins, if any (see opponent_item_use()), on the state
     * start_turn() is building.
     *
     * @param string $side Whose turn begins.
     * @param array $next The state start_turn() is building (mutated in place).
     * @param array $config The phase's rule config.
     * @return array|null ['kind', 'amount', 'appliedAmount', 'shieldConsumed', 'drained'], or null.
     */
    private static function use_opponent_item(string $side, array &$next, array $config): ?array {
        $kind = $side === 'boss' && $next['currentHp'] > 0 ? self::opponent_item_use($next, $config) : null;
        if ($kind === 'potion') {
            $amount = (float) min($next['maxBossHp'] - $next['currentHp'], $config['baseDamage'] * self::ITEM_POTION_HEAL);
            $next['bossPotions'] -= 1;
            $next['currentHp'] += $amount;
            return ['kind' => $kind, 'amount' => $amount, 'appliedAmount' => 0.0, 'shieldConsumed' => false, 'drained' => 0.0];
        }
        if ($kind === 'sword') {
            $amount = self::hit_amount($config['baseDamage'], 'boss', $config);
            $applied = self::resolve_damage($next['currentPlayerHp'], $next['playerShieldReady'], $amount);
            $next['bossSwords'] -= 1;
            $next['currentPlayerHp'] = $applied['newHp'];
            if ($applied['shieldConsumed']) {
                $next['playerShieldReady'] = false;
            }
            $drained = self::drain_amount($applied['appliedAmount'], $next, $config);
            $next['currentHp'] += $drained;
            return [
                'kind' => $kind,
                'amount' => $amount,
                'appliedAmount' => $applied['appliedAmount'],
                'shieldConsumed' => $applied['shieldConsumed'],
                'drained' => $drained,
            ];
        }
        return null;
    }

    /**
     * Whether a match's Swords are an ambush: the opponent's, on its first turn, with the ambush
     * power.
     *
     * @param string $currentturn 'player' or 'boss' — whose match it is.
     * @param array $state Combat state: bossTurnCount is read.
     * @param array $config The phase's rule config; bossPowers is read.
     * @return bool
     */
    public static function is_ambush(string $currentturn, array $state, array $config): bool {
        return $currentturn === 'boss' && $state['bossTurnCount'] === 1 && self::has_power($config, self::POWER_AMBUSH);
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
     * The HP an opponent with the drain power recovers from one of its hits: DRAIN_PERCENT of the
     * damage that hit actually dealt (nothing when the shield took it), up to its max HP. Only the
     * opponent's hits drain (Swords from the board and critical answers), never the student's
     * wrong-answer penalty or poison. The caller adds it to currentHp.
     *
     * @param float $appliedamount The damage the hit dealt, from resolve_damage().
     * @param array $state Current combat state: currentHp and maxBossHp are read.
     * @param array $config The phase's rule config; bossPowers is read.
     * @return float
     */
    public static function drain_amount(float $appliedamount, array $state, array $config): float {
        if (!self::has_power($config, self::POWER_DRAIN)) {
            return 0.0;
        }
        return (float) min($state['maxBossHp'] - $state['currentHp'], $appliedamount * self::DRAIN_PERCENT / 100);
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
     * Everything that happens to a side as its turn begins, before it moves: its pending poison
     * tick (see resolve_poison_tick()); for the opponent, its turn count goes up (its first turn
     * is 1); with the intangible power, every INTANGIBLE_EVERY turns its Shield arms; with the
     * breath power, while it still stands, every BREATH_EVERY turns it hits the student for
     * BREATH_MULTIPLIER times the base damage, through its attack, the student's armor and Shield,
     * and its own drain; with the loot power, every LOOT_EVERY turns it takes LOOT_PERCENT of the
     * student's coins for itself; and with the regen power, while it still stands, REGEN_PERCENT of
     * its max HP comes back, up to the max. Last, the opponent may use one of its items (see
     * opponent_item_use()). The caller checks whether the student survived the breath or a Sword.
     *
     * @param string $side 'player' or 'boss' — whose turn begins.
     * @param array $state Current combat state — copied, never mutated.
     * @param array $config Keys baseDamage, the phase's attack and armor (see hit_amount()), and
     *  bossPowers (optional).
     * @return array ['state' => array, 'poisonTicked' => bool, 'regenerated' => float, 'shieldArmed' => bool,
     *  'breath' => array|null, 'looted' => float] shieldArmed is true when the intangible power armed a Shield
     *  that was not armed yet; breath is null on a turn without one, otherwise ['hit', 'appliedAmount',
     *  'shieldConsumed', 'drained']; looted is the coins taken, 0 if none; item is null, or the item the
     *  opponent used as ['kind', 'amount', 'appliedAmount', 'shieldConsumed', 'drained'].
     */
    public static function start_turn(string $side, array $state, array $config): array {
        $next = $state;
        $hpfield = $side === 'player' ? 'currentPlayerHp' : 'currentHp';
        $roundsfield = $side === 'player' ? 'playerPoisonRounds' : 'bossPoisonRounds';
        $tick = self::resolve_poison_tick($next[$hpfield], $next[$roundsfield], (float) $config['baseDamage']);
        $next[$hpfield] = $tick['newHp'];
        $next[$roundsfield] = $tick['newPoisonRounds'];
        $shieldarmed = false;
        if ($side === 'boss') {
            $next['bossTurnCount'] += 1;
            if (self::has_power($config, self::POWER_INTANGIBLE) && $next['bossTurnCount'] % self::INTANGIBLE_EVERY === 0) {
                $shieldarmed = !$next['bossShieldReady'];
                $next['bossShieldReady'] = true;
            }
        }

        $breath = null;
        if (
            $side === 'boss' && self::has_power($config, self::POWER_BREATH) && $next['currentHp'] > 0
            && $next['bossTurnCount'] % self::BREATH_EVERY === 0
        ) {
            $hit = self::hit_amount($config['baseDamage'] * self::BREATH_MULTIPLIER, 'boss', $config);
            $applied = self::resolve_damage($next['currentPlayerHp'], $next['playerShieldReady'], $hit);
            $next['currentPlayerHp'] = $applied['newHp'];
            if ($applied['shieldConsumed']) {
                $next['playerShieldReady'] = false;
            }
            $drained = self::drain_amount($applied['appliedAmount'], $next, $config);
            $next['currentHp'] += $drained;
            $breath = [
                'hit' => $hit,
                'appliedAmount' => $applied['appliedAmount'],
                'shieldConsumed' => $applied['shieldConsumed'],
                'drained' => $drained,
            ];
        }

        $looted = 0.0;
        if ($side === 'boss' && self::has_power($config, self::POWER_LOOT) && $next['bossTurnCount'] % self::LOOT_EVERY === 0) {
            $looted = (float) ($next['playerGold'] * self::LOOT_PERCENT / 100);
            $next['playerGold'] -= $looted;
            $next['bossGold'] += $looted;
        }

        $regenerated = 0.0;
        if ($side === 'boss' && self::has_power($config, self::POWER_REGEN) && $next['currentHp'] > 0) {
            $regenerated = (float) min(
                $next['maxBossHp'] - $next['currentHp'],
                $next['maxBossHp'] * self::REGEN_PERCENT / 100
            );
            $next['currentHp'] += $regenerated;
        }
        $item = self::use_opponent_item($side, $next, $config);
        return [
            'state' => $next,
            'poisonTicked' => $tick['ticked'],
            'regenerated' => $regenerated,
            'shieldArmed' => $shieldarmed,
            'breath' => $breath,
            'looted' => $looted,
            'item' => $item,
        ];
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
