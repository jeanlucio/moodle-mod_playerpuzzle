# 🧪 Testes Automatizados

O PlayerPuzzle vem com uma suíte PHPUnit extensa cobrindo o motor de combate, o fluxo de
questões, a segurança anti-trapaça, a integração com o PlayerHUD, backup/restore, conclusão de
atividade e privacidade. Todo push no CI roda a matriz completa (Moodle 4.5 → 5.3, PHP 8.1 →
8.4, PostgreSQL e MariaDB).

### PHPUnit — Testes Unitários e de Integração (`tests/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `access_test.php` | 1 |
| `backup_restore_test.php` | 23 |
| `generator_test.php` | 2 |
| `install_xml_test.php` | 1 |
| `lib_completion_info_test.php` | 7 |
| `lib_crud_test.php` | 12 |
| `lib_grade_item_update_test.php` | 5 |
| `lib_grant_potential_test.php` | 5 |
| `lib_pluginfile_test.php` | 5 |
| `lib_reset_userdata_test.php` | 4 |
| `lib_settings_navigation_test.php` | 1 |
| `lib_supports_test.php` | 2 |
| `lib_update_grades_test.php` | 7 |
| `mod_form_test.php` | 32 |
| `uninstall_test.php` | 2 |
| `upgrade_test.php` | 7 |
| **Subtotal** | **116** |

### Testes de Conclusão (`tests/completion/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `custom_completion_test.php` | 12 |
| **Subtotal** | **12** |

### Testes de Eventos (`tests/event/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `course_module_viewed_test.php` | 2 |
| `game_completed_test.php` | 3 |
| `game_started_test.php` | 2 |
| **Subtotal** | **7** |

### Testes de Web Services (`tests/external/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `advance_phase_test.php` | 33 |
| `buy_stock_test.php` | 12 |
| `buy_upgrade_test.php` | 7 |
| `core_libraries_test.php` | 2 |
| `draw_question_test.php` | 16 |
| `generate_questions_test.php` | 2 |
| `get_phase_questionlog_test.php` | 5 |
| `get_shop_test.php` | 4 |
| `save_combat_state_test.php` | 14 |
| `save_generated_questions_test.php` | 5 |
| `save_progress_test.php` | 43 |
| `set_ranking_preference_test.php` | 2 |
| `set_sound_preference_test.php` | 5 |
| `transfer_hud_coins_test.php` | 10 |
| `use_stock_test.php` | 19 |
| `validate_answer_test.php` | 20 |
| **Subtotal** | **199** |

### Testes de Formulário (`tests/form/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `import_form_test.php` | 4 |
| `question_form_test.php` | 13 |
| **Subtotal** | **17** |

### Testes de Lógica de Negócio Local (`tests/local/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `ai_question_generator_test.php` | 23 |
| `attempt_consumables_test.php` | 8 |
| `attempt_questions_test.php` | 2 |
| `campaign_giveup_test.php` | 6 |
| `campaign_history_test.php` | 9 |
| `campaigns_test.php` | 14 |
| `coin_ledger_test.php` | 4 |
| `combat_state_test.php` | 5 |
| `game_page_service_test.php` | 63 |
| `grade_calculator_test.php` | 41 |
| `hero_page_service_test.php` | 5 |
| `hero_shop_test.php` | 5 |
| `heroes_test.php` | 9 |
| `howtoplay_test.php` | 5 |
| `hud_service_test.php` | 17 |
| `instance_list_service_test.php` | 4 |
| `last_hero_test.php` | 2 |
| `lobby_page_service_test.php` | 60 |
| `match_abandon_test.php` | 2 |
| `move_log_test.php` | 21 |
| `opponent_card_test.php` | 11 |
| `opponents_test.php` | 9 |
| `question_bank_sync_test.php` | 24 |
| `question_editor_files_test.php` | 3 |
| `question_list_service_test.php` | 7 |
| `questions_repository_test.php` | 33 |
| `ranking_service_test.php` | 12 |
| `replay_credit_test.php` | 8 |
| `report_service_test.php` | 37 |
| `seen_opponents_test.php` | 2 |
| `sound_preferences_test.php` | 8 |
| `text_test.php` | 1 |
| `user_hero_test.php` | 7 |
| `user_stock_test.php` | 10 |
| **Subtotal** | **477** |

