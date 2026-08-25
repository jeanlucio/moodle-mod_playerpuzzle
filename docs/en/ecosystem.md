# 🕹️ Player Ecosystem

PlayerPuzzle is one activity in a broader family of gamification plugins for Moodle. None of
them are required — PlayerPuzzle works standalone — but installing the ones below unlocks
integrations documented throughout this page.

* **PlayerHUD Block:** XP, levels, inventory, drops, quests, RPG classes and ranking inside each
  course. PlayerPuzzle reads/writes to it for its entire optional economy — see
  [PlayerHUD Integration](#playerhud).
  👉 [github.com/jeanlucio/moodle-block_playerhud](https://github.com/jeanlucio/moodle-block_playerhud)

* **PlayerWords:** A sibling activity module (word-guessing) that established the
  `grademethod`/attempt-counting pattern PlayerPuzzle's Single Match mode reuses.
  👉 [github.com/jeanlucio/moodle-mod_playerwords](https://github.com/jeanlucio/moodle-mod_playerwords)

* **PlayerCross:** A sibling activity module (crossword) sharing the same architectural patterns
  (`local\*_page_service` entry-point classes, PlayerHUD integration conventions).
  👉 [github.com/jeanlucio/moodle-mod_playercross](https://github.com/jeanlucio/moodle-mod_playercross)

* **PlayerLand:** Another Phaser-based activity module in the ecosystem, sharing the same
  dynamic-script-loading pattern for its own bundled game engine.
  👉 [github.com/jeanlucio/moodle-mod_playerland](https://github.com/jeanlucio/moodle-mod_playerland)

* **AI Hub:** Shared BYOK (bring your own key) broker for AI features across the ecosystem.
  PlayerPuzzle's planned question-generation feature will consume it as a soft dependency,
  falling back to Moodle's own `core_ai` — see [Features](#features).
  👉 [github.com/jeanlucio/moodle-local_aihub](https://github.com/jeanlucio/moodle-local_aihub)

* **PlayerGames:** Central hub of a second, broader gamification ecosystem (site-wide XP,
  seasons, daily mini-games) — a separate initiative from the PlayerHUD family above.
  👉 [github.com/jeanlucio/moodle-local_playergames](https://github.com/jeanlucio/moodle-local_playergames)
