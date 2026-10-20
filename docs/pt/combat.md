# ⚔️ Como o Combate Funciona

O combate roda num tabuleiro Match-3 8×8 (Phaser 4), priorizando retrato (540×960), se
adaptando a um layout paisagem em telas mais largas.

## Estrutura de Turnos

1. O estudante faz uma jogada no tabuleiro.
2. Combos e cascatas mantêm o turno do estudante rolando.
3. Quando o tabuleiro estabiliza, é o **Turno do Chefe**: uma IA simples varre o tabuleiro e
   executa uma troca válida. O que ela combina resolve pelas mesmas sete peças e os mesmos
   efeitos do turno do estudante (veja abaixo) — o chefe pode causar dano, acumular
   multiplicador, envenenar o estudante, se proteger, se curar e acumular moeda, exatamente como
   o estudante faz no próprio turno.

## As Sete Peças

Efeitos simétricos: cada peça faz a mesma coisa não importa de qual lado está jogando — o que
muda é só quem ganha o efeito (quem estiver no turno atual).

| Peça | Efeito |
|------|--------|
| ⭐ Estrela | Soma +0,1 a um multiplicador de dano, acumulando entre turnos; consumido no acerto crítico daquele lado e resetado a 1 quando esse lado erra uma pergunta |
| 📖 Grimório | Enche um medidor de veneno próprio de 0–100 (+10 por peça); ao chegar em 100, arma 3 rodadas de dano contra o **oponente** (uma por turno dele) e reseta, preservando o excedente |
| ❓ Orbe de Interrogação | Enche uma barra de energia de 100 pontos própria de cada lado; ao chegar em 100, pausa o combate e abre o [Desafio de Pergunta](#questions) — o estudante responde de verdade, o chefe "responde" escolhendo uma opção ao acaso, validada do mesmo jeito no servidor |
| ⚔️ Espadas | Dano direto no **oponente** — sem precisar de pergunta. Escala com nível/fase, e uma combinação de 4 ou 5 peças causa 1,5x/2x o dano de uma combinação de 3 |
| 🛡️ Escudo | Enche um medidor próprio de 0–100 (+10 por peça); ao chegar em 100, nega por completo o **próximo** golpe recebido por esse lado, não importa o valor, e reseta |
| 🧪 Poção | Cura o HP do próprio lado em um quarto do dano base por peça, então uma combinação de 3 cura três quartos do que uma combinação de 3 Espadas causa — o dano sempre pesa mais que a cura |
| 🪙 Moeda | Soma ao contador de ouro próprio da partida — veja a fórmula por combinação e a compensação líquida em [Integração com o PlayerHUD](#playerhud) |

## O Desafio de Pergunta

Quando a barra de Orbe de Interrogação de qualquer um dos lados chega a 100%, a partida pausa e
abre o [modal de pergunta](#questions):

* **Resposta correta** — um acerto crítico: dano base triplicado, multiplicado pelo bônus de
  Estrela que esse lado acumulou.
* **Resposta errada** — esse lado sofre dano, e seu multiplicador de Estrela volta pra 1.

O chefe também "responde" quando a própria barra de Orbe de Mana enche, mas nunca vê a resposta
certa: é o servidor que decide se o chefe acertou, com uma probabilidade que depende da
dificuldade (veja abaixo).

É o momento em que o plugin conecta a habilidade no Match-3 com o conhecimento real de
conteúdo: combinar peças bem enche a barra e acumula o multiplicador, mas o retorno de verdade
só vem se o estudante também responder certo.

## Mínimo de Questões e a Revivência do Chefe

O professor pode exigir um número mínimo de perguntas respondidas por partida (3 por padrão). O
Lobby avisa o estudante antes de a partida começar, e um contador mostra o progresso durante ela.
Se o chefe fosse cair antes de esse número ser atingido, ele volta com metade do HP — quantas
vezes for preciso —, então uma partida nunca pode ser vencida sem o estudante realmente encarar as
perguntas.

## Dificuldade

O estudante escolhe Fácil, Normal ou Difícil. A escolha muda a luta, nunca a nota:

| | HP e dano do chefe | Moedas ganhas | Chefe acerta uma Múltipla Escolha | Chefe acerta um Verdadeiro/Falso |
|---|:---:|:---:|:---:|:---:|
| Fácil | ×0,5 | ×0,5 | 33% | 50% |
| Normal | ×1 | ×1 | 66% | 75% |
| Difícil | ×2 | ×3 | 100% | 100% |

Na Campanha a escolha é feita a cada fase: no Lobby para a primeira, e na tela "Fase concluída"
para a seguinte.

## A Loja de Loadout e os Consumíveis

Os consumíveis são comprados **antes** da partida, na loja do Lobby, com PuzzleCoin, e usados no
painel de combate durante a luta. Cada uso sai do estoque do estudante e é limitado por fase ou
partida:

| Consumível | Efeito | Preço | Usos por fase |
|---|---|:---:|:---:|
| 🧪 Poção | Cura 1,25× o dano base | 8 | 3 |
| 🛡️ Escudo | Enche o medidor de Escudo de uma vez, armando o bloqueio do próximo golpe | 10 | 1 |
| 🔮 Magia Rápida | Enche o medidor do Grimório de uma vez, armando três rodadas de veneno no chefe | 12 | 1 |
| ⚔️ Espada | Um ataque extra de um dano base, sem encerrar o turno | 10 | 3 |
| 💡 Dica | Mostra a dica da pergunta aberta, quando ela tem uma | 5 | sem limite fixo |

Os preços e limites são fixos, iguais para todos. A primeira partida de um estudante normalmente
começa com o loadout vazio: o PuzzleCoin se ganha vencendo, ou transferindo do PlayerHUD. As moedas
são coletadas no tabuleiro com a peça Moeda — veja [Integração com o PlayerHUD](#playerhud) para
como são compensadas com as do chefe e guardadas. O professor pode desligar as dicas em toda a
atividade.

## Progresso Salvo

Enquanto uma luta acontece, o tabuleiro, o HP e os medidores são salvos no servidor a cada poucos
segundos e quando a página é deixada. Recarregar, ou voltar mais tarde, reconstrói a luta a partir
do registro de jogadas do próprio servidor, e não do que o navegador diz.

## Verdade do Servidor

Todo número que governa o combate — o HP máximo do chefe e do estudante, e o valor base de dano
do qual Espada/Estrela/Grimório/Poção escalam — é calculado uma única vez no servidor pro nível e
fase atuais (veja [Modos de Jogo](#game-modes) pra como esses valores escalam) e entregue ao
cliente como configuração; o cliente nunca recalcula nada disso. O servidor também refaz as
jogadas do estudante para calcular o resultado real — veja [Segurança e Anti-Trapaça](#security).
