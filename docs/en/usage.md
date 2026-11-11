# 📖 Usage

## For Teachers

1. Add a PlayerPuzzle activity to a course.
2. Choose a **Game Mode** — Campaign or Single Match — see [Game Modes](#game-modes) — and the
   opponent, its HP and damage, and how many levels a Campaign has.
3. Open **Manage questions** (in the activity's navigation bar, under **More** when it does not fit) to fill the activity's question bank: import a Question Bank
   category, add questions by hand, or generate them with AI (pending questions need your
   approval before they are drawn). See [Question Engine](#questions).
4. Optionally set a **Minimum Questions per Match**, whether wrong answers should count against
   the grade, a **cooldown** between matches (Single Match), and whether hints are allowed.
5. Set the **Grade** (points, or no grade), a pass grade and, for Single Match, the **Grading
   method**. Both formulas scale to the Maximum Grade, never to a fixed `100`.
6. Optionally connect [PlayerHUD](#playerhud) items — coin transfer, retry cost, victory award —
   if `block_playerhud` is installed in the course.
7. Decide whether the class **Ranking** is shown, and use the activity's **Report** page to follow
   progress, the most missed questions and the score distribution.

## For Students

1. Open the activity to reach the **Lobby**: your progress (Campaign), the difficulty and the
   minimum-questions notice. The difficulty is a button: press it to step through Easy, Normal and
   Hard (a line under it says what each changes). Once a phase is under way (a move, an
   answer or an item used) it is locked until its "Phase won" screen; before that, you can still change it. In a Campaign, the Campaign button opens your **Campaign history**:
   a line for each Campaign with its hero, the phases won, the attempts used and its points.
2. Use **Play Demo** for a guided practice fight that never counts for anything, and **How to play** to read
   the rules: it is also behind the **?** button during a fight, while the button with the dragon icon opens the
   opponent's card.
3. Open the **Shop**, which shows your PuzzleCoin balance, to buy consumables before the match, and —
   when the teacher connected PlayerHUD — to transfer coins in. **Settings** holds music (off until you turn it on), sound effects and spoken
   narration (also off), and **Ranking** shows the class standings.
4. Select **Play Game** to start (or resume) a match.
5. Match pieces to attack the opponent, fill the Mana Orb bar to trigger a
   [Question Challenge](#questions), and answer correctly for a critical hit. With a mouse or touch, press a
   piece and then a neighbour, or drag one. **With the keyboard**, Tab into the board, move between squares
   with the arrow keys, press **Enter** to select a piece and **Enter** on a neighbouring square to swap
   the two (Enter on the same piece drops the selection), or press **1** to **9** to play the move the game
   announced, and **Space** to hear the list again. See [Accessibility](#accessibility).
6. After a phase or match, review the questions you answered. In Campaign mode, winning a phase
   never costs a new attempt — only losing does.
