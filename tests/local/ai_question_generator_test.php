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
 * Unit tests for ai_question_generator's untrusted-input handling.
 *
 * @package    mod_playerpuzzle
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_playerpuzzle\local;

/**
 * Tests for ai_question_generator — no database or network access needed.
 *
 * The AI provider always returns raw, untrusted text. These tests exercise
 * parse_and_validate() (response-shape parsing), validate_shaped_question() (the safety
 * gate applied both to a freshly parsed AI entry and to a teacher-edited preview
 * resubmitted for saving) and build_prompt() directly — the first and last are
 * intentionally kept protected (internal parsing helpers, not part of the class's public
 * contract), invoked here via reflection, mirroring mod_playerwords\local\
 * ai_word_generator_test's own pattern for the same kind of class.
 *
 * @covers \mod_playerpuzzle\local\ai_question_generator
 */
final class ai_question_generator_test extends \basic_testcase {
    /**
     * Invokes the protected static parse_and_validate() method.
     *
     * @param string $responsetext Raw AI response text.
     * @param int $limit Max questions to return.
     * @return array
     */
    private function parse_and_validate(string $responsetext, int $limit = 10): array {
        $method = new \ReflectionMethod(ai_question_generator::class, 'parse_and_validate');
        $method->setAccessible(true);
        return $method->invoke(null, $responsetext, $limit);
    }

    /**
     * Invokes the protected static build_prompt() method.
     *
     * @param string $topic Subject area or theme.
     * @param string $language Target language name.
     * @param int $count Number of questions to request.
     * @return string
     */
    private function build_prompt(string $topic, string $language, int $count): string {
        $method = new \ReflectionMethod(ai_question_generator::class, 'build_prompt');
        $method->setAccessible(true);
        return $method->invoke(null, $topic, $language, $count);
    }

    /**
     * A well-formed multichoice and truefalse entry are both shaped correctly.
     *
     * @return void
     */
    public function test_parse_and_validate_shapes_multichoice_and_truefalse(): void {
        $response = json_encode(['questions' => [
            [
                'type' => 'multichoice',
                'question' => 'Capital of France?',
                'hint' => 'Think Eiffel Tower.',
                'options' => [
                    ['text' => 'Paris', 'correct' => true],
                    ['text' => 'Lyon', 'correct' => false],
                ],
            ],
            [
                'type' => 'truefalse',
                'question' => 'The sky is blue.',
                'hint' => '',
                'answer' => true,
            ],
        ]]);

        $questions = $this->parse_and_validate($response);

        $this->assertCount(2, $questions);
        $this->assertSame('multichoice', $questions[0]['qtype']);
        $this->assertSame('Capital of France?', $questions[0]['questiontext']);
        $this->assertSame('Think Eiffel Tower.', $questions[0]['hint']);
        $this->assertCount(2, $questions[0]['answers']);
        $this->assertTrue($questions[0]['answers'][0]['iscorrect']);
        $this->assertFalse($questions[0]['answers'][1]['iscorrect']);

        $this->assertSame('truefalse', $questions[1]['qtype']);
        $this->assertCount(2, $questions[1]['answers']);
        $this->assertTrue($questions[1]['answers'][0]['iscorrect']);
        $this->assertFalse($questions[1]['answers'][1]['iscorrect']);
    }

    /**
     * Malformed JSON never throws; it degrades to an empty result.
     *
     * @return void
     */
    public function test_parse_and_validate_malformed_json_returns_empty(): void {
        $this->assertSame([], $this->parse_and_validate('not valid json at all {{{'));
    }

    /**
     * A syntactically valid JSON value missing the "questions" wrapper is rejected.
     *
     * @return void
     */
    public function test_parse_and_validate_missing_wrapper_returns_empty(): void {
        $this->assertSame([], $this->parse_and_validate('{"unexpectedkey":"value"}'));
        $this->assertSame([], $this->parse_and_validate('"just a string"'));
        $this->assertSame([], $this->parse_and_validate('[]'));
    }

    /**
     * Markdown code fences around the JSON payload are stripped before decoding.
     *
     * @return void
     */
    public function test_parse_and_validate_strips_markdown_code_fence(): void {
        $fence = str_repeat(chr(96), 3);
        $body = json_encode(['questions' => [
            ['type' => 'truefalse', 'question' => 'Q?', 'hint' => '', 'answer' => false],
        ]]);
        $response = $fence . "json\n" . $body . "\n" . $fence;

        $questions = $this->parse_and_validate($response);

        $this->assertCount(1, $questions);
    }

