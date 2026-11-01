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
 * Cross-language parity for the pure combat rules.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

/**
 * Runs every case in tests/fixtures/combat_vectors.json through combat_engine.
 *
 * Each case is a call to amd/src/engine/combat_rules.js with its arguments and the result the
 * JavaScript engine returned, written by tests/js/engine/generate_vectors.js. The PHP port must
 * return the same value, bit for bit: numbers are compared as floats (JSON does not keep PHP's
 * int/float distinction) but never with a tolerance.
 *
 * @covers \mod_playerpuzzle\local\engine\combat_engine
 */
final class combat_vectors_test extends \advanced_testcase {
    /**
     * Loads the JavaScript engine's recorded calls, one data set per function.
     *
     * @return array Function name => [cases].
     */
    public static function vectors_provider(): array {
        $raw = file_get_contents(__DIR__ . '/../../fixtures/combat_vectors.json');
        $byfunction = [];
        foreach (json_decode($raw, true) as $case) {
            $byfunction[$case['fn']][] = $case;
        }

        $sets = [];
        foreach ($byfunction as $fn => $cases) {
            $sets[$fn] = [$cases];
        }
        return $sets;
    }

    /**
     * Tests that combat_engine returns what combat_rules.js returned for the same call.
     *
     * @dataProvider vectors_provider
     * @param array $cases Every recorded call of one function.
     * @return void
     */
    public function test_combat_engine_matches_the_javascript_engine(array $cases): void {
        foreach ($cases as $index => $case) {
            $this->assertSame(
                self::canonical($case['result']),
                self::canonical(self::call($case['fn'], $case['args'])),
                $case['fn'] . ' case ' . $index
            );
        }
    }

    /**
     * Calls the PHP twin of a combat_rules.js function with the recorded arguments.
     *
     * @param string $fn The JavaScript function name.
     * @param array $args The recorded arguments, in call order.
     * @return mixed What combat_engine returns.
     */
    private static function call(string $fn, array $args): mixed {
        switch ($fn) {
            case 'comboMultiplier':
                return combat_engine::combo_multiplier($args[0]);
            case 'resolvePoisonMeters':
                $state = $args[0];
                return combat_engine::resolve_poison_meters($state);
            case 'resolveShieldMeters':
                $state = $args[0];
                return combat_engine::resolve_shield_meters($state);
            case 'phaseLimit':
                return combat_engine::phase_limit($args[0]);
            case 'needsRevive':
                return combat_engine::needs_revive($args[0], $args[1]);
            case 'reviveHp':
                return combat_engine::revive_hp($args[0]);
            case 'hasNextPhase':
                return combat_engine::has_next_phase($args[0]);
            case 'resolveDamage':
                return combat_engine::resolve_damage($args[0], $args[1], $args[2]);
            case 'resolvePoisonTick':
                return combat_engine::resolve_poison_tick($args[0], $args[1], $args[2]);
            case 'resolveMatchEffects':
                return combat_engine::resolve_match_effects(
                    $args[0]['currentTurn'],
                    $args[0]['destroyedTypes'],
                    $args[0]['matchGroups'],
                    $args[0]['state'],
                    $args[0]['config']
                );
            case 'resolveConsumableEffect':
                return combat_engine::resolve_consumable_effect($args[0], $args[1], $args[2]);
            case 'STATE_FIELDS':
                return combat_engine::STATE_FIELDS;
            case 'initialState':
                return combat_engine::initial_state($args[0], $args[1], $args[2] ?? []);
            case 'matchHitAmount':
                return combat_engine::match_hit_amount($args[0], $args[1], $args[2], $args[3]);
            case 'hitAmount':
                return combat_engine::hit_amount($args[0], $args[1], $args[2]);
            case 'ARMOR_CAP':
                return combat_engine::ARMOR_CAP;
            case 'questionOutcome':
                return combat_engine::question_outcome($args[0], $args[1], $args[2], $args[3]);
            case 'startTurn':
                return combat_engine::start_turn($args[0], $args[1], $args[2]);
        }
        throw new \coding_exception('No PHP twin mapped for combat_rules.' . $fn);
    }

    /**
     * Normalises a value for an exact comparison: every number becomes a float and keys are
     * sorted, so only a real difference in value fails.
     *
     * @param mixed $value A result.
     * @return mixed
     */
    private static function canonical(mixed $value): mixed {
        if (is_array($value)) {
            $value = array_map([self::class, 'canonical'], $value);
            if (!array_is_list($value)) {
                ksort($value);
            }
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        return $value;
    }
}
