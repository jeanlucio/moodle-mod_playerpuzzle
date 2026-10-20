# 🎯 Question Engine

## Source

Each activity has its own question bank, filled from three sources on the *Manage questions*
page:

* **Moodle's Question Bank** (`core_question`) — the teacher imports an existing category, and
  any single-answer Multiple Choice or True/False question in it is copied into the activity.
  A teacher who already maintains a question bank for Quiz can reuse it immediately, with no
  content migration. Importing again refreshes the copies.
* **Manual entry** — questions written directly in the activity.
* **AI generation** — optional, always reviewed by the teacher before anything is saved, and
  saved as pending until approved. See [Third-party Service Disclosure](#third-party).

Only approved questions are ever drawn in a match.

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
