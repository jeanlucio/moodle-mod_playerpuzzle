# 🧪 Testes Automatizados

O PlayerPuzzle vem com uma suíte PHPUnit cobrindo o motor de combate, o fluxo de questões, a
segurança anti-trapaça, a integração com o PlayerHUD, e privacidade. Todo push no CI roda a
matriz completa (Moodle 4.5 → 5.2, PHP 8.2 → 8.4, PostgreSQL e MariaDB).

## PHPUnit — Testes Unitários e de Integração

| Arquivo de teste | Casos | O que é coberto |
|-------------------|------:|------------------|
| `local/engine/security_test.php` | 15 | Geração/unicidade do token anti-replay; caminho feliz de validar-e-consumir e todo caminho de rejeição (replay, usuário errado, instância errada, token desconhecido, status inválido); retomada de tentativa de campanha (nova vs. retomada, nível/fase preservados, token rotacionado, sem linha duplicada, isolamento por instância/usuário, pega a mais recente entre várias linhas órfãs) |
| `local/hud_service_test.php` | 14 | Busca da instância do bloco e escopo por curso, detecção de instalação, listagem de itens (habilitados/ordenados), nome de item, leitura de nível de upgrade, crédito de moedas e seus caminhos de falha (item não configurado, quantidade não positiva) |
| `mod_form_test.php` | 11 | Campos do PlayerHUD aparecendo/escondendo conforme a presença do bloco, preservação de opção de item obsoleta, modo de jogo padrão, regras `hideIf` nos dois sentidos, padrão do mínimo de questões, validação rejeitando quantidade zero quando um item do PlayerHUD está configurado |
| `external/advance_phase_test.php` | 9 | Incremento de fase dentro do nível, transição pro próximo nível, HP retornado escalado pra nova fase, rotação de token com rejeição de replay, rejeição de dano insuficiente, tentativa continua `inprogress`, rejeição na última fase, rejeição de token desconhecido, aplicação da capability |
| `local/engine/question_fetcher_test.php` | 8 | Payload do frontend nunca vaza a resposta correta, filtro por categoria, limite de resultados, checagens de acerto de resposta, busca do id da resposta correta incluindo o caso sem nenhuma resposta com crédito total |
| `external/save_progress_test.php` | 7 | Vitória credita o item de moeda configurado, derrota descarta o ouro, dano limitado ao HP do chefe escalado pela fase (não o HP base), rejeição de token inválido/reproduzido, aplicação da capability |
| `local/lobby_page_service_test.php` | 7 | Campos base do Lobby, saldo mostrado só pros itens do PlayerHUD configurados, sem progresso no modo Partida Única ou sem tentativa em andamento, progresso da tentativa mais recente, lógica do aviso de mínimo de questões |
| `local/game_page_service_test.php` | 6 | Aplicação do limite de tentativas (ilimitado, Campanha, Partida Única, ignorando tentativas em andamento), escala de HP pra fase atual, Partida Única usa HP base sem escala |
| `privacy/provider_test.php` | 12 | Declaração de metadados mais uma checagem de drift garantindo que toda coluna declarada bate com o schema real; contextos, lista de usuários, exportação em contexto único/múltiplo, e os três caminhos de exclusão, cada um conferido quanto a isolamento entre módulos |
| `local/engine/combat_test.php` | 4 | Fórmulas de HP do chefe/estudante contra a tabela de exemplos documentada, Nível 1/Fase 1 devolve o HP base sem alteração, caso extremo de HP base zero |
| `external/validate_answer_test.php` | 4 | Formato da resposta certa/errada, rejeição de uma questão fora da categoria configurada da instância, aplicação da capability |
| `lib_crud_test.php` | 4 | Persistência de campos em `add_instance`/`update_instance`, `delete_instance` propaga para as tentativas, tratamento de id desconhecido |
| `phaser_loading_test.php` | 2 | Guarda de regressão estrutural: nenhum `<script>` estático enfileira o Phaser, o `game_boot.js` o carrega dinamicamente — veja a nota sobre o carregamento do Phaser em [Funcionalidades](#features) |
| `lib_supports_test.php` | 1 | Flags de suporte a funcionalidades, incluindo uma funcionalidade não reconhecida devolvendo `null` |
| **Total** | **104** | |

```bash
vendor/bin/phpunit --testsuite mod_playerpuzzle
```

## Cobertura

Medida localmente com Xdebug (`moodle-coverage`, ferramenta de bancada — não faz parte do CI):

| | Cobertura |
|---|---|
| Classes | 70,00% (7/10 totalmente cobertas) |
| Métodos | 81,08% (30/37) |
| Linhas | 84,44% (521/617) |

Toda classe que trata do próprio motor de combate — `advance_phase`, `save_progress`,
`validate_answer`, `combat`, `question_fetcher`, `security`, `game_page_service` — está em
**100%** de cobertura de linhas e métodos. As três classes abaixo da cobertura total não são
funcionalidades sem teste; cada lacuna é um ramo específico e de baixo valor:

* **`hud_service`** (85,71% métodos / 96,30% linhas) — a guarda `!is_installed()` dentro de
  `get_item_name()` nunca é acionada, porque todo teste que alcança esse método já tem o
  PlayerHUD instalado (um teste separado de `is_installed()` cobre essa checagem por si só).
* **`lobby_page_service`** (50,00% métodos / 91,38% linhas) — a métrica estrita de "totalmente
  coberto" só credita `build_progress_context` e `build_minquestions_context`, ambos a 100%;
  `build_page_data` (93,33%) e `build_hud_stats_context` (71,43%) são exercitados por todo teste
  do arquivo, mas não acionam toda permutação individual de item do PlayerHUD configurado.
* **`privacy/provider`** (42,86% métodos / 93,40% linhas) — todo método de exclusão/exportação
  fica entre 81% e 97%; nenhum caminho de exclusão ou exportação fica sem teste, só alguns ramos
  de caso extremo dentro deles (por exemplo, um conjunto de resultados já vazio).

## Behat — Testes de Aceitação

| Arquivo de feature | Cenários | O que é coberto |
|---------------------|----------:|------------------|
| `mod_playerpuzzle_smoke.feature` | — | O fluxo da moldura Moodle: adicionar a atividade, chegar ao Lobby, entrar numa partida. Deliberadamente restrito ao que está fora do Canvas — veja [Acessibilidade](#accessibility) pra entender por que o Canvas em si não é testável via Behat hoje. |
| `mod_playerpuzzle_settings.feature` | — | Comportamento do formulário de configurações da atividade |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@mod_playerpuzzle --profile=chrome
```
