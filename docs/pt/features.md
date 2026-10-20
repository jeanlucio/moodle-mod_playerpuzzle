# ✨ Funcionalidades

## ⚔️ Jogabilidade

* **Combate Match-3 por turnos:** um tabuleiro 8×8 (Phaser) onde o estudante troca peças para
  atacar um chefe. Sete tipos de peça, cada uma com seu efeito — veja
  [Como o Combate Funciona](#combat).
* **IA do chefe simétrica:** toda peça resolve exatamente do mesmo jeito no turno do chefe e no do
  estudante — o chefe causa dano, acumula multiplicador de crítico, envenena, se protege, se cura e
  acumula a própria moeda.
* **Desafios de pergunta:** encher a barra do Orbe de Mana abre uma pergunta; acertar é um ataque
  crítico, errar custa HP. Veja [Motor de Questões](#questions).
* **Dois modos de jogo:** **Campanha** (até 10 níveis × 10 fases, onde vencer nunca gasta uma
  tentativa) e **Partida Única** (uma rodada autocontida e repetível). Veja
  [Modos de Jogo](#game-modes).
* **Três dificuldades,** escolhidas pelo estudante, que nunca mudam a nota: Fácil, Normal e Difícil
  escalam o chefe, a recompensa em moedas e a frequência com que o chefe acerta a pergunta.
* **Mínimo de questões por partida:** o chefe revive com metade do HP se cair antes de o estudante
  ter respondido o número exigido de perguntas.
* **Retome de onde parou:** o tabuleiro e a luta são salvos durante o jogo, então fechar a aba ou
  recarregar a página retoma a luta de onde estava.
* **Partida Demo:** uma luta de prática guiada, sempre disponível no Lobby, que nunca conta para
  nota, moedas ou tentativas.
* **Revisão após a partida:** cada pergunta respondida, com a resposta do estudante e a correta.

## 🏛️ Lobby e economia

* **Lobby como tela inicial:** progresso, dificuldade, o aviso de mínimo de questões e **Jogar**,
  com botões para a **Loja**, o **Ranking** e as **Configurações**.
* **PuzzleCoin:** a moeda persistente do próprio plugin, ganha ao vencer fases e partidas.
* **Loja de loadout:** antes da partida, o estudante gasta PuzzleCoin em consumíveis — Poção,
  Escudo, Magia Rápida, Espada e Dica da Questão — e os usa durante a luta, dentro de um limite
  fixo por fase. Veja [Combate](#combat).
* **Vínculo opcional com o PlayerHUD:** com o [block_playerhud](#playerhud) no curso, os
  estudantes podem transferir moedas do PlayerHUD para PuzzleCoin, uma nova tentativa pode custar
  um item do PlayerHUD, e uma vitória pode conceder um. Sem ele, tudo isso acima continua
  funcionando.
* **Ranking da turma:** um placar opcional, independente da nota, que respeita grupos separados.

## 📚 Questões

* **Três fontes por atividade:** importar do Banco de Questões do Moodle, escrever à mão ou gerar
  com IA. Múltipla escolha e Verdadeiro/Falso.
* **Revisão do professor:** as questões geradas por IA aparecem primeiro numa prévia e são salvas
  como pendentes; nenhuma é sorteada numa partida antes de ser aprovada. Aprovar e excluir em lote.
* **Dicas:** uma dica opcional por questão, usada pelo consumível Dica.

## 📊 Notas e ferramentas do professor

* **Livro de notas:** um item de nota de verdade, escalado pela Nota Máxima configurada (pontos, ou
  sem nota), com nota para aprovação e uma variante opcional "considerar erros". Veja
  [Modos de Jogo](#game-modes).
* **Conclusão personalizada:** exija um número de tentativas e/ou vitórias.
* **Relatório do professor:** progresso por estudante, dano médio ao chefe e taxa de acerto, as
  questões mais erradas, a distribuição das notas e a taxa de conclusão, respeitando grupos
  separados.
* **Backup e restauração,** incluindo duplicar a atividade, levando junto as tentativas, o estoque
  do loadout e os logs.

## 🛡️ Integridade

* **JSON Cego:** a resposta correta nunca chega ao navegador antes de o servidor julgá-la.
* **Reprodução no servidor:** o servidor refaz as jogadas registradas pelo estudante a partir de
  uma semente que ele mesmo emitiu e calcula o resultado real, em vez de confiar no que o cliente
  reporta.
* **Tokens de uso único e travas atômicas** contra requisições repetidas ou paralelas.

Veja [Segurança e Anti-Trapaça](#security).

## ♿ Acessibilidade

Uma camada HTML paralela e sempre presente para o tabuleiro (uma grade oculta com navegação por
setas, jogadas por teclas numéricas e anúncios `aria-live` a cada turno), um `<dialog>` nativo
para as perguntas e uma narração falada opcional. Veja [Acessibilidade](#accessibility).

## 🔐 Privacidade e qualidade

* **Provedor de privacidade:** metadados, exportação e exclusão de toda tabela que guarda dado
  pessoal, mais as preferências de som e de narração.
* **Testes automatizados:** PHPUnit, testes unitários de JavaScript sem navegador e Behat, verdes
  em toda a matriz de CI (Moodle 4.5 → 5.3). Veja [Testes Automatizados](#testing).

## ⏳ Fora desta versão

* 🥊 **Modo Disputa (V2):** um modo frente a frente num tabuleiro compartilhado. Depende de um
  transporte em tempo real que ainda não está disponível, então está desenhado, mas não
  construído.
* ⏱️ **Tempo limite por partida:** removido do formulário porque nada o aplicava. Se voltar, será
  desenhado para ser conferido no servidor.
* 💲 **Preços da loja espelhando uma troca do PlayerHUD:** os preços são fixos hoje (veja
  [Combate](#combat)); seguir uma troca configurada no PlayerHUD é um passo futuro possível.

<p class="page-hint">O plugin ainda não foi publicado no Diretório de Plugins do Moodle. Tudo o que
está listado acima funciona hoje e é coberto pela suíte de testes automatizados.</p>