### Testes do Motor de Combate (`tests/local/engine/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `board_engine_test.php` | 28 |
| `combat_engine_test.php` | 57 |
| `combat_test.php` | 23 |
| `combat_vectors_test.php` | 25 |
| `engine_version_test.php` | 2 |
| `golden_vectors_test.php` | 5 |
| `prng_test.php` | 5 |
| `question_fetcher_test.php` | 29 |
| `replay_test.php` | 35 |
| `replay_vectors_test.php` | 40 |
| `security_test.php` | 58 |
| **Subtotal** | **307** |

### Testes de Privacidade (`tests/privacy/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `provider_test.php` | 41 |
| **Subtotal** | **41** |

| **Total Geral** | **1176** |

```bash
vendor/bin/phpunit --testsuite mod_playerpuzzle_testsuite
```

**Cobertura** (`moodle-coverage`, PHPUnit + Xdebug): **92,5%** das linhas do plugin (7080/7650).

[Detalhamento completo teste a teste e tabela de cobertura →]({{ '/testing-pt.html' | relative_url }})

## JavaScript — Testes Unitários (`tests/js/engine/`)

A matemática determinística de match-3/combate/PRNG, rodada sem cabeça via o test runner
nativo do Node (`node --test`) — sem Jest, sem navegador. Primeiro plugin do ecossistema com
uma camada de teste JS própria.

| Arquivo de teste | Casos |
|-------------------|------:|
| `board_rules.test.js` | 28 |
| `combat_rules.test.js` | 57 |
| `prng.test.js` | 5 |
| `replay_mirror.test.js` | 18 |
| `vectors.test.js` | 3 |
| **Subtotal** | **111** |

```bash
node --test tests/js/engine/*.test.js
```

## JavaScript — Testes do Palco (`tests/js/stage/`)

O pequeno motor que desenha o jogo com elementos HTML (tweens, relógio, carregamento de arquivos e som) e o
contrato entre o jogo e o HUD, rodados sem navegador com `node --test`. Os vetores de tween e de relógio em
`tests/fixtures/` foram amostrados no Phaser que o plugin trazia, então o tempo é o que os jogadores já conheciam.

| Arquivo de teste | Casos |
|------------------|------:|
| `assets.test.js` | 8 |
| `clock.test.js` | 14 |
| `emoji.test.js` | 6 |
| `hero_grid.test.js` | 1 |
| `hud_contract.test.js` | 7 |
| `modal_contrast.test.js` | 4 |
| `no_globals.test.js` | 1 |
| `portrait_layout.test.js` | 3 |
| `status_effects.test.js` | 7 |
| `tween.test.js` | 20 |
| **Subtotal** | **71** |

```bash
node --test tests/js/stage/*.test.js
```

## Behat — Testes de Aceitação

| Arquivo de feature | Cenários |
|---------------------|---------:|
| `mod_playerpuzzle_accessibility.feature` | 4 |
| `mod_playerpuzzle_gameplay.feature` | 4 |
| `mod_playerpuzzle_hero.feature` | 10 |
| `mod_playerpuzzle_howtoplay.feature` | 4 |
| `mod_playerpuzzle_match.feature` | 3 |
| `mod_playerpuzzle_ranking.feature` | 3 |
| `mod_playerpuzzle_settings.feature` | 1 |
| `mod_playerpuzzle_smoke.feature` | 2 |
| `mod_playerpuzzle_stage.feature` | 5 |
| **Subtotal** | **36** |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@mod_playerpuzzle --profile=chrome
```
