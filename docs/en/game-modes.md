# 🎮 Game Modes

The teacher picks one mode per activity instance.

## Campaign

A continuous run through up to 10 **levels**, each split into 10 **phases**. Winning a phase
never costs a new attempt — only a defeat does, and the configured **Maximum attempts** limit
counts only attempts that ended in defeat, never a phase won along the way.

* **Resuming:** closing the browser mid-campaign and coming back resumes the exact level and
  phase the student was on. After a defeat, the next attempt resumes on that same level and phase
  rather than starting over.
* **HP scaling:** both the boss's and the student's maximum HP grow with the current level and
  phase, using a fixed linear formula computed once on the server:

  | | Formula |
  |---|---|
  | Boss HP | `base × (1 + 0.5 × (level − 1) + 0.1 × (phase − 1))` |
  | Student HP | `base × (1 + 0.3 × (level − 1) + 0.05 × (phase − 1))` |

  The boss scales faster than the student — later phases are meant to feel harder, not just
  longer. The boss's damage scales the same way; the coin reward does not (see
  [Combat](#combat)).
* **Advancing:** after winning a phase, the server checks that the reported fight really cleared
  that phase's boss HP, rotates the token, and the student continues to the next phase. The same
  request cannot be replayed to skip several phases off one victory.
* **Difficulty per phase:** the student picks a difficulty in the Lobby for the first phase and
  again on each "Phase complete" screen for the next one.

## Single Match

A single, self-contained round — no levels or phases, always fought at the configured base HP.
The teacher sets how many matches a student may play (1 to 10, or unlimited) and may add a
**cooldown** (in minutes, hours or days) between matches. The student chooses the difficulty in the
Lobby for each match.

## Grading

The grade is always scaled by the activity's **Maximum Grade** (points, or no grade — scales are
not supported), never by a fixed 100, and never changes with the difficulty chosen.

**Campaign**

```
grade = (furthest phase won ÷ total phases configured) × Maximum Grade
```

"Furthest phase won" is the highest level/phase the student has reached with a victory, across all
their attempts — not a count of won attempts, because one continuous attempt can cover dozens of
phases.

**Single Match** — each match scores either the Maximum Grade (a win) or zero (a loss), and the
teacher's **Grading method** combines the matches: highest, average, first, last, or average over
all required matches (an unplayed match counts as zero).

**Consider errors** — available once the activity requires a minimum number of questions. In
Campaign, the progress rate is averaged with the share of questions answered correctly; in Single
Match, a win is weighted by that match's own accuracy.

The grade is sent to the gradebook after every phase or match, so it is never stuck waiting for the
end of a campaign, and recomputed for everyone when a grading setting changes.

## Why Two Modes Instead of One

A Campaign "attempt" and a Single Match "attempt" are structurally different — a continuous run
across many phases versus independent, repeatable rounds — so the plugin models them separately
rather than forcing one grading and attempt-counting scheme to fit both.
