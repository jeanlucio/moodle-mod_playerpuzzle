# ⚔️ How Combat Works

Combat runs on an 8×8 Match-3 board (Phaser 3), portrait-first (540×960), adapting to a
landscape layout on wider screens.

## Turn Structure

1. The student makes a move on the board.
2. Combos and cascades keep the student's turn going.
3. Once the board settles, it becomes the **Boss's turn**: a simple AI scans the board, executes
   one valid swap, and whatever it matches resolves the same way a student match would (for
   example, matching Mana Orbs charges the boss's own Question Challenge; the AI does not
   currently use every piece type available to the student — see [Features](#features)).

## The Seven Pieces

| Piece | Effect |
|-------|--------|
| ⭐ Star | Adds +0.1 to a damage multiplier, stacking across turns |
| 📖 Spellbook | Applies 3 stacks of poison to the boss (5 damage per stack, ticking at the start of the boss's own turn) |
| 🔮 Mana Orb | Fills a 100-point energy bar (tracked separately for student and boss) |
| ⚔️ Swords | Deals direct damage to the boss — no question needed |
| 🛡️ Shield | Blocks the next points of incoming damage, absorbed before HP |
| 🧪 Potion | Heals the student's HP |
| 🪙 Coin | Adds to the match's gold count, banked to the student's permanent [PlayerHUD](#playerhud) balance when the match ends |

## The Question Challenge

When either side's Mana Orb bar reaches 100%, the match pauses and opens the
[question dialog](#questions):

* **Correct answer** — a critical hit: triple base damage, multiplied by whatever Star bonus the
  student has stacked up.
* **Wrong answer** — the student takes damage, and the Star multiplier resets to 1.

This is the moment the plugin ties Match-3 skill to actual content knowledge: matching pieces
well fills the bar and builds up the multiplier, but the real payoff only lands if the student
also answers correctly.

## Server-Side Truth

Every number shown during combat — the boss's and student's maximum HP for the current level and
phase — is computed once on the server (see [Game Modes](#game-modes) for how HP scales) and
handed to the client as configuration; the client never recalculates it. Damage the client
reports back is clamped server-side against that same phase-scaled boss HP before it is accepted
— see [Security & Anti-Cheat](#security).
