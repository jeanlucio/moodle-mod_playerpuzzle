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
}
