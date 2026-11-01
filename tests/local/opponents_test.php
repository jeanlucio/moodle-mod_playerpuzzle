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
 * Tests for the opponents table.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\combat_engine;

/**
 * Tests for opponents.
 *
 * @covers \mod_playerpuzzle\local\opponents
 */
final class opponents_test extends \advanced_testcase {
    /**
     * A minimal activity record, as opponents::for_phase() reads it.
     *
     * @param string $gamemode The game mode.
     * @param int $maxlevels The Campaign's number of levels.
     * @param string $bossavatar The Single Match opponent, as stored.
     * @return \stdClass
     */
    private function instance(string $gamemode, int $maxlevels = 1, string $bossavatar = 'slime'): \stdClass {
        return (object) ['gamemode' => $gamemode, 'maxlevels' => $maxlevels, 'bossavatar' => $bossavatar];
    }

    /**
     * Tests that a Campaign meets the ten opponents in order on every level, with the final boss
     * only on the last phase of the last level.
     *
     * @return void
     */
    public function test_campaign_meets_every_opponent_in_order_and_the_final_boss_last(): void {
        $campaign = $this->instance(constants::GAMEMODE_CAMPAIGN, 3);

        foreach ([1, 2, 3] as $level) {
            $met = [];
            for ($phase = 1; $phase <= 10; $phase++) {
                $met[] = opponents::for_phase($campaign, $level, $phase);
            }
            $expected = opponents::CAMPAIGN_ORDER;
            if ($level === 3) {
                $expected[9] = opponents::FINAL_BOSS;
            }
            $this->assertSame($expected, $met, "level {$level}");
        }
    }

    /**
     * Tests that a one-level Campaign ends on the final boss.
     *
     * @return void
     */
    public function test_a_one_level_campaign_ends_on_the_final_boss(): void {
        $campaign = $this->instance(constants::GAMEMODE_CAMPAIGN, 1);

        $this->assertSame('spider', opponents::for_phase($campaign, 1, 9));
        $this->assertSame('vampire', opponents::for_phase($campaign, 1, 10), 'not the dragon');
    }

    /**
     * Tests that a Single Match always fights the teacher's pick, including a value stored as an
     * image filename before opponents had keys, and falls back to the default for anything else.
     *
     * @return void
     */
    public function test_single_match_fights_the_chosen_opponent(): void {
        $this->assertSame('wolf', opponents::for_phase($this->instance(constants::GAMEMODE_SINGLE, 1, 'wolf'), 1, 1));
        $this->assertSame('vampire', opponents::for_phase($this->instance(constants::GAMEMODE_SINGLE, 1, 'vampire'), 1, 1));
        $this->assertSame('dragon', opponents::for_phase($this->instance(constants::GAMEMODE_SINGLE, 1, 'dragon.png'), 1, 1));
        $this->assertSame(opponents::DEFAULT, opponents::for_phase($this->instance(constants::GAMEMODE_SINGLE, 1, 'x'), 1, 1));
    }

    /**
     * Tests that every opponent has its sprite and its own stage on disk, and a name in the form.
     *
     * @return void
     */
    public function test_every_opponent_has_its_art_and_a_name(): void {
        global $CFG;

        $stages = [];
        foreach (opponents::keys() as $key) {
            $stages[] = opponents::stage($key);
            $this->assertFileExists("{$CFG->dirroot}/mod/playerpuzzle/pix/opponents/{$key}.webp");
            $this->assertFileExists("{$CFG->dirroot}/mod/playerpuzzle/pix/stages/" . opponents::stage($key) . '.webp');
            $this->assertStringEndsWith("/mod/playerpuzzle/pix/opponents/{$key}.webp", opponents::sprite_url($key)->out(false));
        }

        $this->assertCount(11, $stages);
        $this->assertSame($stages, array_unique($stages), 'each opponent has a stage of its own');
        $this->assertSame(opponents::keys(), array_keys(opponents::options()));
        $this->assertSame(array_merge(opponents::CAMPAIGN_ORDER, [opponents::FINAL_BOSS]), opponents::keys());
    }

    /**
     * Tests that every Campaign opponent has a profile and the final boss has none.
     *
     * @return void
     */
    public function test_every_campaign_opponent_has_a_profile_and_the_final_boss_none(): void {
        $expected = [
            'slime' => opponents::PROFILE_TOUGH,
            'goblin' => opponents::PROFILE_BRUTE,
            'golem' => opponents::PROFILE_ARMORED,
            'mummy' => opponents::PROFILE_BRUTE,
            'plant' => opponents::PROFILE_ARMORED,
            'ghost' => opponents::PROFILE_TOUGH,
            'pirate' => opponents::PROFILE_BRUTE,
            'wolf' => opponents::PROFILE_BRUTE,
            'spider' => opponents::PROFILE_TOUGH,
            'dragon' => opponents::PROFILE_BRUTE,
        ];
        foreach (opponents::CAMPAIGN_ORDER as $key) {
            $this->assertSame($expected[$key], opponents::profile($key), $key);
        }
        $this->assertNull(opponents::profile(opponents::FINAL_BOSS));
    }

    /**
     * Tests that each profile changes only its own attribute, and that an opponent without a
     * profile, or an attempt that never had its opponent frozen, changes nothing.
     *
     * @return void
     */
    public function test_each_profile_changes_only_its_own_attribute(): void {
        $none = ['hp' => 1.0, 'attack' => 1.0, 'armor' => 0.0];

        $brute = opponents::modifiers('goblin');
        $this->assertGreaterThan(1.0, $brute['attack']);
        $this->assertSame(['hp' => 1.0, 'armor' => 0.0], ['hp' => $brute['hp'], 'armor' => $brute['armor']]);

        $armored = opponents::modifiers('golem');
        $this->assertGreaterThan(0.0, $armored['armor']);
        $this->assertSame(['hp' => 1.0, 'attack' => 1.0], ['hp' => $armored['hp'], 'attack' => $armored['attack']]);

        $tough = opponents::modifiers('slime');
        $this->assertGreaterThan(1.0, $tough['hp']);
        $this->assertSame(['attack' => 1.0, 'armor' => 0.0], ['attack' => $tough['attack'], 'armor' => $tough['armor']]);

        $this->assertSame($none, opponents::modifiers('vampire'));
        $this->assertSame($none, opponents::modifiers(''));
    }

    /**
     * Tests that the golem starts with its Shield armed, and that an opponent without a power,
     * or an attempt that never had its opponent frozen, has none.
     *
     * @return void
     */
    public function test_powers_of_each_opponent(): void {
        $this->assertSame([combat_engine::POWER_ICESHIELD], opponents::powers('golem'));
        $this->assertSame([], opponents::powers('vampire'));
        $this->assertSame([], opponents::powers(''));
    }
}
