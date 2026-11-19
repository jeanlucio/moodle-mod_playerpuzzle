# 📖 Como Usar

## Para Professores

1. Adicione uma atividade PlayerPuzzle a um curso.
2. Escolha um **Modo de Jogo** — Campanha ou Partida Única — veja [Modos de Jogo](#game-modes) — e
   o oponente de uma Partida Única, o HP e o dano, e quantos níveis tem uma Campanha. O formulário limita o HP base a 10.000, o dano base a 1.000 e
   o ganho de moedas a 1.000, bem acima do padrão, e existe só para pegar um erro de digitação.
3. Abra **Gerenciar questões** (na barra de navegação da atividade, em **Mais** quando não couber) para montar o banco de questões da atividade: importe uma
   categoria do Banco de Questões, cadastre questões à mão ou gere-as com IA (questões pendentes
   precisam da sua aprovação antes de serem sorteadas). Veja [Motor de Questões](#questions).
4. Opcionalmente, defina um **Mínimo de perguntas por partida**, se respostas erradas devem contar
   contra a nota, um **cooldown** entre partidas ou entre Campanhas e se as dicas são permitidas.
5. Defina a **Nota** (pontos, ou sem nota), uma nota para aprovação e o **Método de avaliação**, que
   combina as Campanhas ou partidas de um estudante. As duas fórmulas escalam pela Nota Máxima, nunca
   por um `100` fixo.
6. Opcionalmente, ligue itens do [PlayerHUD](#playerhud) — transferência de moedas, custo de nova
   tentativa, prêmio de vitória — se o `block_playerhud` estiver instalado no curso.
7. Decida se o **Ranking** da turma é exibido, e use a página de **Relatório** da atividade para
   acompanhar o progresso, as questões mais erradas, a distribuição das notas, os resultados por
   dificuldade e os heróis escolhidos.

## Para Estudantes

1. Abra a atividade para chegar ao **Lobby**: seu progresso (Campanha), a dificuldade e o aviso de
   mínimo de questões e, quando a atividade os tem, a tentativa em que você está, quanto a próxima
   tentativa custa no PlayerHUD e quando um cooldown termina. Se o Jogar for recusado, uma caixa de
   diálogo diz o motivo. A dificuldade é um botão: aperte para passar por Fácil, Normal e Difícil (uma
   linha embaixo diz o que cada uma muda). Com uma fase em andamento (uma jogada, uma
   resposta ou um item usado), ela fica travada até a tela de fase vencida dela; antes disso, você ainda pode mudá-la. Numa Campanha, o botão da Campanha abre seu **Histórico de Campanhas**:
   uma linha para cada Campanha, com o herói, as fases vencidas, as tentativas usadas e os pontos.
2. Use **Jogar Demo** para uma luta de prática guiada que nunca conta para nada, e **Como jogar** para ler as
   regras: ele também fica atrás do botão **?** durante a luta, enquanto o botão com o ícone de dragão abre a
   ficha do oponente.
3. Abra a **Loja**, que mostra seu saldo de PuzzleCoin, para comprar consumíveis antes da partida e —
   quando o professor ligou o PlayerHUD — para transferir moedas para cá. **Configurações** guarda música (desligada até você ligar), efeitos
   sonoros e narração falada (também desligada) e, quando o ranking está ligado, se o seu nome aparece
   nele; **Ranking** mostra a classificação da turma.
4. Selecione **Jogar** para começar (ou retomar) uma partida. Na Campanha, o primeiro Jogar pede que
   você escolha o seu herói, e uma Campanha em andamento pode ser abandonada pelo Lobby, depois de uma
   confirmação.
5. Combine peças para atacar o oponente, encha a barra do Orbe de Mana para disparar um
   [Desafio de Pergunta](#questions), e responda certo para um acerto crítico. Com mouse ou toque, aperte uma
   peça e depois uma vizinha, ou arraste uma. **Pelo teclado**, entre no tabuleiro com Tab, ande entre as casas
   com as setas, aperte **Enter** para selecionar a peça e **Enter** numa casa vizinha para trocar as duas
   (Enter na mesma peça desfaz a seleção), ou aperte **1** a **9** para fazer a jogada que o jogo anunciou, e
   **Espaço** para ouvir a lista de novo. Veja [Acessibilidade](#accessibility). O botão de bandeira no alto
   permite sair e continuar depois, sem custo, ou desistir da partida.
6. Depois de uma fase ou partida, revise as perguntas que respondeu. No modo Campanha, vencer uma
   fase nunca consome uma nova tentativa — só perder consome.
