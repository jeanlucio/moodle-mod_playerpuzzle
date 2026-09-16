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
 * AI question generation for mod_playerpuzzle's own question bank.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

use context;
use moodle_exception;

/**
 * Routes generation to the AI Hub (local_aihub) for BYOK keys, falling back to the Moodle
 * core_ai subsystem when the hub has no key or is not installed — same ladder as
 * mod_playerwords\local\ai_word_generator, which this class otherwise mirrors closely.
 *
 * The AI response is untrusted input: every generated question is validated and shaped
 * before it ever reaches the caller, whether that is the initial generation (parse_and_
 * validate()) or a teacher-edited preview resubmitted for saving (validate_shaped_question())
 * — a malformed or half-broken entry is silently dropped and counted, never persisted.
 */
class ai_question_generator {
    /** @var int Ceiling on how many questions a single generation request may ask for. */
    private const MAX_COUNT = 10;

    /**
     * Returns true when an AI source (hub key or core_ai) is available.
     *
     * local_aihub is a site-wide BYOK service with no per-course scoping of its own, so
     * only the core_ai path needs the activity's context to honour a course/module-level
     * "Enable AI tools" override.
     *
     * @param context $context Context of the activity checking availability.
     * @return bool
     */
    public static function has_key(context $context): bool {
        if (class_exists(\local_aihub\ai::class) && \local_aihub\ai::is_available()) {
            return true;
        }
        return self::has_core_ai($context);
    }

    /**
     * Generates a preview batch of questions. Never writes to the database — the caller
     * (the web service) is responsible for letting the teacher review the result before
     * any of it is saved via questions_repository::add_question().
     *
     * @param string $topic Subject area or theme for the AI prompt.
     * @param int $count How many questions to request (clamped to self::MAX_COUNT).
     * @param context $context Context of the activity requesting generation.
     * @return array List of ['qtype' => string, 'questiontext' => string, 'hint' => string,
     *  'answers' => [['text' => string, 'iscorrect' => bool], ...]]. May be shorter than
     *  $count when the AI returned fewer valid questions than asked for.
     * @throws moodle_exception If no AI source is available or the call itself fails.
     */
    public static function generate(string $topic, int $count, context $context): array {
        $count = max(1, min(self::MAX_COUNT, $count));
        $language = get_string('thislanguage', 'langconfig');
        $description = get_string('aiusage', 'mod_playerpuzzle', $topic);

        $prompt = self::build_prompt($topic, $language, $count);
        $result = self::call_ai($prompt, $description, $context);

        if (empty($result['success'])) {
            throw new moodle_exception('error_aigenerate', 'mod_playerpuzzle');
        }

        return self::parse_and_validate((string) ($result['data'] ?? ''), $count);
    }

    /**
     * Re-validates one question the teacher's browser resubmits for saving, in the same
     * ['qtype' => ..., 'questiontext' => ..., 'hint' => ..., 'answers' => [...]] shape
     * generate() returns to the client — the client-side preview may have let the teacher
     * edit text, so this is a full re-check, never a trust-on-resubmit shortcut (the same
     * discipline local_playergames' own AI quiz save step applies to its resubmitted data).
     *
     * @param array $question Submitted question data.
     * @return array|null The validated/shaped question, or null if it must be rejected.
     */
    public static function validate_shaped_question(array $question): ?array {
        $qtype = (string) ($question['qtype'] ?? '');
        if (!in_array($qtype, questions_repository::QTYPES, true)) {
            return null;
        }

        $questiontext = trim(strip_tags((string) ($question['questiontext'] ?? '')));
        if ($questiontext === '') {
            return null;
        }

        $answers = is_array($question['answers'] ?? null) ? $question['answers'] : [];
        $shapedanswers = [];
        $correctcount = 0;
        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $text = trim(strip_tags((string) ($answer['text'] ?? '')));
            if ($text === '') {
                continue;
            }
            $iscorrect = !empty($answer['iscorrect']);
            if ($iscorrect) {
                $correctcount++;
            }
            $shapedanswers[] = ['text' => $text, 'iscorrect' => $iscorrect];
        }

