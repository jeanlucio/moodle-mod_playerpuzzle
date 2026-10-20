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

## Managing Questions

Teachers (users with the *Manage the PlayerPuzzle question bank* capability) get a **Manage
questions** page in the activity. It lists every question with its source, its status and how
many times it has been drawn, and offers:

* **Import from the Question Bank** — pick a category and import its approved, single-answer
  Multiple Choice and True/False questions. Importing again refreshes the copies; a question that
  left the category is disabled rather than deleted, because past attempts may still refer to it.
  Embedded images and files are copied along.
* **Add a question** by hand, with an optional hint.
* **Generate with AI** — see below.
* **Approve, reactivate, edit or delete** questions, one by one or in bulk.

Only **approved** questions are ever drawn in a match.

## AI Generation

When an AI source is available (the AI Hub plugin with a key, or Moodle's own `core_ai`), the
teacher can type a topic and a number of questions (up to 10). The result is shown as a
**preview** the teacher can edit, and only the questions they select are saved — as *pending*.
Nothing the AI wrote reaches a student before the teacher approves it. See
[Third-party Service Disclosure](#third-party) for what is sent.

## Hints

A question can carry a hint. It is shown only to a student who spends a Hint consumable while that
question is open, and a teacher can turn hints off for the whole activity.

## Minimum Questions Per Match

The activity settings let a teacher require a minimum number of questions to be answered before a
match can end in victory (3 by default). The Lobby shows the requirement before the match starts,
a counter shows the progress during it, and the boss revives at half HP if it falls too early —
see [How Combat Works](#combat). The count is kept by the server on every answer, never reported
by the client.
