# ✨ Features

## ⚔️ Gameplay

* **Match-3 turn-based combat:** an 8×8 board (Phaser) where the student swaps pieces to attack a
  boss. Seven piece types, each with its own effect — see [How Combat Works](#combat).
* **Symmetric boss AI:** every piece resolves exactly the same way on the boss's turn as on the
  student's — the boss deals damage, stacks a critical-hit multiplier, poisons, shields itself,
  heals and banks its own gold.
* **Question challenges:** filling the Mana Orb meter opens a question; a correct answer is a
  critical hit, a wrong one costs HP. See [Question Engine](#questions).
* **Two game modes:** **Campaign** (up to 10 levels × 10 phases, where winning never costs an
  attempt) and **Single Match** (a self-contained, repeatable round). See
  [Game Modes](#game-modes).
* **Three difficulties,** chosen by the student, that never change the grade: Easy, Normal and
  Hard scale the boss, the coin reward and how often the boss answers correctly.
* **Minimum questions per match:** the boss revives at half HP if it falls before the student has
  answered the required number of questions.
* **Resume anywhere:** the board and the fight are saved while playing, so closing the tab or
  reloading picks the fight up where it was.
* **Demo match:** a guided practice fight, always available from the Lobby, that never counts for
  grade, coins or attempts.
* **Review after the match:** every question answered, with the student's answer and the correct
  one.

## 🏛️ Lobby and economy

* **Lobby as a home screen:** progress, difficulty, the minimum-questions notice and **Play**, with
  buttons for the **Shop**, the **Ranking** and **Settings**.
* **PuzzleCoin:** the plugin's own persistent currency, earned by winning phases and matches.
* **Loadout shop:** before a match, the student spends PuzzleCoin on consumables — Potion, Shield,
  Quick Magic, Sword and Question Hint — and uses them during the fight, within a fixed limit per
  phase. See [Combat](#combat).
* **Optional PlayerHUD link:** with [block_playerhud](#playerhud) in the course, students can
  transfer PlayerHUD coins into PuzzleCoin, a retry can cost a PlayerHUD item, and a victory can
  award one. Without it, everything above still works.
* **Class ranking:** an optional leaderboard, independent of the grade, that respects separate
  groups.

## 📚 Questions

* **Three sources per activity:** import from Moodle's Question Bank, write questions by hand, or
  generate them with AI. Multiple choice and True/False.
* **Teacher review:** AI-generated questions are shown as a preview first and saved as pending;
  none is ever drawn in a match before it is approved. Bulk approve and delete.
* **Hints:** an optional hint per question, usable through the Hint consumable.

## 📊 Grading and teacher tools

* **Gradebook:** a real grade item, scaled by the configured Maximum Grade (points, or no grade),
  with a pass grade and an optional "consider errors" variant. See [Game Modes](#game-modes).
* **Custom completion:** require a number of attempts and/or wins.
* **Teacher report:** per-student progress, average boss damage and accuracy, the most missed
  questions, the score distribution and the completion rate, respecting separate groups.
* **Backup and restore,** including duplicating the activity, with attempts, the loadout stock and
  logs carried across.

## 🛡️ Integrity

* **Blind JSON:** the correct answer never reaches the browser before the server judges it.
* **Server replay:** the server re-plays the student's logged moves from a seed it issued and
  works out the real outcome, instead of trusting what the client reports.
* **Single-use tokens and atomic locks** against replayed or parallel requests.

See [Security & Anti-Cheat](#security).

## ♿ Accessibility

A parallel, always-present HTML layer for the board (a hidden grid with arrow-key navigation,
number-key moves, and `aria-live` turn announcements), a native `<dialog>` for questions, and an
optional spoken narration. See [Accessibility](#accessibility).

## 🔐 Privacy and quality

* **Privacy provider:** metadata, export and deletion for every table that holds personal data,
  plus the sound and narration preferences.
* **Automated tests:** PHPUnit, headless JavaScript unit tests and Behat, green across the CI
  matrix (Moodle 4.5 → 5.3). See [Automated Tests](#testing).

## ⏳ Not in this release

* 🥊 **Dispute Mode (V2):** a head-to-head mode on a shared board. It depends on a real-time
  transport that is not available yet, so it is designed but not built.
* ⏱️ **Time limit per match:** removed from the form because nothing enforced it. If it comes
  back, it will be designed to be checked on the server.
* 💲 **Shop prices that mirror a PlayerHUD trade:** prices are fixed today (see
  [Combat](#combat)); following a trade configured in PlayerHUD is a possible future step.

<p class="page-hint">The plugin has not been published on the Moodle Plugin Directory yet.
Everything listed above works today and is covered by the automated test suite.</p>
