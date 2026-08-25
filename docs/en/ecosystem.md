# 🕹️ PlayerGames Ecosystem

PlayerPuzzle is part of the **[PlayerGames](https://jeanlucio.github.io/playergames/)**
gamification ecosystem for Moodle. None of the plugins below are required — PlayerPuzzle works
standalone — but installing the ones relevant to a course unlocks the integrations documented
throughout this page.

* **PlayerGames:** Central hub of the ecosystem — site-wide XP, seasons, daily mini-games, and
  the Ecosystem Dashboard that ties every installed Player plugin together.
  👉 [github.com/jeanlucio/moodle-local_playergames](https://github.com/jeanlucio/moodle-local_playergames)

* **PlayerHUD Block:** XP, levels, inventory, drops, quests, RPG classes and ranking inside each
  course. PlayerPuzzle reads/writes to it for its entire optional economy — see
  [PlayerHUD Integration](#playerhud).
  👉 [github.com/jeanlucio/moodle-block_playerhud](https://github.com/jeanlucio/moodle-block_playerhud)

* **PlayerHUD Filter:** Enables item drops via shortcodes inside course content.
  👉 [github.com/jeanlucio/moodle-filter_playerhud](https://github.com/jeanlucio/moodle-filter_playerhud)

* **PlayerHUD Availability Restriction:** Restricts access to course activities based on the
  student's current level or collected items.
  👉 [github.com/jeanlucio/moodle-availability_playerhud](https://github.com/jeanlucio/moodle-availability_playerhud)

* **PlayerWords:** A sibling activity module (word-guessing) that established the
  `grademethod`/attempt-counting pattern PlayerPuzzle's Single Match mode reuses.
  👉 [github.com/jeanlucio/moodle-mod_playerwords](https://github.com/jeanlucio/moodle-mod_playerwords)

* **PlayerCross:** A sibling activity module (crossword) sharing the same architectural patterns
  (`local\*_page_service` entry-point classes, PlayerHUD integration conventions).
  👉 [github.com/jeanlucio/moodle-mod_playercross](https://github.com/jeanlucio/moodle-mod_playercross)

* **PlayerLand:** Another Phaser-based activity module in the ecosystem, sharing the same
  dynamic-script-loading pattern for its own bundled game engine.
  👉 [github.com/jeanlucio/moodle-mod_playerland](https://github.com/jeanlucio/moodle-mod_playerland)

* **PlayerGroup:** Lets students autonomously form their own groups directly from the activity
  page — no teacher intervention needed.
  👉 [github.com/jeanlucio/moodle-mod_playergroup](https://github.com/jeanlucio/moodle-mod_playergroup)

* **AI Hub:** Shared BYOK (bring your own key) broker for AI features across the ecosystem.
  PlayerPuzzle's planned question-generation feature will consume it as a soft dependency,
  falling back to Moodle's own `core_ai` — see [Features](#features).
  👉 [github.com/jeanlucio/moodle-local_aihub](https://github.com/jeanlucio/moodle-local_aihub)