    /**
     * A multichoice entry with no correct answer, or more than one, is dropped — the
     * game only ever grades a single correct choice.
     *
     * @return void
     */
    public function test_parse_and_validate_drops_multichoice_with_wrong_correct_count(): void {
        $response = json_encode(['questions' => [
            [
                'type' => 'multichoice',
                'question' => 'No correct one',
                'options' => [
                    ['text' => 'A', 'correct' => false],
                    ['text' => 'B', 'correct' => false],
                ],
            ],
            [
                'type' => 'multichoice',
                'question' => 'Two correct ones',
                'options' => [
                    ['text' => 'A', 'correct' => true],
                    ['text' => 'B', 'correct' => true],
                ],
            ],
        ]]);

        $this->assertSame([], $this->parse_and_validate($response));
    }

    /**
     * A multichoice entry with fewer than two usable options is dropped.
     *
     * @return void
     */
    public function test_parse_and_validate_drops_multichoice_with_too_few_options(): void {
        $response = json_encode(['questions' => [
            [
                'type' => 'multichoice',
                'question' => 'Only one option',
                'options' => [
                    ['text' => 'A', 'correct' => true],
                ],
            ],
        ]]);

        $this->assertSame([], $this->parse_and_validate($response));
    }

    /**
     * A truefalse entry missing the "answer" key is dropped rather than defaulting to
     * either side silently.
     *
     * @return void
     */
    public function test_parse_and_validate_drops_truefalse_missing_answer(): void {
        $response = json_encode(['questions' => [
            ['type' => 'truefalse', 'question' => 'Q?', 'hint' => ''],
        ]]);

        $this->assertSame([], $this->parse_and_validate($response));
    }

    /**
     * An entry with an unsupported/unknown type is dropped.
     *
     * @return void
     */
    public function test_parse_and_validate_drops_unknown_qtype(): void {
        $response = json_encode(['questions' => [
            ['type' => 'shortanswer', 'question' => 'Q?', 'answer' => 'x'],
        ]]);

        $this->assertSame([], $this->parse_and_validate($response));
    }

    /**
     * An entry with an empty question prompt is dropped.
     *
     * @return void
     */
    public function test_parse_and_validate_drops_empty_questiontext(): void {
        $response = json_encode(['questions' => [
            ['type' => 'truefalse', 'question' => '   ', 'answer' => true],
        ]]);

        $this->assertSame([], $this->parse_and_validate($response));
    }

    /**
     * A non-array entry inside the "questions" list is skipped rather than crashing.
     *
     * @return void
     */
    public function test_parse_and_validate_skips_non_array_entries(): void {
        $response = json_encode(['questions' => [
            'not an object',
            ['type' => 'truefalse', 'question' => 'Q?', 'answer' => true],
        ]]);

        $questions = $this->parse_and_validate($response);

        $this->assertCount(1, $questions);
    }

    /**
     * More valid entries than the requested limit are truncated, never returning more
     * than asked for.
     *
     * @return void
     */
    public function test_parse_and_validate_respects_limit(): void {
        $entries = [];
        for ($i = 0; $i < 5; $i++) {
            $entries[] = ['type' => 'truefalse', 'question' => "Q{$i}?", 'answer' => true];
        }
        $response = json_encode(['questions' => $entries]);

        $questions = $this->parse_and_validate($response, 2);

        $this->assertCount(2, $questions);
    }

    /**
     * HTML tags embedded in the AI's text are stripped rather than stored — AI output
     * is untrusted input, and generated questions are always stored as plain text
     * (no file support for AI-generated content, unlike the manual/bank sources).
     *
     * @return void
     */
    public function test_parse_and_validate_strips_html_from_text(): void {
        $response = json_encode(['questions' => [
            [
                'type' => 'truefalse',
                'question' => '<script>alert(1)</script>Is this safe?',
                'hint' => '<b>bold</b> hint',
                'answer' => true,
            ],
        ]]);

        $questions = $this->parse_and_validate($response);

        $this->assertSame('alert(1)Is this safe?', $questions[0]['questiontext']);
        $this->assertSame('bold hint', $questions[0]['hint']);
    }

