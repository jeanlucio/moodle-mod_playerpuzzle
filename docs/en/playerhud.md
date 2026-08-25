# 💰 PlayerHUD Integration

PlayerPuzzle has **no permanent economy of its own**. Coins and upgrade levels live entirely in
[block_playerhud](#ecosystem), and PlayerPuzzle only reads/writes to it when the block is
present in the course — the plugin works standalone with zero configuration if it isn't.

## Teacher-Picked Items, Not Auto-Detection

For each course, the teacher explicitly picks which of the block's own items represent:

* **Coins** — credited when a match is won (the gold collected during combat is banked here).
* **Sword level** — read as an upgrade tier for the student's damage.
* **Shield level** — read as an upgrade tier for the student's defense.
* **Potion** — optional; can also be consumed from a permanent PlayerHUD stock instead of (or
  alongside) the session-local coin purchase.
* **Retry cost** — optional; charged starting from the student's 2nd attempt, without ever
  raising the configured attempt limit. Intended to be earned through reinforcement content
  outside the game (a video, another activity), not through playing PlayerPuzzle itself — so a
  student who struggles most isn't also the one least able to afford another try.
* **Win grant** — optional; a fixed item awarded on every victory, on top of the coins.

Every one of these is a plain dropdown of the block's own configured items — the same pattern
already used by the sibling `mod_playerwords`/`mod_playercross` activities — so adding this
integration never required a change to `block_playerhud` itself.

## Coin Gain and the Boss's Compensation

Matching the Coin piece (see [How Combat Works](#combat)) earns coins on a combo curve: a
3-piece match earns the configured "Coin gain" base value, a 4-piece match earns 1.5x that, and a
5-piece match earns 2x — the same curve Sword damage uses. Unlike every other combat value, Coin
gain deliberately does **not** scale by level or phase, so a teacher can set fixed consumable
prices (Potion, Shield, Quick Magic, Hint) that stay balanced for the whole campaign instead of
getting proportionally cheaper as it progresses.

The boss also banks its own Coin total when it matches the piece on its own turn — it has no shop
and never spends it. Its only purpose is to net against the student's balance at the end of the
match: the student's final coin count is `max(0, student's coins − boss's coins)`, banked via
PlayerHUD only on victory. This gives the boss's AI a reason to "compete" for the same piece the
student wants, instead of it being inert whenever the AI happens to match it.

## What's Actually Wired Up Today

* ✅ Crediting coins on victory.
* ✅ Reading the student's sword/shield upgrade level (`hud_service::get_upgrade_level()`).
* ⏳ Applying that upgrade level as an actual damage/defense multiplier during combat — the
  reader exists and is tested; `combat.js` doesn't consume it yet.
* ⏳ Potion/retry-cost/win-grant items — configurable today, not yet consumed by gameplay.

See [Features](#features) for the full implemented/planned breakdown.
