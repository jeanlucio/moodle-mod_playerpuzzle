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
 * Lock factory that records every lock taken, for tests.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Grants every lock without blocking and records each acquire/release in order, or refuses
 * the resources a test lists. PHPUnit runs one request at a time, so it cannot race two
 * writers against each other; what it can prove is which locks a code path takes, in what
 * order, and that nothing is written when one of them cannot be had.
 *
 * Installed through $CFG->lock_factory, which lock_config reads on every call.
 */
class mod_playerpuzzle_recording_lock_factory implements \core\lock\lock_factory {
    /** @var string[] Every acquire/release/refusal so far, as "<verb> <type>/<resource>". */
    public static array $events = [];

    /** @var string[] Keys ("<type>/<resource>") whose lock is refused. */
    public static array $refused = [];

    /** @var string The lock type this factory was created for. */
    private string $type;

    /**
     * Makes this factory the site's lock factory for the rest of the test.
     *
     * @param string[] $refused Keys ("<type>/<resource>") to refuse.
     * @return void
     */
    public static function install(array $refused = []): void {
        global $CFG;

        self::$events = [];
        self::$refused = $refused;
        $CFG->lock_factory = self::class;
    }

    /**
     * Returns the recorded events for one lock type, without its prefix.
     *
     * @param string $type The lock type, e.g. 'mod_playerpuzzle'.
     * @return string[] Events as "<verb> <resource>".
     */
    public static function events_for(string $type): array {
        $prefix = ' ' . $type . '/';
        $events = [];
        foreach (self::$events as $event) {
            if (str_contains($event, $prefix)) {
                $events[] = str_replace($prefix, ' ', $event);
            }
        }
        return $events;
    }

    /**
     * Creates the factory for one lock type.
     *
     * @param string $type The lock type.
     */
    public function __construct($type) {
        $this->type = $type;
    }

    #[\Override]
    public function supports_timeout() {
        return true;
    }

    #[\Override]
    public function supports_auto_release() {
        return true;
    }

    #[\Override]
    public function is_available() {
        return true;
    }

    #[\Override]
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        $key = $this->type . '/' . $resource;
        if (in_array($key, self::$refused, true)) {
            self::$events[] = 'refused ' . $key;
            return false;
        }

        self::$events[] = 'acquire ' . $key;
        return new \core\lock\lock($key, $this);
    }

    #[\Override]
    public function release_lock(\core\lock\lock $lock) {
        self::$events[] = 'release ' . $lock->get_key();
        return true;
    }
}
