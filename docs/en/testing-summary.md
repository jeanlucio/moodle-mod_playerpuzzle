# 🧪 Automated Tests

PlayerPuzzle ships with an extensive PHPUnit suite covering the combat engine, the question
pipeline, anti-cheat security, PlayerHUD integration, backup/restore, completion and privacy.
Every CI push runs the full matrix (Moodle 4.5 → 5.3, PHP 8.1 → 8.4, PostgreSQL & MariaDB).

### PHPUnit — Unit & Integration Tests (`tests/`)

| Test file | Cases |
|-----------|------:|
| `access_test.php` | 1 |
| `backup_restore_test.php` | 13 |
| `generator_test.php` | 1 |
| `lib_completion_info_test.php` | 6 |
| `lib_crud_test.php` | 8 |
| `lib_grade_item_update_test.php` | 4 |
| `lib_pluginfile_test.php` | 5 |
| `lib_supports_test.php` | 2 |
| `lib_update_grades_test.php` | 4 |
| `mod_form_test.php` | 11 |
| `phaser_loading_test.php` | 2 |
| `uninstall_test.php` | 2 |
| **Subtotal** | **59** |

### Completion Tests (`tests/completion/`)

| Test file | Cases |
|-----------|------:|
| `custom_completion_test.php` | 11 |
| **Subtotal** | **11** |

### Event Tests (`tests/event/`)

| Test file | Cases |
|-----------|------:|
| `game_completed_test.php` | 3 |
| `game_started_test.php` | 2 |
| **Subtotal** | **5** |

### Web Services Tests (`tests/external/`)

| Test file | Cases |
|-----------|------:|
| `advance_phase_test.php` | 26 |
| `buy_stock_test.php` | 10 |
| `draw_question_test.php` | 8 |
| `generate_questions_test.php` | 2 |
| `get_phase_questionlog_test.php` | 5 |
| `save_combat_state_test.php` | 14 |
| `save_generated_questions_test.php` | 4 |
| `save_progress_test.php` | 29 |
| `set_sound_preference_test.php` | 5 |
| `transfer_hud_coins_test.php` | 8 |
| `use_stock_test.php` | 15 |
| `validate_answer_test.php` | 19 |
| **Subtotal** | **145** |

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
| `game_page_service_test.php` | 27 |
| `grade_calculator_test.php` | 21 |
| `hud_service_test.php` | 18 |
| `lobby_page_service_test.php` | 22 |
| `move_log_test.php` | 20 |
| `question_bank_sync_test.php` | 13 |
| `question_editor_files_test.php` | 3 |
| `question_list_service_test.php` | 3 |
| `questions_repository_test.php` | 21 |
| `ranking_service_test.php` | 9 |
| `replay_credit_test.php` | 8 |
| `report_service_test.php` | 17 |
| `sound_preferences_test.php` | 7 |
| `user_stock_test.php` | 10 |
| **Subtotal** | **235** |

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

| **Grand Total** | **669** |

```bash
vendor/bin/phpunit --testsuite mod_playerpuzzle_testsuite
```

**Coverage** (`moodle-coverage`, PHPUnit + Xdebug): **86.79%** lines, 74.03% methods
(21/47 classes fully covered).

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})

## Behat — Acceptance Tests

| Feature file | What is covered |
|--------------|-----------------|
| `mod_playerpuzzle_smoke.feature` | The Moodle-chrome flow: adding the activity, reaching the Lobby, entering a match |
| `mod_playerpuzzle_settings.feature` | Activity settings form behavior |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@mod_playerpuzzle --profile=chrome
```
