# 📖 Usage

## For Teachers

1. Add a PlayerPuzzle activity to a course.
2. Choose a **Game Mode** — Campaign or Single Match — see [Game Modes](#game-modes) — and the
   opponent, its HP and damage, and how many levels a Campaign has.
3. Open **Manage questions** to fill the activity's question bank: import a Question Bank
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

1. Open the activity to reach the **Lobby**: your progress (Campaign), the difficulty, the
   minimum-questions notice and your PuzzleCoin balance.
2. Use **Play Demo** for a guided practice fight that never counts for anything.
3. Open the **Shop** to buy consumables with PuzzleCoin before the match, and — when the teacher
   connected PlayerHUD — to transfer coins in. **Settings** holds music, sound effects and spoken
   narration, and **Ranking** shows the class standings.
4. Select **Play Game** to start (or resume) a match.
5. Match pieces to attack the opponent, fill the Mana Orb bar to trigger a
   [Question Challenge](#questions), and answer correctly for a critical hit.
6. After a phase or match, review the questions you answered. In Campaign mode, winning a phase
   never costs a new attempt — only losing does.
