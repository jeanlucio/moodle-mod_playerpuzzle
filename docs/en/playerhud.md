# 💰 PlayerHUD Integration

PlayerPuzzle has its own persistent currency, **PuzzleCoin**, so it works fully without anything
else installed. [block_playerhud](#ecosystem) is an **optional** companion: when it is in the
course, the teacher can connect three things to it. The plugin only reads or writes to the block
when one of them is configured.

## PuzzleCoin

Coins are collected on the board with the Coin piece. A 3-piece match earns the configured **Coin
gain** (10 by default), a 4-piece match 1.5× that, and a 5-piece match 2× — the same curve Sword
damage uses. Unlike every other combat value, coin gain deliberately does **not** scale by level
or phase, so the fixed shop prices stay balanced for the whole campaign.

The boss banks its own coins when it matches the Coin piece on its turn. It has no shop and never
spends them; they only net against the student's: the amount banked is
`max(0, student's coins − boss's coins)`, and only on a **victory** (every phase won, in
Campaign). A defeat banks nothing, and a Demo match never does. The result is added to the
student's PuzzleCoin balance, spent in the [loadout shop](#combat).

## What the Teacher Can Connect

Each of these is a plain dropdown of the block's own configured items — the same pattern the
sibling `mod_playerwords`/`mod_playercross` activities use — so the integration never required a
change to `block_playerhud` itself.

* **Item representing coins** — lets a student *transfer* units of this item into PuzzleCoin from
  the Lobby, choosing how many (1:1). Typically PlayerHUD's own PlayerCoin, but any item works.
  This is the only way PlayerHUD coins reach PuzzleCoin: victories are no longer credited to
  PlayerHUD automatically.
* **Retry cost** — optional; an item (and quantity) charged from the student's 2nd attempt, never
  raising the configured attempt limit. It is meant to be earned through reinforcement content
  outside the game, so that the student who struggles most is not the one least able to afford
  another try. A student who is only resuming a fight is never charged.
* **Win grant** — optional; a fixed item awarded on every victory, on top of the coins. When the
  relevant attempt limit is unlimited the item is still granted but without its XP, to prevent XP
  farming.

If `block_playerhud` is missing, or older than the version this integration needs (v1.7.1), the
form says so and the three options are simply not offered.
