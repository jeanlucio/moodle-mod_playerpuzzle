# 🎯 Question Engine

## Source

PlayerPuzzle reads questions from Moodle's own **Question Bank** (`core_question`) — the
teacher picks an existing category in the activity's settings, and any Multiple Choice or
True/False question in it can be served during combat. This means a teacher who already
maintains a question bank for Quiz can point PlayerPuzzle at the same category and reuse it
immediately, with no content migration.

A second, PlayerPuzzle-owned question source (manual entry plus AI-assisted generation) is
planned to coexist alongside the real Question Bank — see [Features](#features).

## Blind JSON — the Answer Never Leaks

The question text and every answer option are sent to the browser, but **the correct answer id
is not**. `question_fetcher.php` strips it server-side before the question ever reaches the
client. When the student submits a choice, `mod_playerpuzzle_validate_answer` checks it against
the real record in the database and returns only a boolean plus (on a wrong answer) the correct
option — so nothing in the page source or network traffic ever reveals the answer in advance.
This is the first of the plugin's [three anti-cheat pillars](#security).

## Minimum Questions Per Match

The activity settings let a teacher require a minimum number of questions to be answered before
a match can end in victory, and the Lobby shows this requirement to the student before they
start. The gameplay rule that enforces it — reviving the boss at half HP if the minimum hasn't
been reached yet — is not wired up yet; see [Features](#features).
