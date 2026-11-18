# ⚔️ Como o Combate Funciona

O combate roda num tabuleiro Match-3 8×8, priorizando retrato (540×960), se
adaptando a um layout paisagem em telas mais largas.

## Estrutura de Turnos

**Quem começa.** Toda fase abre com uma **pergunta inicial**, na mesma janela de um Desafio de Pergunta.
Acertou, você joga primeiro; errou, pulou ou a validação falhou, quem joga primeiro é o oponente. Ela não
muda mais nada na luta (sem dano, sem perder o multiplicador de Estrela), e uma resposta conta para o mínimo
de perguntas como qualquer outra. A Demonstração não tem pergunta inicial, e uma atividade sem nenhuma
pergunta no banco começa direto com o turno do estudante.

1. O estudante faz uma jogada no tabuleiro.
2. Combos e cascatas mantêm o turno do estudante rolando.
3. Quando o tabuleiro estabiliza, é o **Turno do Oponente**: uma IA simples varre o tabuleiro e
   executa uma troca válida. O que ela combina resolve pelas mesmas sete peças e os mesmos
   efeitos do turno do estudante (veja abaixo) — o oponente pode causar dano, acumular
   multiplicador, enfeitiçar o estudante, se proteger, se curar e acumular moeda, exatamente como
   o estudante faz no próprio turno.

## As Sete Peças

Efeitos simétricos: cada peça faz a mesma coisa não importa de qual lado está jogando — o que
muda é só quem ganha o efeito (quem estiver no turno atual).

