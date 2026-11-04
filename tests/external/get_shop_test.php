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
 * External function tests for get_shop.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\external;

use core_external\external_api;
use mod_playerpuzzle\local\constants;
use mod_playerpuzzle\local\game_page_service;
use mod_playerpuzzle\local\user_stock;

/**
 * Tests for the mod_playerpuzzle_get_shop web service.
 *
 * @covers \mod_playerpuzzle\external\get_shop
 */
final class get_shop_test extends \advanced_testcase {
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
     * Calls the web service through the real dispatch path.
     *
     * @param \stdClass $instance The instance.
     * @return array Response shaped as ['error' => bool, 'data' => array|null, ...].
     */
    private function call(\stdClass $instance): array {
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function('mod_playerpuzzle_get_shop', ['cmid' => $instance->cmid]);
    }

    /**
     * Tests that a Campaign student with a hero gets the whole shop: their balance, the five consumables and
     * the hero's upgrades, rendered.
     *
     * @return void
     */
    public function test_a_campaign_hero_gets_the_whole_shop(): void {
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $this->course->id, 'maxlevels' => 1]);
        $this->setUser($this->student);
        game_page_service::choose_campaign_hero($instance, (int) $this->student->id, 'warriorf');
        user_stock::credit((int) $this->student->id, (int) $instance->id, user_stock::CURRENCY_TYPE, 33);

        $result = $this->call($instance);

        $this->assertFalse($result['error']);
        $html = $result['data']['html'];
        $this->assertStringContainsString('class="pp-shop-panel" data-cmid="' . $instance->cmid . '"', $html);
        $this->assertStringContainsString('>33<', $html);
        $this->assertSame(5, substr_count($html, 'pp-lobby-buy"'));
        $this->assertSame(3, substr_count($html, 'pp-hero-buy"'));
        $this->assertStringContainsString(get_string('hero_name_warriorf', 'mod_playerpuzzle'), $html);
    }

    /**
     * Tests that a Single Match has the consumables and no hero upgrades.
     *
     * @return void
     */
    public function test_a_single_match_has_no_upgrades(): void {
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $this->course->id, 'gamemode' => constants::GAMEMODE_SINGLE]);
        $this->setUser($this->student);

        $html = $this->call($instance)['data']['html'];

        $this->assertSame(5, substr_count($html, 'pp-lobby-buy"'));
        $this->assertStringNotContainsString('pp-hero-buy', $html);
    }

    /**
     * Tests that a visitor is refused.
     *
     * @return void
     */
    public function test_a_visitor_is_refused(): void {
        global $DB;

        $instance = $this->getDataGenerator()->get_plugin_generator('mod_playerpuzzle')
            ->create_instance(['course' => $this->course->id]);
        $plugin = enrol_get_plugin('guest');
        $enrol = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'guest']);
        if ($enrol) {
            $plugin->update_status($enrol, ENROL_INSTANCE_ENABLED);
        } else {
            $plugin->add_instance($this->course, ['status' => ENROL_INSTANCE_ENABLED]);
        }
        $this->setGuestUser();

        $result = $this->call($instance);

        $this->assertTrue($result['error']);
        $this->assertSame('guestdemoonly', $result['exception']->errorcode);
    }
}
