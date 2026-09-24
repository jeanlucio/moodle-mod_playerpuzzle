# 🧪 Testes Automatizados

O PlayerPuzzle vem com uma suíte PHPUnit extensa cobrindo o motor de combate, o fluxo de
questões, a segurança anti-trapaça, a integração com o PlayerHUD, backup/restore, conclusão de
atividade e privacidade. Todo push no CI roda a matriz completa (Moodle 4.5 → 5.3, PHP 8.1 →
8.4, PostgreSQL e MariaDB).

### PHPUnit — Testes Unitários e de Integração (`tests/`)

| Arquivo de teste | Casos |
|-------------------|------:|
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

### Testes de Conclusão (`tests/completion/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `custom_completion_test.php` | 11 |
| **Subtotal** | **11** |

### Testes de Eventos (`tests/event/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `game_completed_test.php` | 3 |
| `game_started_test.php` | 2 |
| **Subtotal** | **5** |

### Testes de Web Services (`tests/external/`)

| Arquivo de teste | Casos |
|-------------------|------:|
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

### Testes de Formulário (`tests/form/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `import_form_test.php` | 3 |
| `question_form_test.php` | 11 |
| **Subtotal** | **14** |

### Testes de Lógica de Negócio Local (`tests/local/`)

| Arquivo de teste | Casos |
|-------------------|------:|
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

### Testes do Motor de Combate (`tests/local/engine/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `board_engine_test.php` | 25 |
| `combat_engine_test.php` | 29 |
| `combat_test.php` | 9 |
| `golden_vectors_test.php` | 5 |
| `prng_test.php` | 5 |
| `question_fetcher_test.php` | 20 |
| `replay_test.php` | 28 |
| `security_test.php` | 43 |
| **Subtotal** | **164** |

### Testes de Privacidade (`tests/privacy/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `provider_test.php` | 36 |
| **Subtotal** | **36** |

| **Total Geral** | **669** |

```bash
vendor/bin/phpunit --testsuite mod_playerpuzzle_testsuite
```

**Cobertura** (`moodle-coverage`, PHPUnit + Xdebug): **86,79%** de linhas, 74,03% de métodos
(21/47 classes totalmente cobertas).

[Detalhamento completo teste a teste e tabela de cobertura →]({{ '/testing-pt.html' | relative_url }})

## JavaScript — Testes Unitários (`tests/js/engine/`)

A matemática determinística de match-3/combate/PRNG, rodada sem cabeça via o test runner
nativo do Node (`node --test`) — sem Jest, sem navegador. Primeiro plugin do ecossistema com
uma camada de teste JS própria.

| Arquivo de teste | Casos |
|-------------------|------:|
| `board_rules.test.js` | 25 |
| `combat_rules.test.js` | 29 |
| `prng.test.js` | 5 |
| **Subtotal** | **59** |

```bash
node --test tests/js/engine/board_rules.test.js tests/js/engine/combat_rules.test.js tests/js/engine/prng.test.js
```

## Behat — Testes de Aceitação

| Arquivo de feature | Cenários |
|---------------------|---------:|
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
