# 🧪 Automated Tests

PlayerPuzzle ships with an extensive PHPUnit suite covering the combat engine, the question
pipeline, anti-cheat security, PlayerHUD integration, backup/restore, completion and privacy.
Every CI push runs the full matrix (Moodle 4.5 → 5.3, PHP 8.1 → 8.4, PostgreSQL & MariaDB).

### PHPUnit — Unit & Integration Tests (`tests/`)

| Test file | Cases |
|-----------|------:|
| `access_test.php` | 1 |
| `backup_restore_test.php` | 14 |
| `generator_test.php` | 1 |
| `lib_completion_info_test.php` | 7 |
| `lib_crud_test.php` | 10 |
| `lib_grade_item_update_test.php` | 5 |
| `lib_pluginfile_test.php` | 5 |
| `lib_supports_test.php` | 2 |
| `lib_update_grades_test.php` | 7 |
| `mod_form_test.php` | 21 |
| `phaser_loading_test.php` | 2 |
| `uninstall_test.php` | 2 |
| **Subtotal** | **77** |

### Completion Tests (`tests/completion/`)

| Test file | Cases |
|-----------|------:|
| `custom_completion_test.php` | 11 |
| **Subtotal** | **11** |

### Event Tests (`tests/event/`)

| Test file | Cases |
|-----------|------:|
| `course_module_viewed_test.php` | 2 |
| `game_completed_test.php` | 3 |
| `game_started_test.php` | 2 |
| **Subtotal** | **7** |

### Web Services Tests (`tests/external/`)

| Test file | Cases |
|-----------|------:|
| `advance_phase_test.php` | 28 |
| `buy_stock_test.php` | 12 |
| `draw_question_test.php` | 9 |
| `generate_questions_test.php` | 2 |
| `get_phase_questionlog_test.php` | 5 |
| `save_combat_state_test.php` | 14 |
| `save_generated_questions_test.php` | 4 |
| `save_progress_test.php` | 31 |
| `set_sound_preference_test.php` | 5 |
| `transfer_hud_coins_test.php` | 9 |
| `use_stock_test.php` | 16 |
| `validate_answer_test.php` | 19 |
| **Subtotal** | **154** |

### Form Tests (`tests/form/`)

| Test file | Cases |
|-----------|------:|
| `import_form_test.php` | 3 |
| `question_form_test.php` | 11 |
| **Subtotal** | **14** |

### Local Business-Logic Tests (`tests/local/`)

| Test file | Cases |
|-----------|------:|
| `ai_question_generator_test.php` | 19 |
| `attempt_consumables_test.php` | 7 |
| `attempt_questions_test.php` | 1 |
| `coin_ledger_test.php` | 4 |
| `combat_state_test.php` | 5 |
| `game_page_service_test.php` | 37 |
| `grade_calculator_test.php` | 29 |
| `hud_service_test.php` | 15 |
| `instance_list_service_test.php` | 3 |
| `lobby_page_service_test.php` | 26 |
| `move_log_test.php` | 20 |
| `question_bank_sync_test.php` | 13 |
| `question_editor_files_test.php` | 3 |
| `question_list_service_test.php` | 3 |
| `questions_repository_test.php` | 23 |
| `ranking_service_test.php` | 9 |
| `replay_credit_test.php` | 8 |
| `report_service_test.php` | 19 |
| `sound_preferences_test.php` | 7 |
| `user_stock_test.php` | 10 |
| **Subtotal** | **261** |

### Combat Engine Tests (`tests/local/engine/`)

| Test file | Cases |
|-----------|------:|
| `board_engine_test.php` | 25 |
| `combat_engine_test.php` | 29 |
| `combat_test.php` | 9 |
| `golden_vectors_test.php` | 5 |
| `prng_test.php` | 5 |
| `question_fetcher_test.php` | 20 |
| `replay_test.php` | 28 |
| `security_test.php` | 43 |
| **Subtotal** | **164** |

### Privacy Tests (`tests/privacy/`)

| Test file | Cases |
|-----------|------:|
| `provider_test.php` | 36 |
| **Subtotal** | **36** |

| **Grand Total** | **724** |

```bash
vendor/bin/phpunit --testsuite mod_playerpuzzle_testsuite
```

**Coverage** (`moodle-coverage`, PHPUnit + Xdebug): **87.8%** of the plugin's lines (4511/5135).

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})

## JavaScript — Unit Tests (`tests/js/engine/`)

The deterministic match-3/combat/PRNG math, run headless via Node's own test runner (`node
--test`) — no Jest, no browser. First plugin in the ecosystem with a JS test layer of its own.

| Test file | Cases |
|-----------|------:|
| `board_rules.test.js` | 25 |
| `combat_rules.test.js` | 29 |
| `prng.test.js` | 5 |
| **Subtotal** | **59** |

```bash
node --test tests/js/engine/board_rules.test.js tests/js/engine/combat_rules.test.js tests/js/engine/prng.test.js
```

## Behat — Acceptance Tests

| Feature file | Scenarios |
|--------------|----------:|
| `mod_playerpuzzle_accessibility.feature` | 4 |
| `mod_playerpuzzle_gameplay.feature` | 4 |
| `mod_playerpuzzle_match.feature` | 3 |
| `mod_playerpuzzle_ranking.feature` | 3 |
| `mod_playerpuzzle_settings.feature` | 1 |
| `mod_playerpuzzle_smoke.feature` | 1 |
| **Subtotal** | **16** |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@mod_playerpuzzle --profile=chrome
```
