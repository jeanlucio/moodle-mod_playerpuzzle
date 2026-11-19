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
* **Attempts and the end of a Campaign:** with limited attempts the Lobby says which attempt the student is
  on ("Attempt 2 of 3") and warns before the last one, and so does the give-up dialog of the match. When the
  last attempt is lost the end screen says "Campaign over" instead of offering to play again, with why, the
  phases won, the grade earned (when the activity is graded and the grade is not hidden) and the position
  in the class ranking (when it is on).
* **Giving up a Campaign:** a Campaign under way can be given up from the Lobby, after a confirmation. It
  ends where it stands and is graded on the progress it reached, as when attempts run out, and the next one
  can start if the activity allows it. When it is the last Campaign the activity allows, the confirmation
  says the student will not be able to play again. The Lobby history lists it as "Gave up".
* **Cooldown between Campaigns:** the teacher may set a **cooldown** (in minutes, hours or days) that the
  student waits after a Campaign ends, however it ended (won, out of attempts or given up), before starting
  the next one. It counts from the moment the Campaign ended, so a Campaign given up long after its last
  defeat still waits the whole cooldown. Retrying the same Campaign after a defeat is never held back, and
  with only one Campaign allowed it has no effect. While it runs, the Lobby tells the student the time the
  next Campaign opens, and Play is refused before the hero choice.
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
  [Heroes](#heroes)), and the student's HP starts from the activity's **Base student hit points (HP)**.
* **Advancing:** after winning a phase, the server checks that the reported fight really cleared
  that phase's opponent HP, rotates the token, and the student continues to the next phase. The same
  request cannot be replayed to skip several phases off one victory.
* **Difficulty per phase:** the student picks a difficulty in the Lobby for the first phase and
  again on each "Phase won" screen for the next one. Leaving that screen does not close the choice: until
  the next phase has a move, an answer or an item used in it, the Lobby still offers the difficulty button. With no
  phase under way, as after a defeat, the button starts on the difficulty of the student's last match.
* **The opponent grows with the Campaign:** on Normal and Hard, the opponents hit harder the further into the
  Campaign the phase is, from their usual strength at the first phase to 60% more at the last on Normal and 100%
  more on Hard, so the Campaign keeps pace with a hero that keeps growing. Easy does not change.

## Heroes

In a Campaign the student chooses one of six heroes on a screen of its own, when pressing Play for the
first time (the activity opens at the Lobby, where the help and the shop are), and keeps it to the end of
that Campaign; a new Campaign asks again. In a Single Match or a Demo the hero is chosen when the student
presses Play, has the same specialty head start, with no upgrades to buy, and a Single Match opens the choice
with the hero last used.

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
* **Leaving or giving up:** the flag button at the top of the stage offers three choices, each explained: keep
  playing; leave and continue later, at no cost (the match is saved as it is, and Play in the Lobby continues the
  phase on the same difficulty); or give up, which ends the match as a defeat: one attempt is spent and the coins
  earned in that phase are lost. A Demo just ends. A phase left under way can also be abandoned from the Lobby,
  through the link under the locked difficulty, with the same cost as giving up inside the game.

## Opponents

A Campaign meets the same ten opponents on every level, one per phase, from the easiest to the hardest; the last
phase of the last level is fought against the final boss instead. A Single Match fights the opponent the teacher
picks in the activity settings. Each has a stage of its own, and most stand out in one way: they hit 15%
harder, their armor absorbs 15% of every hit they take, or they have 15% more HP. Each also has a **power**, which the game explains the first time the student meets it and which the
opponent's card shows again at any time:

| Phase | Opponent | Stands out | Power |
|:---:|---|---|---|
| 1 | Slime | — | Regenerates 1% of its max HP as each of its turns begins |
| 2 | Goblin | hits harder | Ambush: its Swords deal double damage on its first turn |
| 3 | Ice golem | armor | Starts the fight with its Shield armed |
| 4 | Mummy | hits harder | Curse: starts with its Grimoire 20% full, and its hex lasts 4 rounds instead of 3 |
| 5 | Carnivorous plant | armor | Root: the Potions it matches heal 50% more |
| 6 | Ghost | — | Intangible: every 10 of its turns its Shield arms by itself |
| 7 | Pirate skeleton | hits harder | Loot: every 4 of its turns it takes 10% of the student's coins from the match, and hits harder the more it took |
| 8 | Wolf | hits harder | Its question comes sooner: each Mana Orb gives it 24% mana instead of 20% |
| 9 | Giant spider | more HP | Its hex comes sooner: each Grimoire fills 15% of its meter instead of 10% |
| 10 | Dragon | hits harder | Fire breath: every 10 of its turns, before it moves, a hit of 1.5 times the base damage that a Shield blocks |
| Final boss | Vampire | 20% more HP | Drain: each of its hits heals it by 30% of the damage it deals |

The final boss also answers the student's hero with a second power borrowed from another opponent: against an
attack specialist it gets 15% armor, like the golem's, against an armor specialist the spider's fast Grimoire
(the hex goes through armor), and against an HP specialist the dragon's breath, every 12 of its turns. On
Normal and Hard the opponents also bring Potions and Swords of their own, more of them further into the
Campaign (see [Difficulty](#combat)).

## Single Match

A single, self-contained round — no levels or phases, always fought at the configured base HP, scaled
by the difficulty the student chose.
The teacher sets how many matches a student may play (1 to 10, or unlimited) and may add a
**cooldown** (in minutes, hours or days) between matches. The student chooses the difficulty in the
Lobby for each match. While a cooldown is running, the Lobby tells the student the time the next
match opens, so they learn it before pressing Play, and Play is refused before the hero choice.

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
Campaign, the progress rate is multiplied by the share of questions answered correctly across the
whole Campaign; in Single Match, a win is weighted by that match's own accuracy.

The grade is sent to the gradebook after every phase or match, so it is never stuck waiting for the
end of a campaign, and recomputed for everyone when a grading setting changes.

## Why Two Modes Instead of One

A Campaign "attempt" and a Single Match "attempt" are structurally different — a continuous run
across many phases versus independent, repeatable rounds — so the plugin models them separately
rather than forcing one grading and attempt-counting scheme to fit both.
