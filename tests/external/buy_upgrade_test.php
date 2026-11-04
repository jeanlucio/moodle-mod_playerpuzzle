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
 * External function tests for buy_upgrade.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use core_external\external_api;
use mod_playerpuzzle\local\constants;
use mod_playerpuzzle\local\engine\security;
use mod_playerpuzzle\local\game_page_service;
use mod_playerpuzzle\local\heroes;
use mod_playerpuzzle\local\user_hero;
use mod_playerpuzzle\local\user_stock;

/**
 * Tests for the mod_playerpuzzle_buy_upgrade web service.
 *
 * @covers \mod_playerpuzzle\external\buy_upgrade
 * @covers \mod_playerpuzzle\local\hero_shop
 * @covers \mod_playerpuzzle\local\engine\security
 */
final class buy_upgrade_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Creates a one-level Campaign whose student has a mage, for the given coins.
     *
     * @param int $coins PuzzleCoin the student has.
     * @param array $overrides Instance field overrides.
     * @return \stdClass Instance record with the ->cmid field added.
     */
    private function make_campaign(int $coins = 100, array $overrides = []): \stdClass {
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')->create_instance(
            array_merge(['course' => $this->course->id, 'maxlevels' => 1, 'minquestions' => 0], $overrides)
        );
        $this->setUser($this->student);
        game_page_service::choose_campaign_hero($instance, (int) $this->student->id, 'magef');
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, $coins);

        return $instance;
    }

    /**
     * Calls the web service through the real dispatch path.
     *
     * @param \stdClass $instance The instance.
     * @param string $attribute The attribute.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(\stdClass $instance, string $attribute): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function(
            'mod_playerpuzzle_buy_upgrade',
            ['cmid' => $instance->cmid, 'attribute' => $attribute]
        );
    }

    /**
     * Tests a purchase: it costs the price, counts one more of that attribute and nothing else, and
     * answers with the balance left. At one level the first stage allows one of each.
     *
     * @return void
     */
    public function test_a_purchase_costs_the_price_and_counts(): void {
        $instance = $this->make_campaign(40);

        $result = $this->call($instance, heroes::ATTACK);

        $this->assertFalse($result['error']);
        $this->assertSame(
            ['newcoinbalance' => 40 - heroes::PRICE, 'bought' => 1, 'cap' => 1, 'stage' => 1, 'evolved' => false],
            array_intersect_key($result['data'], array_flip(['newcoinbalance', 'bought', 'cap', 'stage', 'evolved']))
        );
        $hero = user_hero::get((int) $instance->id, (int) $this->student->id, 1);
        $this->assertSame([0, 1, 0], [(int) $hero->hpbuys, (int) $hero->attackbuys, (int) $hero->armorbuys]);
        $this->assertSame(
            40 - heroes::PRICE,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
    }

    /**
     * Tests that a stage's cap holds: with the one buy of the stage made, a second of the same attribute is
     * refused and costs nothing, while another attribute still has its own.
     *
     * @return void
     */
    public function test_the_stages_cap_is_refused_without_charging(): void {
        $instance = $this->make_campaign(100);
        $this->call($instance, heroes::ARMOR);

        $again = $this->call($instance, heroes::ARMOR);

        $this->assertTrue($again['error']);
        $this->assertSame('upgradecapreached', $again['exception']->errorcode);
        $this->assertSame(
            100 - heroes::PRICE,
            user_stock::get_quantity((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE)
        );
        $this->assertFalse($this->call($instance, heroes::HP)['error']);
    }

    /**
     * Tests the refusals that cost nothing: a short balance, a name that is not an upgrade, a Campaign with
     * no hero chosen yet, and a Single Match.
     *
     * @return void
     */
    public function test_the_refusals_leave_the_hero_and_the_balance_alone(): void {
        global $DB;

        $poor = $this->make_campaign(heroes::PRICE - 1);
        $short = $this->call($poor, heroes::HP);
        $this->assertSame('insufficientcoins', $short['exception']->errorcode);
        $this->assertSame('upgradeinvalid', $this->call($poor, 'luck')['exception']->errorcode);
        $this->assertSame(0, (int) $DB->get_field('playerpuzzle_heroes', 'hpbuys', ['playerpuzzleid' => $poor->id]));
        $this->assertSame(
            heroes::PRICE - 1,
            user_stock::get_quantity((int) $this->student->id, (int) $poor->id, user_stock::CURRENCY_TYPE)
        );

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle');
        $heroless = $generator->create_instance(['course' => $this->course->id, 'maxlevels' => 1]);
        user_stock::credit((int) $this->student->id, (int) $heroless->id, user_stock::CURRENCY_TYPE, 100);
        $this->assertSame('upgradeunavailable', $this->call($heroless, heroes::HP)['exception']->errorcode);

        $single = $generator->create_instance(['course' => $this->course->id, 'gamemode' => constants::GAMEMODE_SINGLE]);
        user_stock::credit((int) $this->student->id, (int) $single->id, user_stock::CURRENCY_TYPE, 100);
        $this->assertSame('upgradeunavailable', $this->call($single, heroes::HP)['exception']->errorcode);
        $this->assertSame(0, $DB->count_records('playerpuzzle_heroes', ['playerpuzzleid' => $single->id]));
    }

    /**
     * Tests that a visitor cannot buy.
     *
     * @return void
     */
    public function test_a_visitor_cannot_buy(): void {
        global $DB;

        $instance = $this->make_campaign(100);
        $plugin = enrol_get_plugin('guest');
        $enrol = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'guest']);
        if ($enrol) {
            $plugin->update_status($enrol, ENROL_INSTANCE_ENABLED);
        } else {
            $plugin->add_instance($this->course, ['status' => ENROL_INSTANCE_ENABLED]);
        }
        $this->setGuestUser();

        $result = $this->call($instance, heroes::HP);

        $this->assertTrue($result['error']);
        $this->assertSame('guestdemoonly', $result['exception']->errorcode);
    }

    /**
     * Tests the evolution a purchase can bring: with the Campaign past the first milestone (three phases of
     * ten, as the student is on the fourth), the specialty's buy that fills the stage's cap evolves the hero.
     * A buy of another attribute does not, since only the specialty counts.
     *
     * @return void
     */
    public function test_buying_the_specialty_past_the_milestone_evolves_the_hero(): void {
        global $DB;

        $instance = $this->make_campaign(100);
        $DB->insert_record('playerpuzzle_attempts', (object) [
            'playerpuzzleid' => $instance->id, 'userid' => $this->student->id, 'token' => bin2hex(random_bytes(32)),
            'status' => 'inprogress', 'currentphase' => 4, 'campaign' => 1, 'timecreated' => time(),
            'movelog' => '[{"type":"move"}]', 'moveseq' => 1,
        ]);

        $other = $this->call($instance, heroes::ATTACK);
        $this->assertFalse($other['data']['evolved']);

        $result = $this->call($instance, heroes::HP);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['evolved']);
        $this->assertSame([2, 'magef2'], [$result['data']['stage'], $result['data']['sprite']]);
        $this->assertSame(2, (int) $DB->get_field('playerpuzzle_heroes', 'stage', ['playerpuzzleid' => $instance->id]));
    }

    /**
     * Tests that what is bought counts for a phase not yet played, and not for one under way: the fresh
     * phase is frozen again with the hero's new side, the one with moves in its log keeps what it started with.
     *
     * @return void
     */
    public function test_a_purchase_counts_for_a_fresh_phase_only(): void {
        global $DB;

        $instance = $this->make_campaign(100);
        $userid = (int) $this->student->id;
        $attempt = security::resume_or_create_attempt_token((int) $instance->id, $userid, 'normal', 1);
        $before = $DB->get_record('playerpuzzle_attempts', ['id' => $attempt->attemptid], '*', MUST_EXIST);

        $this->call($instance, heroes::HP);
        $fresh = $DB->get_record('playerpuzzle_attempts', ['id' => $attempt->attemptid], '*', MUST_EXIST);
        $expected = heroes::attributes('magef', [heroes::HP => 1], 1, (int) $instance->basestudenthp);
        $this->assertGreaterThan((int) $before->frozenplayerhp, (int) $fresh->frozenplayerhp);
        $this->assertSame($expected['hp'], (int) $fresh->frozenplayerhp);

        $DB->update_record('playerpuzzle_attempts', (object) [
            'id' => $attempt->attemptid, 'moveseq' => 1, 'movelog' => '[{"type":"move"}]',
        ]);
        $this->call($instance, heroes::ATTACK);
        $played = $DB->get_record('playerpuzzle_attempts', ['id' => $attempt->attemptid], '*', MUST_EXIST);
        $this->assertSame((int) $fresh->frozenplayerhp, (int) $played->frozenplayerhp);
        $this->assertEqualsWithDelta((float) $fresh->frozenplayerattack, (float) $played->frozenplayerattack, 1e-9);
        $this->assertSame(1, (int) $DB->get_field('playerpuzzle_heroes', 'attackbuys', ['playerpuzzleid' => $instance->id]));
    }

    /**
     * Tests that another student's hero is never touched.
     *
     * @return void
     */
    public function test_another_students_hero_is_untouched(): void {
        global $DB;

        $instance = $this->make_campaign(100);
        $other = $this->getDataGenerator()->create_user();
        user_hero::choose((int) $instance->id, (int) $other->id, 1, 'warriorm');

        $this->call($instance, heroes::HP);

        $this->assertSame(0, (int) $DB->get_field('playerpuzzle_heroes', 'hpbuys', ['userid' => $other->id]));
    }
}
