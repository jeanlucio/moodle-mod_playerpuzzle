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
 * Tests for the hero a student plays.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use mod_playerpuzzle\local\engine\security;

/**
 * Tests for user_hero, and for the phase freezing what the hero fights with.
 *
 * @covers \mod_playerpuzzle\local\user_hero
 * @covers \mod_playerpuzzle\local\engine\security
 */
final class user_hero_test extends \advanced_testcase {
    /** @var \stdClass Activity instance. */
    private \stdClass $instance;

    /** @var \stdClass Student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')->create_instance([
            'course' => $course->id,
            'maxlevels' => 3,
            'basestudenthp' => 100,
            'minquestions' => 0,
        ]);
    }

    /**
     * Tests that choosing a hero starts it at stage 1 with nothing bought, that choosing again in the same
     * Campaign keeps the first one (and what it bought), and that the next Campaign has a hero of its own.
     *
     * @return void
     */
    public function test_choosing_a_hero_keeps_the_first_one_of_a_campaign(): void {
        global $DB;

        $ppid = (int) $this->instance->id;
        $userid = (int) $this->student->id;
        $this->assertNull(user_hero::get($ppid, $userid, 1));

        $first = user_hero::choose($ppid, $userid, 1, 'archerf');
        $this->assertSame(['archerf', 1, 0, 0, 0], [$first->hero, (int) $first->stage, (int) $first->hpbuys,
            (int) $first->attackbuys, (int) $first->armorbuys]);

        $DB->set_field('playerpuzzle_heroes', 'armorbuys', 2, ['id' => $first->id]);
        $again = user_hero::choose($ppid, $userid, 1, 'warriorm');
        $this->assertSame('archerf', $again->hero);
        $this->assertSame(2, user_hero::buys(user_hero::get($ppid, $userid, 1))[heroes::ARMOR]);

        $second = user_hero::choose($ppid, $userid, 2, 'warriorm');
        $this->assertSame(['warriorm', 0], [$second->hero, user_hero::buys($second)[heroes::ARMOR]]);
        $this->assertSame(2, $DB->count_records('playerpuzzle_heroes', ['userid' => $userid]));
    }

    /**
     * Tests that a name outside the catalog is refused.
     *
     * @return void
     */
    public function test_choosing_an_unknown_hero_is_refused(): void {
        $this->expectException(\coding_exception::class);
        user_hero::choose((int) $this->instance->id, (int) $this->student->id, 1, 'dragon');
    }

    /**
     * Tests that a phase of a Campaign freezes what the hero fights with and its sprite, and that a
     * student with no hero yet still gets the instance's own scaling with no attack or armor.
     *
     * @return void
     */
    public function test_a_phase_freezes_the_heroes_side(): void {
        global $DB;

        $ppid = (int) $this->instance->id;
        $userid = (int) $this->student->id;

        $bare = security::resume_or_create_attempt_token($ppid, $userid, 'normal', 3);
        $this->assertSame([100, 1.0, 0.0, ''], [$bare->frozenplayerhp, $bare->frozenplayerattack, $bare->frozenplayerarmor,
            $bare->frozenhero]);
        $DB->delete_records('playerpuzzle_attempts', ['id' => $bare->attemptid]);

        $row = user_hero::choose($ppid, $userid, 1, 'magef');
        $DB->update_record('playerpuzzle_heroes', (object) ['id' => $row->id, 'hpbuys' => 6, 'attackbuys' => 3, 'stage' => 2]);

        $attempt = security::resume_or_create_attempt_token($ppid, $userid, 'normal', 3);
        $expected = heroes::attributes('magef', [heroes::HP => 6, heroes::ATTACK => 3], 3, 100);

        $this->assertSame(
            [$expected['hp'], $expected['attack'], $expected['armor'], 'magef2'],
            [$attempt->frozenplayerhp, $attempt->frozenplayerattack, $attempt->frozenplayerarmor, $attempt->frozenhero]
        );
        $stored = $DB->get_record('playerpuzzle_attempts', ['id' => $attempt->attemptid], '*', MUST_EXIST);
        $this->assertSame('magef2', $stored->frozenhero);
        $this->assertEqualsWithDelta($expected['attack'], (float) $stored->frozenplayerattack, 1e-9);
    }

    /**
     * Tests that the hero of one Campaign is not the hero of the next: its attributes start over.
     *
     * @return void
     */
    public function test_each_campaign_freezes_its_own_hero(): void {
        global $DB;

        $ppid = (int) $this->instance->id;
        $userid = (int) $this->student->id;
        $old = user_hero::choose($ppid, $userid, 1, 'warriorf');
        $DB->set_field('playerpuzzle_heroes', 'attackbuys', 12, ['id' => $old->id]);
        user_hero::choose($ppid, $userid, 2, 'warriorf');
        $attempt = (object) ['userid' => $userid, 'campaign' => 2, 'isdemo' => 0, 'currentlevel' => 1, 'currentphase' => 1];

        security::seed_replay_state($attempt, $this->instance);

        $this->assertSame(
            heroes::attributes('warriorf', [], 3, 100)['attack'],
            (float) $attempt->frozenplayerattack
        );
    }

    /**
     * Tests that a Single Match or Demo attempt never reads a hero row, even for a student who has one.
     *
     * @return void
     */
    public function test_single_match_and_demo_ignore_the_campaign_hero(): void {
        global $DB;

        $ppid = (int) $this->instance->id;
        $userid = (int) $this->student->id;
        $row = user_hero::choose($ppid, $userid, 1, 'warriorf');
        $DB->set_field('playerpuzzle_heroes', 'attackbuys', 12, ['id' => $row->id]);

        $demo = (object) ['userid' => $userid, 'campaign' => 1, 'isdemo' => 1, 'currentlevel' => 1, 'currentphase' => 1];
        security::seed_replay_state($demo, $this->instance);
        $this->assertSame(1.0, $demo->frozenplayerattack);

        $single = clone $this->instance;
        $single->gamemode = constants::GAMEMODE_SINGLE;
        $match = (object) ['userid' => $userid, 'campaign' => 1, 'isdemo' => 0, 'currentlevel' => 1, 'currentphase' => 1];
        security::seed_replay_state($match, $single);
        $this->assertSame(1.0, $match->frozenplayerattack);
        $this->assertFalse(isset($match->frozenhero));
    }
}
