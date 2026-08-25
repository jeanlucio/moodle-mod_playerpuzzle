# 🕹️ Ecossistema PlayerGames

O PlayerPuzzle faz parte do ecossistema de gamificação
**[PlayerGames](https://jeanlucio.github.io/playergames/)** para o Moodle. Nenhum dos plugins
abaixo é obrigatório — o PlayerPuzzle funciona sozinho — mas instalar os relevantes pra um curso
libera as integrações documentadas ao longo desta página.

* **PlayerGames:** hub central do ecossistema — XP site-wide, temporadas, minijogos diários, e o
  Dashboard do Ecossistema que conecta todo plugin Player instalado.
  👉 [github.com/jeanlucio/moodle-local_playergames](https://github.com/jeanlucio/moodle-local_playergames)

* **PlayerHUD Block:** XP, níveis, inventário, drops, missões, classes de RPG e ranking dentro
  de cada curso. O PlayerPuzzle lê/escreve nele pra toda a sua economia opcional — veja
  [Integração com o PlayerHUD](#playerhud).
  👉 [github.com/jeanlucio/moodle-block_playerhud](https://github.com/jeanlucio/moodle-block_playerhud)

* **PlayerHUD Filter:** habilita drops de itens via shortcodes dentro do conteúdo do curso.
  👉 [github.com/jeanlucio/moodle-filter_playerhud](https://github.com/jeanlucio/moodle-filter_playerhud)

* **PlayerHUD Availability Restriction:** restringe o acesso a atividades do curso com base no
  nível atual do estudante ou nos itens coletados.
  👉 [github.com/jeanlucio/moodle-availability_playerhud](https://github.com/jeanlucio/moodle-availability_playerhud)

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

* **PlayerGroup:** permite que estudantes formem seus próprios grupos de forma autônoma direto
  na página da atividade — sem precisar de intervenção do professor.
  👉 [github.com/jeanlucio/moodle-mod_playergroup](https://github.com/jeanlucio/moodle-mod_playergroup)

* **AI Hub:** corretor compartilhado de BYOK (traga sua própria chave) pra funcionalidades de IA
  em todo o ecossistema. A funcionalidade planejada de geração de questões do PlayerPuzzle vai
  consumi-lo como dependência opcional, recorrendo ao próprio `core_ai` do Moodle — veja
  [Funcionalidades](#features).
  👉 [github.com/jeanlucio/moodle-local_aihub](https://github.com/jeanlucio/moodle-local_aihub)
