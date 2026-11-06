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
 * Tests for the heroes' rules.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for heroes.
 *
 * @covers \mod_playerpuzzle\local\heroes
 */
final class heroes_test extends \basic_testcase {
    /**
     * Tests the catalog: six heroes, two of each specialty, and a sprite for each of the four stages.
     *
     * @return void
     */
    public function test_the_catalog_has_six_heroes_two_per_specialty(): void {
        $this->assertCount(6, heroes::CATALOG);
        $this->assertSame(
            [heroes::ATTACK => 2, heroes::HP => 2, heroes::ARMOR => 2],
            array_count_values(heroes::CATALOG)
        );
        $this->assertTrue(heroes::is_valid('magef'));
        $this->assertFalse(heroes::is_valid('dragon'));
        $this->assertFalse(heroes::is_valid(''));
        $this->assertSame(heroes::ARMOR, heroes::specialty('archerf'));
        $this->assertSame('magem3', heroes::sprite('magem', 3));
        $this->assertSame('magem1', heroes::sprite('magem', 0));
        $this->assertSame('magem4', heroes::sprite('magem', 9));
    }

    /**
     * Tests that a sprite names its hero, whatever its stage, and that anything else names none.
     *
     * @return void
     */
    public function test_a_sprite_names_its_hero(): void {
        $this->assertSame('archerf', heroes::of_sprite('archerf4'));
        $this->assertSame('magem', heroes::of_sprite(heroes::sprite('magem', 2)));
        $this->assertSame('', heroes::of_sprite(''));
        $this->assertSame('', heroes::of_sprite('dragon3'));
        $this->assertSame('', heroes::of_sprite('magef'), 'a hero without a stage is not a sprite');
    }

    /**
     * Data provider for the caps and milestones of Campaigns of different lengths.
     *
     * @return array
     */
    public static function campaign_length_provider(): array {
        return [
            '1 level' => [1, 4, [1, 2, 3, 4], [3, 5, 8]],
            '3 levels' => [3, 12, [3, 6, 9, 12], [8, 15, 23]],
            '10 levels' => [10, 40, [10, 20, 30, 40], [25, 50, 75]],
        ];
    }

    /**
     * Tests that a Campaign's buys, the caps of its stages and the milestones that end them follow
     * its length: each stage is a quarter of both.
     *
     * @dataProvider campaign_length_provider
     * @param int $levels The activity's level count.
     * @param int $total Buys of each attribute the Campaign allows.
     * @param int[] $caps The cap of each stage.
     * @param int[] $milestones Phases to win to leave each stage but the last.
     * @return void
     */
    public function test_caps_and_milestones_follow_the_campaign_length(
        int $levels,
        int $total,
        array $caps,
        array $milestones
    ): void {
        $this->assertSame($total, heroes::total_buys($levels));
        foreach ($caps as $index => $cap) {
            $this->assertSame($cap, heroes::stage_cap($levels, $index + 1));
        }
        foreach ($milestones as $index => $milestone) {
            $this->assertSame($milestone, heroes::milestone($levels, $index + 1));
        }
    }

    /**
     * Tests the worth of a buy: every attribute's whole range split over the Campaign's buys, the HP
     * range growing with the levels so the hero keeps up with the opponents.
     *
     * @return void
     */
    public function test_a_buy_is_the_attributes_range_over_the_total(): void {
        $this->assertEqualsWithDelta((2.4 - 1) / 4, heroes::step(heroes::HP, 1), 1e-9);
        $this->assertEqualsWithDelta((3.64 - 1) / 12, heroes::step(heroes::HP, 3), 1e-9);
        $this->assertEqualsWithDelta((7.98 - 1) / 40, heroes::step(heroes::HP, 10), 1e-9);
        $this->assertEqualsWithDelta(0.9 / 40, heroes::step(heroes::ATTACK, 10), 1e-9);
        $this->assertEqualsWithDelta(0.55 / 4, heroes::step(heroes::ARMOR, 1), 1e-9);
    }

    /**
     * Tests what a hero fights with: a specialty's head start is a twentieth of that attribute's range, so
     * nothing bought still leaves the specialist ahead in its own attribute only.
     *
     * @return void
     */
    public function test_the_specialty_starts_a_twentieth_of_its_range_ahead(): void {
        $warrior = heroes::attributes('warriorf', [], 10, 100);
        $mage = heroes::attributes('magef', [], 10, 100);
        $archer = heroes::attributes('archerm', [], 10, 100);

        // Attack range 0.9, HP range 6.98 (x100 base), armor range 0.55: a twentieth of each.
        $this->assertSame(['hp' => 100, 'attack' => 1.045, 'armor' => 0.0], $warrior);
        $this->assertSame(['hp' => 135, 'attack' => 1.0, 'armor' => 0.0], $mage);
        $this->assertSame(['hp' => 100, 'attack' => 1.0, 'armor' => 0.028], $archer);
    }

    /**
     * Tests that buys add up from a full Campaign's worth, and that armor never passes the combat cap.
     *
     * @return void
     */
    public function test_attributes_after_every_buy_reach_the_final_values(): void {
        $full = [heroes::HP => 4, heroes::ATTACK => 4, heroes::ARMOR => 4];

        $side = heroes::attributes('magem', $full, 1, 100);

        // Four buys of each reach the final values; the specialist's head start (a twentieth of the four buys
        // of HP, so 0.2 of a buy) goes beyond it: 100 x (1 + 4.2 x 0.35).
        $this->assertSame(['hp' => 247, 'attack' => 1.9, 'armor' => 0.55], $side);
        $this->assertEqualsWithDelta(1.945, heroes::attributes('warriorm', $full, 1, 100)['attack'], 1e-9);
        $this->assertLessThanOrEqual(
            heroes::ARMOR_CAP,
            heroes::attributes('archerf', [heroes::ARMOR => 99999], 10, 100)['armor']
        );
    }

    /**
     * Tests that a hero evolves only once a milestone is passed AND its specialty is at the stage's cap,
     * moves more than one stage when both already hold for several, and never goes back.
     *
     * @return void
     */
    public function test_evolution_needs_the_milestone_and_the_specialty_at_the_cap(): void {
        // 1 level: caps 1, 2, 3 and milestones after phase 3, 5, 8; the mage's specialty is HP.
        $this->assertSame(1, heroes::evolved_stage('magef', [heroes::HP => 1], 1, 1, 2), 'before the milestone');
        $this->assertSame(1, heroes::evolved_stage('magef', [heroes::HP => 0, heroes::ATTACK => 1], 1, 1, 3), 'cap not met');
        $this->assertSame(1, heroes::evolved_stage('magef', [heroes::ATTACK => 5], 1, 1, 3), 'only the specialty counts');
        $this->assertSame(2, heroes::evolved_stage('magef', [heroes::HP => 1], 1, 1, 3));
        $this->assertSame(2, heroes::evolved_stage('magef', [heroes::HP => 2], 2, 1, 4), 'waits for the next milestone');
        $this->assertSame(3, heroes::evolved_stage('magef', [heroes::HP => 2], 2, 1, 5));
        $this->assertSame(4, heroes::evolved_stage('magef', [heroes::HP => 3], 1, 1, 8), 'catches up through every stage');
        $this->assertSame(4, heroes::evolved_stage('magef', [heroes::HP => 0], 4, 1, 0), 'never goes back');
    }
}
