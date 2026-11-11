# ♿ Accessibility

## The Design

The game is drawn with ordinary HTML elements on a stage that scales to the page, not on a canvas, so
there is no opaque drawing for assistive technology to miss. The pieces, scenery and effects are
hidden from screen readers, and the game is operated through real, labelled controls instead of
an afterthought bolt-on layer:

* A real `<table role="grid">` lies over the 8×8 board, and its cells are the same ones the mouse
  and touch act on. It is navigable cell by cell with the arrow keys, and each cell is read with its
  piece name. The focus outline shows only for keyboard users. **Enter** selects the piece on the focused
  cell (announced, and marked with `aria-selected`), and Enter on a neighbouring cell swaps the two; Enter on
  the same piece drops the selection. The number keys below play a move without walking the grid.
* The game area is a labelled region (the activity's name), not an application region, so a screen reader keeps
  its normal browse mode around the board and the buttons.
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
  and restored to whatever triggered it when it closes. When it opens, the focus goes to the question, so
  the screen reader starts from what is being asked and the answers come next, by Tab. The picked answer is
  exposed as pressed (`aria-pressed`). Pressing Attack keeps the focus where it is until the answer is judged,
  and then a separate Continue button, new to the page, takes it: the keyboard is never thrown out to the page,
  and the screen reader meets Continue as a new control instead of an old one that changed. Everything outside an open modal dialog is inert and so
  is not heard, so while a dialog is open the announcements go to a live region inside it.
* The Lobby, shop, ranking and report are ordinary Moodle pages and forms, so they follow the
  theme's own accessibility behaviour.

## Emoji and sound

* Emoji in the game's texts are kept out of the accessibility tree (each one is wrapped in an
  `aria-hidden` span) and removed from what is announced or spoken, so a button reads "Attack!" and not
  "crossed swords Attack!".
* The music is off until the student turns it on, and the sound effects are on: a browser cannot tell whether a
  screen reader is running, and a looping song under its voice is the worse default. A student who already
  chose keeps the choice. Both have buttons in the game (with their state) and switches in the Lobby.

## Spoken Narration

The announcements can also be spoken through the browser's Web Speech API, with a **Speak game
announcements aloud** switch in the Lobby settings. It is off by default, so it never competes with
the screen reader a student is already running, and the choice is remembered per user.

## Testing

A Behat feature reads the announcements and plays through the keyboard using only Moodle's
standard steps. It proves the mechanism; checking with a real screen reader (NVDA, JAWS,
VoiceOver) is still worthwhile for timing and pronunciation, which no test framework captures.
