# 🕹️ Ecossistema Player

O PlayerPuzzle é uma atividade dentro de uma família mais ampla de plugins de gamificação pro
Moodle. Nenhum deles é obrigatório — o PlayerPuzzle funciona sozinho — mas instalar os abaixo
libera as integrações documentadas ao longo desta página.

* **PlayerHUD Block:** XP, níveis, inventário, drops, missões, classes de RPG e ranking dentro
  de cada curso. O PlayerPuzzle lê/escreve nele pra toda a sua economia opcional — veja
  [Integração com o PlayerHUD](#playerhud).
  👉 [github.com/jeanlucio/moodle-block_playerhud](https://github.com/jeanlucio/moodle-block_playerhud)

* **PlayerWords:** uma atividade irmã (adivinhação de palavras) que estabeleceu o padrão de
  `grademethod`/contagem de tentativas que o modo Partida Única do PlayerPuzzle reaproveita.
  👉 [github.com/jeanlucio/moodle-mod_playerwords](https://github.com/jeanlucio/moodle-mod_playerwords)

* **PlayerCross:** uma atividade irmã (palavras cruzadas) compartilhando os mesmos padrões
  arquiteturais (classes `local\*_page_service` de entrada, convenções de integração com o
  PlayerHUD).
  👉 [github.com/jeanlucio/moodle-mod_playercross](https://github.com/jeanlucio/moodle-mod_playercross)

* **PlayerLand:** outra atividade baseada em Phaser no ecossistema, compartilhando o mesmo
  padrão de carregamento dinâmico de script pro seu próprio motor de jogo embutido.
  👉 [github.com/jeanlucio/moodle-mod_playerland](https://github.com/jeanlucio/moodle-mod_playerland)

* **AI Hub:** corretor compartilhado de BYOK (traga sua própria chave) pra funcionalidades de IA
  em todo o ecossistema. A funcionalidade planejada de geração de questões do PlayerPuzzle vai
  consumi-lo como dependência opcional, recorrendo ao próprio `core_ai` do Moodle — veja
  [Funcionalidades](#features).
  👉 [github.com/jeanlucio/moodle-local_aihub](https://github.com/jeanlucio/moodle-local_aihub)

* **PlayerGames:** hub central de um segundo ecossistema de gamificação, mais amplo (XP
  site-wide, temporadas, minijogos diários) — uma iniciativa separada da família PlayerHUD
  acima.
  👉 [github.com/jeanlucio/moodle-local_playergames](https://github.com/jeanlucio/moodle-local_playergames)