        // The game only ever grades a single correct choice — see question_fetcher.php —
        // so a question with zero or more than one correct answer can never be played
        // correctly and must be rejected here rather than saved half-broken. The upper bound
        // matches question_form.php's fixed slot count: the AI is asked for at most that many
        // options, but nothing stops it from ignoring the prompt, and a question saved with
        // more would be silently truncated the moment a teacher opens it in that form.
        if (
            $correctcount !== 1
            || count($shapedanswers) < 2
            || count($shapedanswers) > questions_repository::MAX_MULTICHOICE_ANSWERS
        ) {
            return null;
        }

        return [
            'qtype' => $qtype,
            'questiontext' => $questiontext,
            'hint' => trim(strip_tags((string) ($question['hint'] ?? ''))),
            'answers' => $shapedanswers,
        ];
    }

    /**
     * Returns true when the Moodle core_ai subsystem has a text-generation provider
     * available and, on Moodle versions that support it, not disabled for this context.
     *
     * @param context $context Context of the activity checking availability.
     * @return bool
     */
    protected static function has_core_ai(context $context): bool {
        if (
            !class_exists(\core_ai\manager::class)
            || !class_exists(\core_ai\aiactions\generate_text::class)
        ) {
            return false;
        }

        try {
            $actionclass = \core_ai\aiactions\generate_text::class;
            $manager = \core\di::get(\core_ai\manager::class);
            $providers = $manager->get_providers_for_actions([$actionclass], true);
            if (empty($providers[$actionclass])) {
                return false;
            }
            return self::action_enabled_in_context($manager, $context, $actionclass);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Checks the per-course/per-module "Enable AI tools" override, when the running Moodle
     * version supports it.
     *
     * core_ai\manager::is_action_enabled_in_context() was added to Moodle core after 4.5
     * (course.enableaitools / course_modules.enableaitools do not exist on 4.5, so the method
     * itself is undefined there). This plugin supports 4.5+5.x, so the check is skipped —
     * never blocking — on versions where the method does not exist, exactly like every other
     * class_exists()/method_exists() guarded integration in this codebase.
     *
     * @param \core_ai\manager $manager The AI manager instance.
     * @param context $context Context of the activity requesting generation.
     * @param string $actionclass Fully qualified AI action class name.
     * @return bool
     */
    protected static function action_enabled_in_context(
        \core_ai\manager $manager,
        context $context,
        string $actionclass
    ): bool {
        if (!method_exists($manager, 'is_action_enabled_in_context')) {
            return true;
        }
        return $manager->is_action_enabled_in_context($context, $actionclass);
    }

    /**
     * Generates text via the Moodle core_ai subsystem (institutional fallback).
     *
     * @param string $prompt The prompt text.
     * @param context $context Context of the activity requesting generation.
     * @return array Result with keys: success (bool), data (string), message (string).
     */
    protected static function call_core_ai(string $prompt, context $context): array {
        global $USER;

        try {
            $actionclass = \core_ai\aiactions\generate_text::class;
            $manager = \core\di::get(\core_ai\manager::class);
            $providers = $manager->get_providers_for_actions([$actionclass], true);

            if (empty($providers[$actionclass]) || !self::action_enabled_in_context($manager, $context, $actionclass)) {
                return ['success' => false, 'message' => '', 'data' => ''];
            }

            $action = new \core_ai\aiactions\generate_text(
                contextid: $context->id,
                userid: (int) $USER->id,
                prompttext: $prompt,
            );

            $response = $manager->process_action($action);

            if (!$response->get_success()) {
                return ['success' => false, 'message' => 'core_ai: provider returned failure', 'data' => ''];
            }

            $data = $response->get_response_data();
            return ['success' => true, 'message' => '', 'data' => (string) ($data['generatedcontent'] ?? '')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => ''];
        }
    }

    /**
     * Resolves an AI source and generates content.
     *
     * Routes to the AI Hub (local_aihub) first, which resolves personal then site BYOK
     * keys. Falls back to the Moodle core_ai subsystem when the hub returns no result or
     * is not installed.
     *
     * @param string $prompt The full prompt text.
     * @param string $description Short label of what is being generated, for the hub usage log.
     * @param context $context Context of the activity requesting generation.
     * @return array Result with keys: success (bool), data (string), message (string).
     */
    protected static function call_ai(string $prompt, string $description, context $context): array {
        $lasterror = ['success' => false, 'message' => '', 'data' => ''];

        if (class_exists(\local_aihub\ai::class)) {
            $result = \local_aihub\ai::generate_text('', $prompt, true, 'mod_playerpuzzle', $description);
            if (!empty($result['success'])) {
                return $result;
            }
            // Preserve a real failure (e.g. an invalid key) so it is not masked as "no source".
            if (!empty($result['message'])) {
                $lasterror = $result;
            }
        }

        if (self::has_core_ai($context)) {
            $result = self::call_core_ai($prompt, $context);
            if ($result['success'] || !empty($result['message'])) {
                return $result;
            }
        }

        return $lasterror;
    }

    /**
     * Builds the prompt asking the AI for a batch of multichoice/truefalse questions.
     *
     * @param string $topic Subject area or theme.
     * @param string $language Target language name.
     * @param int $count Number of questions to request.
     * @return string The constructed prompt.
     */
    protected static function build_prompt(string $topic, string $language, int $count): string {
        $langname = $language !== '' ? $language : 'English';
        $example = '{"questions":['
            . '{"type":"multichoice","question":"...","hint":"...",'
            . '"options":[{"text":"...","correct":true},{"text":"...","correct":false}]},'
            . '{"type":"truefalse","question":"...","hint":"...","answer":true}'
            . ']}';

        $parts = [
            "You are generating quiz questions for an educational game about the topic: \"{$topic}\".",
            "Generate {$count} questions. Write all text in language: {$langname}.",
            'Use only these two types: "multichoice" and "truefalse".',
            'For "multichoice": 2 to 5 short options, with exactly one marked "correct": true.',
            'For "truefalse": "answer" is a JSON boolean, true or false.',
            'For every question, "hint" is one short clue sentence that helps without giving away'
                . ' the answer; use an empty string if no good hint applies.',
            'IMPORTANT: Reply ONLY with a valid JSON object in this exact format, no code fences:',
            $example,
        ];

        return implode("\n", $parts);
    }

    /**
     * Parses and validates the AI's raw response text into shaped, storable questions.
     *
     * @param string $responsetext Raw text returned by the AI provider.
     * @param int $limit Never return more than this many questions.
     * @return array See generate()'s return shape.
     */
    protected static function parse_and_validate(string $responsetext, int $limit): array {
        $cleaned = preg_replace('/^\x60\x60\x60(?:json)?\s*/im', '', $responsetext);
        $cleaned = preg_replace('/\x60\x60\x60\s*$/m', '', $cleaned);
        $cleaned = trim((string) $cleaned);

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded) || empty($decoded['questions']) || !is_array($decoded['questions'])) {
            return [];
        }

        $shaped = [];
        foreach ($decoded['questions'] as $entry) {
            if (count($shaped) >= $limit) {
                break;
            }
            $question = self::shape_ai_question($entry);
            if ($question !== null) {
                $shaped[] = $question;
            }
        }

        return $shaped;
    }

    /**
     * Shapes and validates one entry from the AI's raw JSON into the common question
     * array shape, or returns null when the entry is malformed.
     *
     * @param mixed $entry One decoded "questions" array entry.
     * @return array|null
     */
    private static function shape_ai_question(mixed $entry): ?array {
        if (!is_array($entry)) {
            return null;
        }

        $qtype = (string) ($entry['type'] ?? '');
        $questiontext = trim(strip_tags((string) ($entry['question'] ?? '')));
        if ($questiontext === '') {
            return null;
        }
        $hint = trim(strip_tags((string) ($entry['hint'] ?? '')));

        if ($qtype === 'truefalse') {
            if (!array_key_exists('answer', $entry)) {
                return null;
            }
            $answer = (bool) $entry['answer'];
            return [
                'qtype' => 'truefalse',
                'questiontext' => $questiontext,
                'hint' => $hint,
                'answers' => [
                    ['text' => get_string('true', 'qtype_truefalse'), 'iscorrect' => $answer],
                    ['text' => get_string('false', 'qtype_truefalse'), 'iscorrect' => !$answer],
                ],
            ];
        }

        if ($qtype !== 'multichoice') {
            return null;
        }

        $options = $entry['options'] ?? null;
        if (!is_array($options)) {
            return null;
        }

        return self::validate_shaped_question([
            'qtype' => 'multichoice',
            'questiontext' => $questiontext,
            'hint' => $hint,
            'answers' => array_map(
                static fn($option): array => [
                    'text' => (string) ($option['text'] ?? ''),
                    'iscorrect' => !empty($option['correct']),
                ],
                array_filter($options, 'is_array')
            ),
        ]);
    }
}
