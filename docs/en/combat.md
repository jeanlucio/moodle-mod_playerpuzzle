# ⚔️ How Combat Works

Combat runs on an 8×8 Match-3 board (Phaser 3), portrait-first (540×960), adapting to a
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
| 🧪 Potion | Heals that side's own HP — scales the same way Sword damage does, at half the rate of a same-size Sword match |
| 🪙 Coin | Adds to that side's own gold count for the match — see the combo formula and net compensation in [PlayerHUD integration](#playerhud) |

## The Question Challenge

When either side's Question Orb bar reaches 100%, the match pauses and opens the
[question dialog](#questions):

* **Correct answer** — a critical hit: triple base damage, multiplied by whatever Star bonus that
  side has stacked up.
* **Wrong answer** — that side takes damage, and its Star multiplier resets to 1.

This is the moment the plugin ties Match-3 skill to actual content knowledge: matching pieces
well fills the bar and builds up the multiplier, but the real payoff only lands if the student
also answers correctly.

## Server-Side Truth

Every number that governs combat — the boss's and student's maximum HP, and the base damage
figure Sword/Star/Spellbook/Potion all scale from — is computed once on the server for the
current level and phase (see [Game Modes](#game-modes) for how these scale) and handed to the
client as configuration; the client never recalculates any of it. Damage the client reports back
is clamped server-side against that same phase-scaled boss HP before it is accepted — see
[Security & Anti-Cheat](#security).