| Peça | Efeito |
|------|--------|
| ⭐ Estrela | Soma +0,1 a um multiplicador de dano, acumulando entre turnos; consumido no acerto crítico daquele lado e resetado a 1 quando esse lado erra uma pergunta |
| 📖 Grimório | Enche um medidor de feitiço próprio de 0–100 (+10 por peça); ao chegar em 100, arma 3 rodadas de dano contra o **oponente** (uma por turno dele) e reseta, preservando o excedente |
| ❓ Orbe de Interrogação | Enche uma barra de energia de 100 pontos própria de cada lado; ao chegar em 100, pausa o combate e abre o [Desafio de Pergunta](#questions) — o estudante responde de verdade; se a "resposta" do oponente está certa é decidido no servidor, com uma chance definida pela dificuldade (veja abaixo) |
| ⚔️ Espadas | Dano direto no **oponente** — sem precisar de pergunta. Escala com nível/fase, e uma combinação de 4 ou 5 peças causa 1,5x/2x o dano de uma combinação de 3 |
| 🛡️ Escudo | Enche um medidor próprio de 0–100 (+10 por peça); ao chegar em 100, nega por completo o **próximo** golpe recebido por esse lado, não importa o valor, e reseta |
| 🧪 Poção | Cura o HP do próprio lado em um quarto do dano base por peça, então uma combinação de 3 cura três quartos do que uma combinação de 3 Espadas causa — o dano sempre pesa mais que a cura |
| 🪙 Moeda | Soma ao contador de ouro próprio da partida — veja a fórmula por combinação e a compensação líquida em [Integração com o PlayerHUD](#playerhud) |

**Efeitos de status nos lutadores.** Enquanto um Feitiço está sobre um lado, uma névoa roxa com bolhas
subindo envolve aquele lutador e uma etiqueta ao lado mostra as rodadas que faltam ("Feitiço 3"); enquanto
um bloqueio está armado, uma bolha dourada envolve o lutador e uma etiqueta diz "Escudo". As etiquetas ficam
do lado que encara o outro lutador e são tão grandes quanto o espaço permite, para serem lidas no celular.
Os dois efeitos são anunciados para leitores de tela quando começam e quando acabam, e a animação para
para quem pede movimento reduzido.

## O Desafio de Pergunta

Quando a barra de Orbe de Interrogação de qualquer um dos lados chega a 100%, a partida pausa e
abre o [modal de pergunta](#questions):

* **Resposta correta** — um acerto crítico: dano base triplicado, multiplicado pelo bônus de
  Estrela que esse lado acumulou.
* **Resposta errada** — ninguém sofre dano, mas o multiplicador de Estrela daquele lado volta pra 1.

O oponente também "responde" quando a própria barra de Orbe de Mana enche, mas nunca vê a resposta
certa: é o servidor que decide se o oponente acertou, com uma probabilidade que depende da
dificuldade (veja abaixo).

É o momento em que o plugin conecta a habilidade no Match-3 com o conhecimento real de
conteúdo: combinar peças bem enche a barra e acumula o multiplicador, mas o retorno de verdade
só vem se o estudante também responder certo.

## Mínimo de Questões e o Oponente que Resiste

O professor pode exigir um número mínimo de perguntas respondidas por partida (3 por padrão). O
Lobby avisa o estudante antes de a partida começar, e um contador mostra o progresso durante ela.
Se o oponente fosse cair antes de esse número ser atingido, ele fica com 1 HP em vez de morrer, e cada
peça de Orbe que o estudante combina enche a barra de mana inteira, então um único combo de Orbe já faz
a próxima pergunta. Cumprido o mínimo, o próximo golpe o derrota. Um golpe forte não custa nada, e uma
partida nunca pode ser vencida sem o estudante realmente encarar as perguntas.

## Dificuldade

O estudante escolhe Fácil, Normal ou Difícil. A escolha muda a luta, nunca a nota:

| | Fácil | Normal | Difícil |
|---|:---:|:---:|:---:|
| HP do oponente | ×0,5 | ×1 (chefe final +15%) | ×2 |
| Golpe base do oponente | ×0,5 | ×1 | ×2 |
| Crescimento do golpe na Campanha | nenhum | até +60% | até +100% |
| Armadura do oponente | só a dele | a dele +25 pontos ao longo da Campanha | a dele +25 pontos |
| Golpe do estudante | ×1 | ×1 | ×2 |
| Golpe crítico (uma resposta certa) | 3× o golpe de quem respondeu, vezes o seu multiplicador de Estrela | o mesmo | o mesmo |
| Moedas por combinação de 3 | ×0,5 | ×1 | ×3 |
| Oponente acerta a pergunta (Múltipla Escolha / Verdadeiro-Falso) | 33% / 50% | 66% / 75% | 100% / 100% |
| Como o oponente joga | a primeira combinação de Espadas que achar | pesa as peças (Espada e Poção valem mais) | como no Normal, e ainda valoriza mais as peças que alimentam os seus poderes (o lobo, o Orbe) |
| Itens do oponente (Poção, Espada) | nenhum | 1 por nível depois do primeiro, +1 no chefe final | 1 a mais que no Normal |
| Poderes do oponente | sim | sim | sim |
| Nota | a mesma | a mesma | a mesma |

O herói é o mesmo em toda dificuldade: o HP, o ataque e a armadura dele vêm só do que é comprado na loja. Como
exemplo, uma atividade com HP base de 250 dos dois lados, dano base de 10 e 2 níveis:

| Fase | | HP do oponente | Golpe dele | Crítico dele | Armadura dele | Golpe do estudante | Crítico do estudante |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Nível 1, Fase 1 (slime) | Fácil | 125 | 5 | 15 | 0% | 10 | 30 |
| | Normal | 250 | 10 | 30 | 0% | 10 | 30 |
| | Difícil | 500 | 20 | 60 | 0% | 20 | 60 |
| Nível 1, Fase 6 (fantasma) | Fácil | 188 | 8 | 24 | 0% | 15 | 45 |
| | Normal | 375 | 17 | 52 | 7% | 15 | 45 |
| | Difícil | 750 | 38 | 114 | 7% | 30 | 90 |
| Nível 2, Fase 10 (vampiro, chefe final) | Fácil | 360 | 12 | 36 | 15% | 24 | 72 |
| | Normal | 828 | 38 | 115 | 40% | 24 | 72 |
| | Difícil | 1440 | 96 | 288 | 40% | 48 | 144 |

Os críticos aparecem com multiplicador de Estrela ×1, antes da armadura do outro lado; os golpes do estudante, antes
do ataque do herói e da armadura do oponente.

No Normal e no Difícil o estudante precisa do mesmo número de golpes para derrubar o oponente, já que o golpe dele
também dobra; no Fácil, metade. No Difícil o golpe do oponente é de 2,2 a 2,5 vezes o do Normal e o HP do
estudante não dobra, então ele aguenta menos da metade dos golpes.

No Fácil a redução é só do oponente: o HP e os golpes dele caem pela metade enquanto os golpes, as
Poções e o feitiço do próprio estudante mantêm o valor cheio, então uma partida fácil é mais curta, e não só mais
segura. No Difícil tudo dobra para os dois lados, então a partida dura o mesmo que no Normal e o estudante tem
metade do tempo para sobreviver. No Normal o chefão final também tem 15% a mais de HP, o que o põe um pouco
acima do que um herói com todas as melhorias consegue alcançar.

O herói cresce ao longo da Campanha (HP, ataque e armadura, comprados na loja) e os poderes dos oponentes
continuam os mesmos, então do Normal para cima os golpes do oponente também crescem com o progresso da Campanha:
de nada na primeira fase a 60% a mais na última no Normal e 100% a mais no Difícil; o Fácil não cresce. A
armadura deles também cresce: todo oponente, no Normal e no Difícil, absorve 25 pontos a mais de cada golpe na última
fase do que na primeira, além da armadura que já tinha (o golem e a planta começam em 15% e terminam em 40%, os
outros vão de nada a 25%), então o herói que pôs suas compras em armadura encontra uma defesa que responde a ela. No
Difícil isso mantém a luta exigente até o fim, e as Poções, Espadas e Escudos da loja pesam muito mais lá do que no
Normal.

Na Campanha a escolha é feita a cada fase: no Lobby para a primeira, e na tela "Fase vencida"
para a seguinte.

## A Loja de Loadout e os Consumíveis

Os consumíveis são comprados **antes** da partida, na loja do Lobby, com PuzzleCoin, e usados no
painel de combate durante a luta. Cada uso sai do estoque do estudante e é limitado por fase ou
partida:

| Consumível | Efeito | Preço | Usos por fase |
|---|---|:---:|:---:|
| 🧪 Poção | Cura 1,25× o dano base | 8 | 3 |
| 🛡️ Escudo | Enche o medidor de Escudo de uma vez, armando o bloqueio do próximo golpe | 10 | 1 |
| 🔮 Magia Rápida | Enche o medidor do Grimório de uma vez, armando três rodadas de feitiço no oponente | 12 | 1 |
| ⚔️ Espada | Um ataque extra de um dano base, sem encerrar o turno | 10 | 3 |
| 💡 Dica | Mostra a dica da pergunta aberta, quando ela tem uma | 5 | sem limite fixo |

Os preços e limites são fixos, iguais para todos, e a loja mostra o limite de cada item no próprio cartão. A primeira partida de um estudante normalmente
começa com o loadout vazio: o PuzzleCoin se ganha vencendo, ou transferindo do PlayerHUD. As moedas
são coletadas no tabuleiro com a peça Moeda — veja [Integração com o PlayerHUD](#playerhud) para
como são compensadas com as do oponente e guardadas. O professor pode desligar as dicas em toda a
atividade.

## Progresso Salvo

Enquanto uma luta acontece, o tabuleiro, o HP e os medidores são salvos no servidor a cada poucos
segundos e quando a página é deixada. Recarregar, ou voltar mais tarde, reconstrói a luta a partir
do registro de jogadas do próprio servidor, e não do que o navegador diz.

## Verdade do Servidor

Todo número que governa o combate — o HP máximo do oponente e do estudante, e o valor base de dano
do qual Espada/Estrela/Grimório/Poção escalam — é calculado uma única vez no servidor pro nível e
fase atuais (veja [Modos de Jogo](#game-modes) pra como esses valores escalam) e entregue ao
cliente como configuração; o cliente nunca recalcula nada disso. O servidor também refaz as
jogadas do estudante para calcular o resultado real — veja [Segurança e Anti-Trapaça](#security).
