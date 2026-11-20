# Changelog — mod_playerpuzzle

All notable changes to this plugin are documented here.

---

## [v1.0.0] — 2026-11-20

First public release.

### Added
- A turn-based Match-3 RPG activity: the student swaps pieces on an 8×8 board to attack an opponent
  played by a simple AI, and filling the Mana Orb opens a question whose right answer is a critical hit.
  Every phase starts with a question that decides who moves first.
- Two game modes: a Campaign of up to 10 levels of 10 phases, with six heroes to choose from, eleven
  opponents with powers of their own and a final boss, and a Single Match that can be replayed.
- Three difficulties chosen by the student, which never change the grade.
- A Lobby with progress, the Campaign history, the ranking, a guided Demo match, the "How to play" help and
  a shop. PuzzleCoin earned by winning buys hero upgrades and consumables for the next match.
- Questions imported from the course's Question Bank, written by hand or generated with AI and approved by
  the teacher, per level and per phase of a Campaign, with optional hints. AI generation goes through the
  AI Hub plugin when it is installed, otherwise through Moodle's own AI subsystem.
- A grade item with a pass grade and an option to count errors, custom completion by attempts or wins,
  limits on attempts and Campaigns, cooldowns, a class ranking that respects groups and a teacher report.
- The server replays each match from the moves the student made and works out the result itself; the
  correct answer never reaches the browser before the server judges it.
- A board playable from the keyboard and with a screen reader, with optional spoken narration.
- Optional link to the PlayerHUD block: coins converted into PuzzleCoin, a retry that costs an item and an
  item as a reward. Without it, everything else works.
- Backup, restore and "Duplicate activity", course reset and the privacy API. Support for Moodle 4.5 to 5.3.
