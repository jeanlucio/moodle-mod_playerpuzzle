# 🎮 Game Modes

The teacher picks one mode per activity instance.

## Campaign

A continuous run through up to 10 **levels**, each split into 10 **phases**. Winning a phase
never costs a new attempt — only a defeat does. **Maximum attempts** is the number of attempts in
each Campaign.

* **Playing again:** a Campaign ends when its last phase is won or its attempts run out (it keeps
  the progress it reached). The student may then start another Campaign at Level 1, Phase 1, up to
  **Maximum Campaigns** (1 to 10, or unlimited, the default). A new Campaign starts with the
  student's PuzzleCoin and consumables back to zero, so the Lobby asks them to confirm it first. Once a Campaign
  has ended the Lobby no longer shows the balance or the shop, and the next Campaign's hero choice comes marked
  with the hero of the one that ended.
* **Resuming:** closing the browser mid-campaign and coming back resumes the exact level and
  phase the student was on. After a defeat, the next attempt resumes on that same level and phase
  rather than starting over.
* **Opponent scaling:** the opponent's maximum HP grows with the current level and phase, using a
  fixed linear formula computed once on the server:

  | | Formula |
  |---|---|
  | Opponent HP | `base × (1 + 0.5 × (level − 1) + 0.1 × (phase − 1))` |

  The opponent's damage scales the same way; the coin reward does not (see [Combat](#combat)). The
  student does not grow by itself: they keep up by buying upgrades for their hero (see
  [Heroes](#heroes)), and the student's HP starts from the activity's **Base student HP**.
* **Advancing:** after winning a phase, the server checks that the reported fight really cleared
  that phase's opponent HP, rotates the token, and the student continues to the next phase. The same
  request cannot be replayed to skip several phases off one victory.
* **Difficulty per phase:** the student picks a difficulty in the Lobby for the first phase and
  again on each "Phase complete" screen for the next one.
* **Normal grows with the Campaign:** on Normal, the opponents hit harder the further into the Campaign
  the phase is, from their usual strength at the first phase to 60% more at the last, so the Campaign keeps
  pace with a hero that keeps growing. Easy and Hard do not change.

## Heroes

In a Campaign the student chooses one of six heroes on a screen of its own, the first one of the
activity, and keeps it to the end of that Campaign; a new Campaign asks again. In a Single Match or a
Demo the hero is only how the student looks, and a Single Match opens the choice with the hero last used.

* **Specialty:** each hero is a specialist in HP (the mages), attack (the warriors) or armor (the
  archers), and starts a little ahead in it, a twentieth of what that attribute can reach.
* **Upgrades:** after each phase, and from the Lobby, the student spends PuzzleCoin on upgrades of HP,
  attack or armor (12 coins each). What they buy counts from the next phase.
* **Stages and evolution:** the hero has four looks. A stage caps how many upgrades of each attribute can
  be bought, a quarter of the Campaign's total for each stage (the total grows with the number of
  levels). At 25%, 50% and 75% of the Campaign the hero evolves for free, as soon as its specialty is
  at the stage's cap, which opens the next stage's cap.
* **The shop:** the same panel, in the Lobby and on the screen between phases. A folded line names the next
  opponent (the **opponent's card**) and opens to its picture and what sets it apart: extra HP, harder hits,
  armor, its powers, the final boss's answer to the hero and, once the phase exists, the items it brings.
  Below it come the hero's attributes, each with its value, how many upgrades it has and a buy button (an
  attribute at the stage's cap says **Full**), a short list of what the next evolution still needs (the
  specialty full and the milestone phase won), and the consumables. During the fight, the **?** button at
  the top of the stage opens the same card, with a table that puts your hero and the opponent side by side
  (HP, attack and armor), so the powers can be read again after the first meeting, when the game's notice no
  longer shows.
* **Giving up:** the flag button at the top of the stage asks for confirmation and then ends the match as a
  defeat: one attempt is spent and the coins earned in that phase are lost. A Demo just ends.

## Single Match

A single, self-contained round — no levels or phases, always fought at the configured base HP.
The teacher sets how many matches a student may play (1 to 10, or unlimited) and may add a
**cooldown** (in minutes, hours or days) between matches. The student chooses the difficulty in the
Lobby for each match.

## Grading

The grade is always scaled by the activity's **Maximum Grade** (points, or no grade — scales are
not supported), never by a fixed 100, and never changes with the difficulty chosen.

**Campaign** — each Campaign scores on its own:

```
Campaign score = (furthest phase won ÷ total phases configured) × Maximum Grade
```

"Furthest phase won" is the highest level/phase the student reached with a victory in that
Campaign, across all of its attempts — not a count of won attempts, because one continuous attempt
can cover dozens of phases. The teacher's **Grading method** combines the Campaigns: highest,
average, first, last, or average over all required Campaigns. The first Campaign counts from the
start; each later one only once it ends, so starting another never lowers the grade.

**Single Match** — each match scores either the Maximum Grade (a win) or zero (a loss), and the
teacher's **Grading method** combines the matches the same way (in the average over all required
matches, an unplayed match counts as zero).

**Consider errors** — available once the activity requires a minimum number of questions. In
Campaign, the progress rate is averaged with the share of questions answered correctly; in Single
Match, a win is weighted by that match's own accuracy.

The grade is sent to the gradebook after every phase or match, so it is never stuck waiting for the
end of a campaign, and recomputed for everyone when a grading setting changes.

## Why Two Modes Instead of One

A Campaign "attempt" and a Single Match "attempt" are structurally different — a continuous run
across many phases versus independent, repeatable rounds — so the plugin models them separately
rather than forcing one grading and attempt-counting scheme to fit both.
