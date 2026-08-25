# 🎮 Game Modes

The teacher picks one mode per activity instance.

## Campaign

A continuous run through up to 10 **levels**, each split into 10 **phases**. Winning a phase
never costs a new attempt — only losing (or timing out) does, and the configured **Max
Attempts** limit only counts finished (lost/timeout/abandoned) attempts, never a phase won along
the way.

* **Resuming:** closing the browser mid-campaign and coming back resumes the exact level/phase
  the student was on — the server rotates the anti-replay token and picks up the most recent
  in-progress attempt, instead of restarting at Level 1.
* **HP Scaling:** both the boss's and the student's maximum HP scale up with the current
  level/phase, using a fixed linear formula computed once server-side:

  | | Formula |
  |---|---|
  | Boss HP | `base × (1 + 0.5 × (level − 1) + 0.1 × (phase − 1))` |
  | Student HP | `base × (1 + 0.3 × (level − 1) + 0.05 × (phase − 1))` |

  The boss scales faster than the student — later phases are meant to feel harder, not just
  longer.
* **Advancing:** after winning a phase, the server verifies the reported damage actually cleared
  that phase's boss HP before allowing the advance, and rotates the token again — the same
  request can't be replayed to skip several phases off one real victory.

## Single Match

A single, self-contained round — no levels or phases. Grading reuses the `grademethod` pattern
already established by the sibling `mod_playerwords`/`mod_playercross` activities: the teacher
sets a **Max Matches** limit (or leaves it unlimited) via a `0`–`10` selector, matching the scale
of a short, repeatable round rather than a long campaign.

## Why Two Modes Instead of One

A Campaign "attempt" and a Single Match "attempt" are structurally different — a continuous run
across many phases versus independent, repeatable rounds — so the plugin models them separately
rather than forcing one grading/attempt-counting scheme to fit both. `question_fetcher.php`,
`save_progress.php` and the Lobby all read the configured `gamemode` to know which rules apply.
