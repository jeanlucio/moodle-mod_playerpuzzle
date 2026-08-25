# ✨ Features

## ✅ Implemented

* ⚔️ **Match-3 Turn-Based Combat:** An 8×8 board (Phaser 3) where the student swaps pieces to
  attack a boss. Combining "mana" pieces charges a question challenge; a correct answer deals
  extra damage. See [How Combat Works](#combat).
* 🎮 **Two Game Modes:** **Campaign** (a continuous run through up to 10 levels × 10 phases each,
  where winning never costs a new attempt — only losing does) and **Single Match** (a self-
  contained round reusing the `grademethod` pattern from the PlayerGames ecosystem). See
  [Game Modes](#game-modes).
* 📈 **Linear HP Scaling:** Boss and student HP scale with the current level/phase using a fixed
  formula (`base × (1 + rate × (level−1) + rate × (phase−1))`), computed once server-side and
  never recalculated in the client.
* 🔄 **Campaign Resume:** Closing the browser mid-campaign and coming back resumes the same
  level/phase instead of restarting at Level 1 — the server rotates the anti-replay token and
  preserves progress transparently.
* 🎯 **Real Question Bank Integration:** Questions are pulled from Moodle's own Question Bank
  (`core_question`), reusing categories the teacher already has in the course — see
  [Question Engine](#questions).
* 🛡️ **Three-Pillar Anti-Cheat:** Blind JSON (the correct answer never reaches the client until
  the server validates it), server-side damage clamping against the phase-scaled boss HP, and
  single-use anti-replay tokens rotated on every state-changing call.
* 🗨️ **Accessible Question Modal:** The question challenge renders as a native HTML `<dialog>` —
  focus trapping, ESC handling and centering all come from the browser itself, with focus
  saved and restored to whatever triggered it.
* 📊 **HUD Progress Indicator:** "Level X — Phase Y of 10" shown during Campaign combat.
* 💰 **PlayerHUD Economy Integration:** Fully optional. When
  [block_playerhud](#ecosystem) is present in the course, the teacher picks which of the block's
  own items represent coins, sword level and shield level — winning a match credits coins, and a
  configurable item can be granted on every victory (with XP suppressed once the attempt limit is
  set to unlimited, to prevent farming). Without PlayerHUD, the plugin still works standalone with
  no permanent progression.
* 🏛️ **Lobby:** Shows the student's PlayerHUD balance (only the items the teacher configured),
  current Campaign progress, and the configured minimum-questions notice before the match starts.
* 🔐 **Privacy (GDPR):** Complete Privacy Provider — metadata declaration, export and deletion of
  all stored personal data.
* 🧪 **Automated Tests:** 104-case PHPUnit suite, green across the CI matrix (Moodle 4.5 → 5.2) —
  see the [Automated Tests](#testing) section.

## ⏳ In Development / Planned

* 🧮 **PlayerHUD Combat Multiplier:** `hud_service::get_upgrade_level()` already reads the
  student's sword/shield level, but `combat.js` does not yet apply it as a damage/defense
  multiplier during a match.
* 🎬 **Post-Match Debrief:** A non-automatic review screen after winning a phase — every question
  answered, correct vs. chosen, damage dealt, coins earned — with "Play Next Phase" / "Exit"
  buttons. The `advance_phase` endpoint that powers the "next phase" transition already exists;
  the screen itself does not yet.
* 🛍️ **In-Combat Consumables:** Shield, Quick Magic, Hint and Potion, purchasable mid-match with a
  session-local coin balance (Potion will also be consumable from a permanent PlayerHUD stock).
* ❤️‍🩹 **Boss Revive / Minimum Questions Enforcement:** The "minimum questions per match" setting
  already exists in the activity form and shows a notice in the Lobby, but the actual gameplay
  rule (reviving the boss at 50% HP if the minimum hasn't been reached yet) is not wired up yet.
* 🎓 **Gradebook Integration:** `grade_item_update()`/`update_grades()`, scaled by the configured
  Maximum Grade — the two grading formulas (progress-based for Campaign, `grademethod`-based for
  Single Match) are designed but not yet pushed to the Moodle gradebook.
* ✅ **Custom Completion Rules:** `FEATURE_COMPLETION_HAS_RULES` is currently declared `false`
  (honestly, rather than advertising a broken feature) until the real implementation lands.
* 💾 **Backup & Restore:** `FEATURE_BACKUP_MOODLE2` is currently `false` for the same reason.
* 📋 **Teacher Report:** A dedicated report page summarizing class performance.
* ♿ **Full Accessibility Layer:** A parallel, always-present HTML layer (a visually-hidden
  `<table role="grid">` mirroring the board, `aria-live` turn-by-turn announcements, number-key
  input `1`–`9`) for screen-reader play without touching the Canvas. The question dialog already
  follows this design; the rest of the combat loop does not yet.
* 📚 **Own Question Bank + AI Generation:** A second, PlayerPuzzle-owned question source
  (manual entry or AI-assisted generation via `local_aihub`), coexisting with the real Question
  Bank rather than replacing it.
* 🏆 **Ranking:** A separate leaderboard (not tied to the configured grade), with its own
  aggregation per game mode.
* 🥊 **Dispute Mode (V2):** A future head-to-head mode — architecture not finalized yet.

<p class="page-hint">The plugin is Alpha-stage software: everything in "Implemented" above works
today and is covered by the automated test suite; everything in "Planned" is designed (see the
project's internal roadmap) but not yet built.</p>
