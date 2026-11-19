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
questions** page in the activity. It lists every question with its type, source, phase, status
and how many times it has been drawn, and offers:

* **Import from the Question Bank** — a button beside *Add question* opens the import panel; pick a category and import its approved, single-answer
  Multiple Choice and True/False questions. Importing again refreshes the copies; a question that
  left the category is disabled rather than deleted, because past attempts may still refer to it.
  Embedded images and files are copied along. The category list shows how many questions each
  one would import, leaving out the ones the import skips (other question types, several correct
  choices, none).
* **Add a question** by hand, with an optional hint.
* **Generate with AI** — see below.
* **Approve, reactivate, edit or delete** questions, one by one or in bulk.
* **Set the level and the phase** a question is meant for, one by one in the question's form or for
  the selected questions at once — see below.

Only **approved** questions are ever drawn in a match. A question already answered in a phase is not asked
again in that phase while the pool still has another; once all have been asked they come around again.

**Finding and sorting.** The list can be filtered by level (in a Campaign), by phase (including *No
level set* and *No phase set*) and by status (approved, pending, disabled), and sorted by most recent,
level and phase, status, most drawn, type or source. The filters apply as soon as they change and
travel with every action, so approving, editing or setting a level or a phase on a filtered list brings
the teacher back to the same list. The bulk actions (approve, set level, set phase, delete) appear in a
bar once questions are selected.

## Questions by Level and Phase

In a Campaign, each question can be meant for a **level** and for a **phase** of a level (every level
has 10). The two are independent and start as *Any*, so the teacher only fills in what they want to
use:

| The teacher sets | What for | Example |
|---|---|---|
| The level only | Content of its own for each level: a unit, a chapter | Level 2 asks about chapter 2 |
| The phase only | Rising difficulty inside every level | The easy ones open the level, the hard ones close it |
| Both | A question for one exact point of the Campaign | The question of level 3's boss |

A phase is played with the questions whose level and phase both match it or are *Any*. When none
does, the game draws from every approved question, so a phase nobody prepared is never left without
questions. A Single Match and the Demo have no levels or phases and always draw from every approved
question. A bank re-import never changes a question's level or phase.

The levels offered go up to the activity's number of levels. If the teacher lowers that number later,
the questions meant for a level that no longer exists are never drawn, and the question list says how
many there are.

## AI Generation

When an AI source is available (the AI Hub plugin with a key, or Moodle's own `core_ai`), the
teacher can type a topic, a number of questions (up to 10) and their type: *Multiple choice and
True/False*, *Multiple choice* only or *True/False* only. In a Campaign they can also choose the level
and the phase the questions are saved for. The result is shown as a **preview** the teacher can edit,
and only the questions they select are saved — as *pending*.
Nothing the AI wrote reaches a student before the teacher approves it. See
[Third-party Service Disclosure](#third-party) for what is sent.

## Hints

A question can carry a hint. It is shown only to a student who spends a Hint consumable while that
question is open, and a teacher can turn hints off for the whole activity.

## Minimum Questions Per Match

The activity settings let a teacher require a minimum number of questions to be answered before a
match can end in victory (3 by default). The Lobby shows the requirement before the match starts,
a counter shows the progress during it, and the opponent stays on its last HP if it falls too early —
see [How Combat Works](#combat). The count is kept by the server on every answer, never reported
by the client.
