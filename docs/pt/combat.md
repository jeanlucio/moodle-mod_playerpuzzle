# ⚔️ Como o Combate Funciona

O combate roda num tabuleiro Match-3 8×8 (Phaser 3), priorizando retrato (540×960), se
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
| 🧪 Poção | Cura o HP do próprio lado — escala do mesmo jeito que o dano de Espada, na metade da taxa de uma combinação de Espada do mesmo tamanho |
| 🪙 Moeda | Soma ao contador de ouro próprio da partida — veja a fórmula por combinação e a compensação líquida em [Integração com o PlayerHUD](#playerhud) |

## O Desafio de Pergunta

Quando a barra de Orbe de Interrogação de qualquer um dos lados chega a 100%, a partida pausa e
abre o [modal de pergunta](#questions):

* **Resposta correta** — um acerto crítico: dano base triplicado, multiplicado pelo bônus de
  Estrela que esse lado acumulou.
* **Resposta errada** — esse lado sofre dano, e seu multiplicador de Estrela volta pra 1.

É o momento em que o plugin conecta a habilidade no Match-3 com o conhecimento real de
conteúdo: combinar peças bem enche a barra e acumula o multiplicador, mas o retorno de verdade
só vem se o estudante também responder certo.

## Verdade do Servidor

Todo número que governa o combate — o HP máximo do chefe e do estudante, e o valor base de dano
do qual Espada/Estrela/Grimório/Poção escalam — é calculado uma única vez no servidor pro nível e
fase atuais (veja [Modos de Jogo](#game-modes) pra como esses valores escalam) e entregue ao
cliente como configuração; o cliente nunca recalcula nada disso. O dano que o cliente reporta de
volta é limitado no servidor contra esse mesmo HP do chefe escalado pela fase antes de ser
aceito — veja [Segurança e Anti-Trapaça](#security).
