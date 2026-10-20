# ♿ Accessibility

## The Design

Match-3 gameplay lives on a Canvas, which is opaque to screen readers by design. PlayerPuzzle's
accessibility approach is a parallel, always-present HTML layer rather than an afterthought bolt-on:

* A visually-hidden `<table role="grid">` mirrors the 8×8 board, navigable cell by cell with the
  arrow keys; each cell is read with its piece name.
* At the start of the student's turn, the available moves are announced through an
  `aria-live="polite"` region, which also carries the other turn-by-turn events — damage dealt and
  taken, energy bar full, a question answered, the boss reviving, a phase advanced.
* Number keys `1`–`9` execute the correspondingly-announced move, and `Space` re-reads the list of
  available moves.
* The question challenge is a native HTML `<dialog>`: focus is trapped and centred by the browser,
  and restored to whatever triggered it when it closes.
* The Lobby, shop, ranking and report are ordinary Moodle pages and forms, so they follow the
  theme's own accessibility behaviour.

## Spoken Narration

The announcements can also be spoken through the browser's Web Speech API, with a **Speak game
announcements aloud** switch in the Lobby settings. It is off by default, so it never competes with
the screen reader a student is already running, and the choice is remembered per user.

## Testing

A Behat feature reads the announcements and plays through the keyboard using only Moodle's
standard steps. It proves the mechanism; checking with a real screen reader (NVDA, JAWS,
VoiceOver) is still worthwhile for timing and pronunciation, which no test framework captures.
