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
 * Unit tests for the Music/Sound Effects/narration preferences service.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for sound_preferences.
 *
 * @covers \mod_playerpuzzle\local\sound_preferences
 */
final class sound_preferences_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests that Music/Sound Effects default to enabled for a user who never touched
     * either preference — matches the in-game default before this preference existed.
     *
     * @return void
     */
    public function test_defaults_to_enabled(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue(sound_preferences::is_enabled('music', (int) $user->id));
        $this->assertTrue(sound_preferences::is_enabled('sfx', (int) $user->id));
    }

    /**
     * Tests that narration defaults to disabled — it speaks over the game through the
     * browser's speech synthesis, so a student must opt in deliberately, unlike Music/
     * Sound Effects which default on.
     *
     * @return void
     */
    public function test_speech_defaults_to_disabled(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(sound_preferences::is_enabled('speech', (int) $user->id));
    }

    /**
     * Tests that set_enabled()/is_enabled() round-trip correctly for each channel.
     *
     * @return void
     */
    public function test_set_enabled_round_trips(): void {
        $user = $this->getDataGenerator()->create_user();

        sound_preferences::set_enabled('music', false, (int) $user->id);
        $this->assertFalse(sound_preferences::is_enabled('music', (int) $user->id));

        sound_preferences::set_enabled('music', true, (int) $user->id);
        $this->assertTrue(sound_preferences::is_enabled('music', (int) $user->id));
    }

    /**
     * Tests that the two channels are stored independently — muting one never touches
     * the other, matching the two independently clickable HUD badges.
     *
     * @return void
     */
    public function test_channels_are_independent(): void {
        $user = $this->getDataGenerator()->create_user();

        sound_preferences::set_enabled('music', false, (int) $user->id);

        $this->assertFalse(sound_preferences::is_enabled('music', (int) $user->id));
        $this->assertTrue(sound_preferences::is_enabled('sfx', (int) $user->id));
    }

    /**
     * Tests that preferences are scoped per user, never leaking across accounts.
     *
     * @return void
     */
    public function test_preferences_are_scoped_per_user(): void {
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        sound_preferences::set_enabled('sfx', false, (int) $usera->id);

        $this->assertFalse(sound_preferences::is_enabled('sfx', (int) $usera->id));
        $this->assertTrue(sound_preferences::is_enabled('sfx', (int) $userb->id));
    }

    /**
     * Tests that preference_name() is prefixed consistently for both channels — the same
     * prefix db/uninstall.php matches on.
     *
     * @return void
     */
    public function test_preference_name_is_prefixed(): void {
        $this->assertSame('mod_playerpuzzle_music', sound_preferences::preference_name('music'));
        $this->assertSame('mod_playerpuzzle_sfx', sound_preferences::preference_name('sfx'));
        $this->assertSame('mod_playerpuzzle_speech', sound_preferences::preference_name('speech'));
    }

    /**
     * Tests that turning narration on for one user never affects Music/Sound Effects, nor
     * another user's own narration preference.
     *
     * @return void
     */
    public function test_speech_is_independent_of_other_channels_and_users(): void {
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        sound_preferences::set_enabled('speech', true, (int) $usera->id);

        $this->assertTrue(sound_preferences::is_enabled('speech', (int) $usera->id));
        $this->assertTrue(sound_preferences::is_enabled('music', (int) $usera->id));
        $this->assertTrue(sound_preferences::is_enabled('sfx', (int) $usera->id));
        $this->assertFalse(sound_preferences::is_enabled('speech', (int) $userb->id));
    }
}
