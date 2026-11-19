# ⚔️ How Combat Works

Combat runs on an 8×8 Match-3 board, portrait-first (540×960), adapting to a
landscape layout on wider screens.

## Turn Structure

**Who moves first.** Every phase opens with an **opening question**, in the same window as a Question
Challenge. Answer it right and you move first; miss it, skip it, or let it fail and the opponent does.
It changes nothing else in the fight (no damage, no Star multiplier lost), and an answer counts towards the
minimum of questions like any other. A Demo has no opening question, and an activity with no question in its
bank starts straight away with the student's turn.

1. The student makes a move on the board.
2. Combos and cascades keep the student's turn going.
3. Once the board settles, it becomes the **Opponent's turn**: a simple AI scans the board and
   executes one valid swap. Whatever it matches resolves through the exact same seven pieces and
   effects as the student's own turn (see below) — the opponent can deal damage, build up a
   multiplier, hex the student, shield itself, heal, and bank gold, exactly like the student
   does on their own turn.

## The Seven Pieces

Every effect is symmetric: a piece does the same thing no matter which side is playing it — only
who benefits changes (whoever's turn it currently is).

| Piece | Effect |
|-------|--------|
| ⭐ Star | Adds +0.1 to a damage multiplier, stacking across turns; consumed on that side's critical hit and reset to 1 when that side answers a question wrong |
| 📖 Grimoire | Fills a 0–100 hex meter of its own (+10 per piece); on reaching 100, arms 3 damage rounds against the **opponent** (one per their own turn) and resets, carrying over any overshoot |
| ❓ Mana Orb | Fills a 100-point energy bar of its own for each side; reaching 100 pauses combat and opens the [Question Challenge](#questions) — the student answers for real; whether the opponent's "answer" is right is decided on the server, with a chance set by the difficulty (see below) |
| ⚔️ Swords | Direct damage to the **opponent** — no question needed. Scales with level/phase, and a 4- or 5-piece match deals 1.5x/2x the damage of a 3-piece match |
| 🛡️ Shield | Fills a 0–100 meter of its own (+10 per piece); on reaching 100, negates the **next** hit that side takes in full, no matter how large, then resets |
| 🧪 Potion | Heals that side's own HP by a quarter of the base damage per piece, so a 3-piece match heals three quarters of what a 3-piece Sword match deals — damage always outweighs healing |
| 🪙 Coin | Adds to that side's own gold count for the match — see the combo formula and net compensation in [PlayerHUD integration](#playerhud) |

**Status effects on the fighters.** While a Hex is on a side, a purple haze with bubbles rising from it
surrounds that fighter and a label beside it shows the rounds left ("Hex 3"); while a block is armed, a
golden bubble surrounds the fighter and a label says "Shield". The labels sit on the side that faces the
other fighter and are as large as the room allows, so they read on a phone. Both effects are announced to
screen readers when they start and when they end, and the animation stops for players who ask for reduced
motion.

## The Question Challenge

When either side's Mana Orb bar reaches 100%, the match pauses and opens the
[question dialog](#questions):

* **Correct answer** — a critical hit: triple base damage, multiplied by whatever Star bonus that
  side has stacked up.
* **Wrong answer** — no damage to either side, but that side's Star multiplier resets to 1.

The opponent "answers" too when its own Mana Orb bar fills, but it never sees the right answer: the
server decides whether the opponent guessed correctly, with a probability that depends on the
difficulty (see below).

This is the moment the plugin ties Match-3 skill to actual content knowledge: matching pieces
well fills the bar and builds up the multiplier, but the real payoff only lands if the student
also answers correctly.

## Minimum Questions and the Opponent Holding On

The teacher can require a minimum number of answered questions per match (3 by default). The
Lobby tells the student before the match starts, and a counter shows the progress during it. If
the opponent would fall before that number is reached, it stays on its last 1 HP instead of dying, and
every Orb piece the student matches fills the whole mana bar, so a single Orb combo asks the next
question. Once the minimum is met, the next hit defeats it. A strong hit therefore costs nothing, and a
match can never be won without actually engaging with the questions.

## Difficulty

The student chooses Easy, Normal or Hard. The choice changes the fight, never the grade:

| | Easy | Normal | Hard |
|---|:---:|:---:|:---:|
| Opponent HP | ×0.5 | ×1 (final boss +15%) | ×3 |
| Opponent's base hit | ×0.5 | ×1 | ×2 |
| Growth of its hit over the Campaign | none | up to +60% | up to +100% |
| Opponent armor | its own only | its own +25 points over the Campaign | its own +25 points |
| Student's hit | ×1 | ×1 | ×2 |
| Student's HP in the fight | ×1 | ×1 | ×2.5 |
| Critical hit (a right answer) | 3× the hit of whoever answered, times their Star multiplier | the same | the same |
| Coins per 3-coin match | ×0.5 | ×1 | ×2 |
| Opponent answers right (Multiple Choice / True-False) | 33% / 50% | 66% / 75% | 100% / 100% |
| How the opponent plays | the first Sword match it finds | weighs the pieces (Sword and Potion count most) | as on Normal, and it also values more the pieces that feed its powers (the wolf, the Orb) |
| Opponent's items (Potion, Sword) | none | 1 per level after the first, +1 on the final boss | 1 more than on Normal |
| Opponent's powers | yes | yes | yes |
| Grade | the same | the same | the same |

The hero is the same on every difficulty: its HP, attack and armor come only from what is bought in the shop, and
the shop shows those values. On Hard it fights with 2.5× that HP. As an example, an activity with the default values (a
base HP of 100 on both sides, a base damage of 10) and 2 levels, with the student's HP before any upgrade. The
opponent's HP and both sides' hits grow with every phase; the student's HP only grows with what they buy in the shop:

| Phase | | Opponent HP | Its hit | Its critical | Its armor | Student HP | Student's hit | Student's critical |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Level 1, Phase 1 (slime) | Easy | 50 | 5 | 15 | 0% | 100 | 10 | 30 |
| | Normal | 100 | 10 | 30 | 0% | 100 | 10 | 30 |
| | Hard | 300 | 20 | 60 | 0% | 250 | 20 | 60 |
| Level 1, Phase 6 (ghost) | Easy | 75 | 8 | 24 | 0% | 100 | 15 | 45 |
| | Normal | 150 | 17 | 52 | 7% | 100 | 15 | 45 |
| | Hard | 450 | 38 | 114 | 7% | 250 | 30 | 90 |
| Level 2, Phase 10 (vampire, final boss) | Easy | 144 | 12 | 36 | 15% | 100 | 24 | 72 |
| | Normal | 331 | 38 | 115 | 40% | 100 | 24 | 72 |
| | Hard | 864 | 96 | 288 | 40% | 250 | 48 | 144 |

The criticals are shown with a Star multiplier of ×1, before the other side's armor; the student's hits are before
the hero's attack and the opponent's armor. On the Hard final boss, one of the opponent's criticals (288) is more than
the student's starting HP (250): that is what the shop and the hero's upgrades have to cover.

On Hard the student needs 1.5 times as many hits as on Normal to bring the opponent down, since its HP triples
while their own hit doubles; on Easy, half as many. On Hard the opponent's hit is 2.2 to 2.5 times the Normal one
while the student's HP grows 2.5×, so the student survives about as many hits as on Normal.

On Easy the halving is on the opponent only: its HP and its hits are halved while the student's own
hits, Potions and hex keep their full value, so an Easy match is shorter and not only safer. On Hard both sides'
hits double, the opponent's HP triples and the student's grows 2.5×: a match lasts about as long as a Normal one,
and what makes it harder is the opponent itself (it answers every question right, its hits grow over the Campaign
and it has more items), not hits that bring down a hero at full HP.
On Normal the final boss also has 15% more HP, which puts it a little above what a hero with every upgrade can
reach.

The hero grows over a Campaign (HP, attack and armor, all bought in the shop) and the opponents' powers stay the
same, so from Normal up the opponent's hits also grow with the Campaign's progress, from nothing at the first phase
to 60% more at the last on Normal and 100% more on Hard; Easy does not grow. Their armor grows too: every
opponent, on Normal and Hard, absorbs 25 points more of each hit at the last phase than at the first, on top of the
armor it already had (the golem and the plant start at 15% and end at 40%, the others go from nothing to 25%), so a
hero that has put its buys in armor meets a defense that answers it. On Hard this keeps the fight
demanding to the end, and the Potions, Swords and Shields from the shop weigh far more there than on Normal.

The activity's values change the difficulty too, as much as the student's choice. The opponent's base HP over the
base damage decides how long a fight lasts, and long fights favour the opponent, above all on Hard, where it answers
every question right and gathers Stars for more turns. The student's base HP over the base damage decides how many
hits they can take. In simulated matches of a 3-level Campaign on Hard, with a student who answers 90% of the
questions right and a base damage of 10:

| Opponent's base HP | Student's base HP | Tries per phase |
|:---:|:---:|:---:|
| 100 | 100 (the default) | 2.9 to 3.6 |
| 100 | 250 | 1.1 |
| 250 | 250 | 3.8 to 5.1 |

Raising only the student's HP makes the game much easier; raising both makes the fight 2.5 times longer, and on Hard
that makes it harder than with both at 100. On Normal the same three rows come to 2.1 to 2.3, 1.1 and 1.5
to 1.8.

In Campaign the choice is made for each phase: in the Lobby for the first one, and on the
"Phase won" screen for the next.

## The Loadout Shop and Consumables

Consumables are bought **before** a match, in the Lobby shop, with PuzzleCoin, and used from the
combat panel during the fight. Each use comes out of the student's stock and is limited per phase
or match:

| Consumable | Effect | Price | Uses per phase |
|---|---|:---:|:---:|
| 🧪 Potion | Heals 1.25× the base damage | 8 | 3 |
| 🛡️ Shield | Arms the block of the next hit at once, without spending what the Shield meter already had; cannot be used while a block is armed | 10 | 1 |
| 🔮 Quick Magic | Arms three hex rounds on the opponent at once, without spending what the Grimoire meter already had | 12 | 1 |
| ⚔️ Sword | An extra attack of one base damage, without ending the turn | 10 | 3 |
| 💡 Hint | Shows the hint of the open question, when it has one | 5 | no fixed limit |

Prices and limits are fixed, the same for everyone, and the shop shows each item's limit on its own card. During the
fight, the number on each item is how many can still be used in the phase: the stock, up to the phase's limit. After
a defeat, the **Shop** button on the end screen goes straight to the Lobby's shop, to stock up before the next try. A student's first match normally starts with an
empty loadout: PuzzleCoin is earned by winning, or transferred in from PlayerHUD. Coins are collected on the board with the Coin
piece — see [PlayerHUD Integration](#playerhud) for how they are netted against the opponent's and
banked. A teacher can switch hints off for the whole activity.

## Saved Progress

While a fight is on, the board, the HP and the meters are saved to the server every few seconds
and when the page is left. Reloading, or coming back later, rebuilds the fight from the server's
own record of the moves rather than from whatever the browser says.

## Server-Side Truth

Every number that governs combat — the opponent's and student's maximum HP, and the base damage
figure Sword/Star/Grimoire/Potion all scale from — is computed once on the server for the
current level and phase (see [Game Modes](#game-modes) for how these scale) and handed to the
client as configuration; the client never recalculates any of it. The server also re-plays the
moves the student made to work out the real outcome — see [Security & Anti-Cheat](#security).
