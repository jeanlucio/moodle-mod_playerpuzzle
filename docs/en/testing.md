# 🧪 Automated Tests

PlayerPuzzle ships with a PHPUnit suite covering the combat engine, the question pipeline,
anti-cheat security, PlayerHUD integration, and privacy. Every CI push runs the full matrix
(Moodle 4.5 → 5.2, PHP 8.2 → 8.4, PostgreSQL & MariaDB).

## PHPUnit — Unit & Integration Tests

| Test file | Cases | What is covered |
|-----------|------:|-----------------|
| `local/engine/security_test.php` | 15 | Anti-replay token generation/uniqueness; validate-and-consume happy path and every rejection path (replay, wrong user, wrong instance, unknown token, invalid status); campaign attempt resume (fresh vs. resumed, level/phase preserved, token rotated, no duplicate row, instance/user isolation, picks the most recent of several stale rows) |
| `local/hud_service_test.php` | 14 | Block-instance lookup and course scoping, install detection, item listing (enabled/sorted), item naming, upgrade-level reading, coin crediting and its failure paths (unconfigured item, non-positive quantity) |
| `mod_form_test.php` | 11 | PlayerHUD fields appearing/hiding with the block's presence, stale-item-option preservation, game-mode default, `hideIf` rules in both directions, minimum-questions default, validation rejecting a zero quantity when a HUD item is configured |
| `external/advance_phase_test.php` | 9 | Phase increment within a level, roll-over to the next level, HP returned scaled for the new phase, token rotation with replay rejection, insufficient-damage rejection, attempt stays `inprogress`, rejection at the final phase, unknown-token rejection, capability enforcement |
| `local/engine/question_fetcher_test.php` | 8 | Frontend payload never leaks the correct answer, category filtering, result limit, answer-correctness checks, correct-answer-id lookup including the no-full-credit-response case |
| `external/save_progress_test.php` | 7 | Victory credits the configured coin item, defeat discards gold, damage clamped to the phase-scaled boss HP (not the base HP), invalid/replayed token rejection, capability enforcement |
| `local/lobby_page_service_test.php` | 7 | Base Lobby fields, balance shown only for configured HUD items, no progress shown in Single Match mode or without an in-progress attempt, most-recent-attempt progress, minimum-questions notice logic |
| `local/game_page_service_test.php` | 6 | Attempt-limit enforcement (unlimited, Campaign, Single Match, ignoring in-progress attempts), HP scaling for the current phase, Single Match uses unscaled base HP |
| `privacy/provider_test.php` | 12 | Metadata declaration plus a drift guard asserting every declared table column matches the real schema; contexts, userlist, export across single/multiple contexts, and all three deletion paths, each checked for cross-module isolation |
| `local/engine/combat_test.php` | 4 | Boss/student HP formulas against the documented worked-example table, Level 1/Phase 1 returns the base HP unchanged, zero base HP edge case |
| `external/validate_answer_test.php` | 4 | Correct/wrong answer response shape, rejecting a question outside the instance's configured category, capability enforcement |
| `lib_crud_test.php` | 4 | `add_instance`/`update_instance` field persistence, `delete_instance` cascades to attempts, unknown-id handling |
| `phaser_loading_test.php` | 2 | Structural regression guard: no static `<script>` queues Phaser, `game_boot.js` loads it dynamically instead — see the Phaser-loading note in [Features](#features) |
| `lib_supports_test.php` | 1 | Feature-support flags, including an unrecognised feature returning `null` |
| **Total** | **104** | |

```bash
vendor/bin/phpunit --testsuite mod_playerpuzzle
```

## Coverage

Measured locally with Xdebug (`moodle-coverage`, a bench tool — not part of CI):

| | Coverage |
|---|---|
| Classes | 70.00% (7/10 fully covered) |
| Methods | 81.08% (30/37) |
| Lines | 84.44% (521/617) |

Every class handling the combat engine itself — `advance_phase`, `save_progress`,
`validate_answer`, `combat`, `question_fetcher`, `security`, `game_page_service` — is at
**100%** line and method coverage. The three classes below full coverage are not untested
features; each gap is a specific, low-value branch:

* **`hud_service`** (85.71% methods / 96.30% lines) — `get_item_name()`'s
  `!is_installed()` guard clause is never hit, because every test that reaches this method
  already has PlayerHUD installed (a separate `is_installed()` test covers that check on its
  own).
* **`lobby_page_service`** (50.00% methods / 91.38% lines) — the strict "fully covered"
  method metric only credits `build_progress_context` and `build_minquestions_context`, at
  100% each; `build_page_data` (93.33%) and `build_hud_stats_context` (71.43%) are exercised by
  every test in the file but don't hit every individual HUD-item-configured permutation.
* **`privacy/provider`** (42.86% methods / 93.40% lines) — every deletion/export method sits
  between 81% and 97%; no deletion or export path is untested, only a handful of edge-case
  branches inside them (e.g. an already-empty result set).

## Behat — Acceptance Tests

| Feature file | Scenarios | What is covered |
|--------------|----------:|-----------------|
| `mod_playerpuzzle_smoke.feature` | — | The Moodle-chrome flow: adding the activity, reaching the Lobby, entering a match. Deliberately scoped to what's outside the Canvas — see [Accessibility](#accessibility) for why the Canvas itself isn't Behat-testable today. |
| `mod_playerpuzzle_settings.feature` | — | Activity settings form behavior |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@mod_playerpuzzle --profile=chrome
```
