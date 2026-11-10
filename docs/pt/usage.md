# 📖 Como Usar

## Para Professores

1. Adicione uma atividade PlayerPuzzle a um curso.
2. Escolha um **Modo de Jogo** — Campanha ou Partida Única — veja [Modos de Jogo](#game-modes) — e
   o oponente, o HP e o dano dele, e quantos níveis tem uma Campanha.
3. Abra **Gerenciar questões** (na barra de navegação da atividade, em **Mais** quando não couber) para montar o banco de questões da atividade: importe uma
   categoria do Banco de Questões, cadastre questões à mão ou gere-as com IA (questões pendentes
   precisam da sua aprovação antes de serem sorteadas). Veja [Motor de Questões](#questions).
4. Opcionalmente, defina um **Mínimo de perguntas por partida**, se respostas erradas devem contar
   contra a nota, um **cooldown** entre partidas (Partida Única) e se as dicas são permitidas.
5. Defina a **Nota** (pontos, ou sem nota), uma nota para aprovação e, na Partida Única, o
   **Método de avaliação**. As duas fórmulas escalam pela Nota Máxima, nunca por um `100` fixo.
6. Opcionalmente, ligue itens do [PlayerHUD](#playerhud) — transferência de moedas, custo de nova
   tentativa, prêmio de vitória — se o `block_playerhud` estiver instalado no curso.
7. Decida se o **Ranking** da turma é exibido, e use a página de **Relatório** da atividade para
   acompanhar o progresso, as questões mais erradas e a distribuição das notas.

## Para Estudantes

1. Abra a atividade para chegar ao **Lobby**: seu progresso (Campanha), a dificuldade e o aviso de
   mínimo de questões. A dificuldade é um botão: aperte para passar por Fácil, Normal e Difícil (uma
   linha embaixo diz o que cada uma muda). Com uma fase em andamento, ela fica travada até a próxima
   tela de fase vencida. Numa Campanha, o botão da Campanha abre seu **Histórico de Campanhas**:
   uma linha para cada Campanha, com o herói, as fases vencidas, as tentativas usadas e os pontos.
2. Use **Jogar Demo** para uma luta de prática guiada que nunca conta para nada.
3. Abra a **Loja**, que mostra seu saldo de PuzzleCoin, para comprar consumíveis antes da partida e —
   quando o professor ligou o PlayerHUD — para transferir moedas para cá. **Configurações** guarda música (desligada até você ligar), efeitos
   sonoros e narração falada (também desligada), e **Ranking** mostra a classificação da turma.
4. Selecione **Jogar** para começar (ou retomar) uma partida.
5. Combine peças para atacar o oponente, encha a barra do Orbe de Mana para disparar um
   [Desafio de Pergunta](#questions), e responda certo para um acerto crítico.
6. Depois de uma fase ou partida, revise as perguntas que respondeu. No modo Campanha, vencer uma
   fase nunca consome uma nova tentativa — só perder consome.
