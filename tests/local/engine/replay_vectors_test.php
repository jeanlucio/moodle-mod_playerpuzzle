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
 * Cross-language parity for the replay's turn order.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local\engine;

use mod_playerpuzzle\local\move_log;
use mod_playerpuzzle\local\opponents;

/**
 * Replays every match in tests/fixtures/replay_vectors.json through the real replay::derive().
 *
 * Each match was played and recorded by tests/js/engine/replay_mirror.js on the game's own
 * JavaScript engine (board_rules.js, combat_rules.js, prng.js), following the turn order the
 * live client runs. Reaching the same totals here proves the server runs that same order with
 * the same rules; a change to either side alone breaks it. The fixture is regenerated only by
 * tests/js/engine/generate_vectors.js, never by hand.
 *
 * @covers \mod_playerpuzzle\local\engine\replay
 * @covers \mod_playerpuzzle\local\engine\combat_engine
 * @covers \mod_playerpuzzle\local\engine\board_engine
 */
final class replay_vectors_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Loads the recorded matches.
     *
     * @return array Name => [vector].
     */
    public static function vectors_provider(): array {
        $raw = file_get_contents(__DIR__ . '/../../fixtures/replay_vectors.json');
        $cases = [];
        foreach (json_decode($raw, true) as $vector) {
            $cases[$vector['name']] = [$vector];
        }
        return $cases;
    }

    /**
     * Tests that the server derives exactly what the JavaScript engine recorded.
     *
     * @dataProvider vectors_provider
     * @param array $vector One recorded match.
     * @return void
     */
    public function test_derive_matches_the_javascript_engine(array $vector): void {
        global $DB;

        $attemptid = 500;
        foreach ($vector['serveruses'] as $type => $times) {
            $DB->insert_record('playerpuzzle_attempt_consumables', (object) [
                'attemptid' => $attemptid,
                'consumabletype' => $type,
                'timesused' => $times,
            ]);
        }

        // The fixture spells the questions_total column the JavaScript way.
        $fields = $vector['attempt'];
        $fields['questions_total'] = $fields['questionsTotal'];
        unset($fields['questionsTotal']);

        // A case that names no opponent is an attempt whose opponent was never frozen, which
        // fights with no profile. A case that names one carries the modifiers the JavaScript side
        // recorded it with, which must be the server's own.
        $fields += ['frozenopponent' => ''];
        $this->assertEquals(
            $vector['modifiers'] ?? ['hp' => 1, 'attack' => 1, 'armor' => 0],
            opponents::modifiers($fields['frozenopponent'])
        );
        $this->assertSame($vector['powers'] ?? [], opponents::powers($fields['frozenopponent']));

        $attempt = (object) array_merge($fields, [
            'id' => $attemptid,
            'isdemo' => 0,
            'engineversion' => (int) get_config('mod_playerpuzzle', 'version'),
            'movelog' => move_log::encode($vector['events']),
            'questionresults' => $vector['outcomes'] === [] ? null : json_encode($vector['outcomes']),
        ]);
        $playerpuzzle = (object) array_merge($vector['playerpuzzle'], ['id' => 0]);

        $this->assertSame($vector['expected'], replay::derive($attempt, $playerpuzzle));
    }
}
