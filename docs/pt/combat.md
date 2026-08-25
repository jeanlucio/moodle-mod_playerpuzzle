# ⚔️ Como o Combate Funciona

O combate roda num tabuleiro Match-3 8×8 (Phaser 3), priorizando retrato (540×960), se
adaptando a um layout paisagem em telas mais largas.

## Estrutura de Turnos

1. O estudante faz uma jogada no tabuleiro.
2. Combos e cascatas mantêm o turno do estudante rolando.
3. Quando o tabuleiro estabiliza, é o **Turno do Chefe**: uma IA simples varre o tabuleiro,
   executa uma troca válida, e o que ela combina resolve da mesma forma que uma jogada do
   estudante resolveria (por exemplo, combinar Orbes de Mana carrega o próprio desafio de
   pergunta do chefe; a IA hoje não usa todos os tipos de peça disponíveis ao estudante — veja
   [Funcionalidades](#features)).

## As Sete Peças

| Peça | Efeito |
|------|--------|
| ⭐ Estrela | Soma +0.1 a um multiplicador de dano, acumulando entre turnos |
| 📖 Grimório | Aplica 3 cargas de veneno no chefe (5 de dano por carga, ativando no início do próprio turno do chefe) |
| 🔮 Orbe de Mana | Enche uma barra de energia de 100 pontos (contada separadamente pro estudante e pro chefe) |
| ⚔️ Espadas | Causa dano direto no chefe — sem precisar de pergunta |
| 🛡️ Escudo | Bloqueia os próximos pontos de dano recebido, absorvidos antes do HP |
| 🧪 Poção | Cura o HP do estudante |
| 🪙 Moeda | Soma ao contador de ouro da partida, creditado ao saldo permanente do estudante no [PlayerHUD](#playerhud) quando a partida termina |

## O Desafio de Pergunta

Quando a barra de Orbe de Mana de qualquer um dos lados chega a 100%, a partida pausa e abre o
[modal de pergunta](#questions):

* **Resposta correta** — um acerto crítico: dano base triplicado, multiplicado pelo bônus de
  Estrela que o estudante acumulou.
* **Resposta errada** — o estudante sofre dano, e o multiplicador de Estrela volta pra 1.

É o momento em que o plugin conecta a habilidade no Match-3 com o conhecimento real de
conteúdo: combinar peças bem enche a barra e acumula o multiplicador, mas o retorno de verdade
só vem se o estudante também responder certo.

## Verdade do Servidor

Todo número mostrado durante o combate — o HP máximo do chefe e do estudante pro nível e fase
atuais — é calculado uma única vez no servidor (veja [Modos de Jogo](#game-modes) pra como o HP
escala) e entregue ao cliente como configuração; o cliente nunca recalcula. O dano que o cliente
reporta de volta é limitado no servidor contra esse mesmo HP do chefe escalado pela fase antes de
ser aceito — veja [Segurança e Anti-Trapaça](#security).
