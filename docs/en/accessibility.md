# ♿ Accessibility

## The Design

The game is drawn with ordinary HTML elements on a stage that scales to the page, not on a canvas, so
there is no opaque drawing for assistive technology to miss. The pieces, scenery and effects are
hidden from screen readers, and the game is operated through real, labelled controls instead of
an afterthought bolt-on layer:

* A real `<table role="grid">` lies over the 8×8 board, and its cells are the same ones the mouse
  and touch act on. It is navigable cell by cell with the arrow keys, and each cell is read with its
  piece name. The focus outline shows only for keyboard users. The arrow keys only move the focus: a
  move is made with the pointer, or with the number keys below.
* The buttons (give up, help, music, sound effects, full screen, use item) are real `<button>`
  elements with accessible names; music, sound effects and full screen also say whether they are on
  or off (`aria-pressed`). The history log and the tutorial balloon are not read aloud, so the
  live region below stays the one channel.
* At the start of the student's turn, the available moves are announced through an
  `aria-live="polite"` region, which also carries the other turn-by-turn events — damage dealt and
  taken, energy bar full, a question answered, the opponent reviving, a phase advanced.
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
