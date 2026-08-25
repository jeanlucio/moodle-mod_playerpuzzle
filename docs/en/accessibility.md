# ♿ Accessibility

## The Design

Match-3 gameplay lives on a Canvas, which is opaque to screen readers by design. PlayerPuzzle's
accessibility approach is a parallel, always-present HTML layer rather than an afterthought bolt-
on:

* A visually-hidden `<table role="grid">` mirrors the board state, navigable cell by cell.
* Turn-by-turn events (available moves, damage dealt, boss HP remaining, energy bar full, a
  question answered) are announced through an `aria-live="polite"` region, so a screen reader
  user gets the same information a sighted player reads from the HUD.
* Number keys `1`–`9` execute the correspondingly-announced move; `Space` re-reads the list of
  available moves.
* `aria-live="assertive"` is reserved for events that interrupt the turn (e.g. taking damage from
  a poison tick).

## What's Implemented Today

* ✅ **The question dialog** — the moment gameplay most needs to be accessible — is a native
  HTML `<dialog>`, not a Canvas overlay. Opening it traps focus and centers it using the
  browser's own behavior; closing it restores focus to whatever element triggered it.

## What's Planned

* ⏳ The parallel HTML board (`<table role="grid">`), the `aria-live` turn announcements, and the
  number-key input scheme described above are designed but not built yet.
* ⏳ A phase-advance announcement ("You advanced to Level 5, Phase 2") once the post-match
  debrief screen exists — see [Features](#features).

`speechSynthesis` is never used as the default announcement channel, to avoid conflicting with
whatever screen reader the student already has running.