    /**
     * A valid multichoice question, in the shape generate() returns to the client, is
     * accepted for saving.
     *
     * @return void
     */
    public function test_validate_shaped_question_accepts_valid_multichoice(): void {
        $result = ai_question_generator::validate_shaped_question([
            'qtype' => 'multichoice',
            'questiontext' => 'Capital of France?',
            'hint' => 'Eiffel Tower',
            'answers' => [
                ['text' => 'Paris', 'iscorrect' => true],
                ['text' => 'Lyon', 'iscorrect' => false],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('multichoice', $result['qtype']);
        $this->assertCount(2, $result['answers']);
    }

    /**
     * An unknown qtype is rejected.
     *
     * @return void
     */
    public function test_validate_shaped_question_rejects_unknown_qtype(): void {
        $this->assertNull(ai_question_generator::validate_shaped_question([
            'qtype' => 'essay',
            'questiontext' => 'Q?',
            'answers' => [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => false],
            ],
        ]));
    }

    /**
     * Zero and more than one correct answer are both rejected — the game only ever
     * grades a single correct choice.
     *
     * @return void
     */
    public function test_validate_shaped_question_rejects_wrong_correct_count(): void {
        $zerocorrect = ai_question_generator::validate_shaped_question([
            'qtype' => 'multichoice',
            'questiontext' => 'Q?',
            'answers' => [
                ['text' => 'A', 'iscorrect' => false],
                ['text' => 'B', 'iscorrect' => false],
            ],
        ]);
        $twocorrect = ai_question_generator::validate_shaped_question([
            'qtype' => 'multichoice',
            'questiontext' => 'Q?',
            'answers' => [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => 'B', 'iscorrect' => true],
            ],
        ]);

        $this->assertNull($zerocorrect);
        $this->assertNull($twocorrect);
    }

    /**
     * More options than questions_repository::MAX_MULTICHOICE_ANSWERS are rejected — the
     * manual editor's fixed slots cannot display more, so saving one anyway would silently
     * truncate it the first time a teacher opened and re-saved it there.
     *
     * @return void
     */
    public function test_validate_shaped_question_rejects_too_many_options(): void {
        $answers = [];
        for ($i = 0; $i < questions_repository::MAX_MULTICHOICE_ANSWERS + 1; $i++) {
            $answers[] = ['text' => 'Option ' . $i, 'iscorrect' => $i === 0];
        }

        $result = ai_question_generator::validate_shaped_question([
            'qtype' => 'multichoice',
            'questiontext' => 'Q?',
            'answers' => $answers,
        ]);

        $this->assertNull($result);
    }

    /**
     * An answer with blank text does not count toward the minimum of two — a teacher
     * clearing an option's text in the preview before saving should not leave it as an
     * empty, unplayable option.
     *
     * @return void
     */
    public function test_validate_shaped_question_drops_blank_answer_text(): void {
        $result = ai_question_generator::validate_shaped_question([
            'qtype' => 'multichoice',
            'questiontext' => 'Q?',
            'answers' => [
                ['text' => 'A', 'iscorrect' => true],
                ['text' => '   ', 'iscorrect' => false],
            ],
        ]);

        $this->assertNull($result);
    }

    /**
     * An empty question prompt is rejected.
     *
     * @return void
     */
    public function test_validate_shaped_question_rejects_empty_questiontext(): void {
        $this->assertNull(ai_question_generator::validate_shaped_question([
            'qtype' => 'truefalse',
            'questiontext' => '',
            'answers' => [
                ['text' => 'True', 'iscorrect' => true],
                ['text' => 'False', 'iscorrect' => false],
            ],
        ]));
    }

    /**
     * The prompt requests the given topic, count and language, and instructs the AI to
     * use only the two supported question types.
     *
     * @return void
     */
    public function test_build_prompt_includes_topic_count_and_types(): void {
        $prompt = $this->build_prompt('astronomy', 'English', 5);

        $this->assertStringContainsString('astronomy', $prompt);
        $this->assertStringContainsString('5 questions', $prompt);
        $this->assertStringContainsString('multichoice', $prompt);
        $this->assertStringContainsString('truefalse', $prompt);
    }
}
