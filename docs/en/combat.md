# ⚔️ How Combat Works

Combat runs on an 8×8 Match-3 board (Phaser 4), portrait-first (540×960), adapting to a
landscape layout on wider screens.

## Turn Structure

1. The student makes a move on the board.
2. Combos and cascades keep the student's turn going.
3. Once the board settles, it becomes the **Boss's turn**: a simple AI scans the board and
   executes one valid swap. Whatever it matches resolves through the exact same seven pieces and
   effects as the student's own turn (see below) — the boss can deal damage, build up a
   multiplier, poison the student, shield itself, heal, and bank gold, exactly like the student
   does on their own turn.

## The Seven Pieces

Every effect is symmetric: a piece does the same thing no matter which side is playing it — only
who benefits changes (whoever's turn it currently is).

| Piece | Effect |
|-------|--------|
| ⭐ Star | Adds +0.1 to a damage multiplier, stacking across turns; consumed on that side's critical hit and reset to 1 when that side answers a question wrong |
| 📖 Spellbook | Fills a 0–100 poison meter of its own (+10 per piece); on reaching 100, arms 3 damage rounds against the **opponent** (one per their own turn) and resets, carrying over any overshoot |
| ❓ Question Orb | Fills a 100-point energy bar of its own for each side; reaching 100 pauses combat and opens the [Question Challenge](#questions) — the student answers for real, the boss "answers" by picking an option at random, validated the same way server-side |
| ⚔️ Swords | Direct damage to the **opponent** — no question needed. Scales with level/phase, and a 4- or 5-piece match deals 1.5x/2x the damage of a 3-piece match |
| 🛡️ Shield | Fills a 0–100 meter of its own (+10 per piece); on reaching 100, negates the **next** hit that side takes in full, no matter how large, then resets |
| 🧪 Potion | Heals that side's own HP by a quarter of the base damage per piece, so a 3-piece match heals three quarters of what a 3-piece Sword match deals — damage always outweighs healing |
| 🪙 Coin | Adds to that side's own gold count for the match — see the combo formula and net compensation in [PlayerHUD integration](#playerhud) |

## The Question Challenge

When either side's Question Orb bar reaches 100%, the match pauses and opens the
[question dialog](#questions):

* **Correct answer** — a critical hit: triple base damage, multiplied by whatever Star bonus that
  side has stacked up.
* **Wrong answer** — that side takes damage, and its Star multiplier resets to 1.

The boss "answers" too when its own Mana Orb bar fills, but it never sees the right answer: the
server decides whether the boss guessed correctly, with a probability that depends on the
difficulty (see below).

This is the moment the plugin ties Match-3 skill to actual content knowledge: matching pieces
well fills the bar and builds up the multiplier, but the real payoff only lands if the student
also answers correctly.

## Minimum Questions and the Boss's Revival

The teacher can require a minimum number of answered questions per match (3 by default). The
Lobby tells the student before the match starts, and a counter shows the progress during it. If
the boss would fall before that number is reached, it comes back with half its HP — as many times
as needed — so a match can never be won without actually engaging with the questions.

## Difficulty

The student chooses Easy, Normal or Hard. The choice changes the fight, never the grade:

| | Boss HP and damage | Coins earned | Boss answers a Multiple Choice correctly | Boss answers a True/False correctly |
|---|:---:|:---:|:---:|:---:|
| Easy | ×0.5 | ×0.5 | 33% | 50% |
| Normal | ×1 | ×1 | 66% | 75% |
| Hard | ×2 | ×3 | 100% | 100% |

In Campaign the choice is made for each phase: in the Lobby for the first one, and on the
"Phase complete" screen for the next.

## The Loadout Shop and Consumables

Consumables are bought **before** a match, in the Lobby shop, with PuzzleCoin, and used from the
combat panel during the fight. Each use comes out of the student's stock and is limited per phase
or match:

| Consumable | Effect | Price | Uses per phase |
|---|---|:---:|:---:|
| 🧪 Potion | Heals 1.25× the base damage | 8 | 3 |
| 🛡️ Shield | Fills the Shield meter at once, arming the block of the next hit | 10 | 1 |
| 🔮 Quick Magic | Fills the Grimoire meter at once, arming three poison rounds on the boss | 12 | 1 |
| ⚔️ Sword | An extra attack of one base damage, without ending the turn | 10 | 3 |
| 💡 Hint | Shows the hint of the open question, when it has one | 5 | no fixed limit |

Prices and limits are fixed, the same for everyone. A student's first match normally starts with an
empty loadout: PuzzleCoin is earned by winning, or transferred in from PlayerHUD. Coins are collected on the board with the Coin
piece — see [PlayerHUD Integration](#playerhud) for how they are netted against the boss's and
banked. A teacher can switch hints off for the whole activity.

## Saved Progress

While a fight is on, the board, the HP and the meters are saved to the server every few seconds
and when the page is left. Reloading, or coming back later, rebuilds the fight from the server's
own record of the moves rather than from whatever the browser says.

## Server-Side Truth

Every number that governs combat — the boss's and student's maximum HP, and the base damage
figure Sword/Star/Spellbook/Potion all scale from — is computed once on the server for the
current level and phase (see [Game Modes](#game-modes) for how these scale) and handed to the
client as configuration; the client never recalculates any of it. The server also re-plays the
moves the student made to work out the real outcome — see [Security & Anti-Cheat](#security).
