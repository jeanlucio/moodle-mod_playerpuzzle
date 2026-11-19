# 💰 Integração com o PlayerHUD

O PlayerPuzzle tem uma moeda persistente própria, o **PuzzleCoin**, então funciona por completo
sem mais nada instalado. O [block_playerhud](#ecosystem) é um companheiro **opcional**: quando ele
está no curso, o professor pode ligar três coisas a ele. O plugin só lê ou escreve no bloco quando
uma delas está configurada.

## PuzzleCoin

As moedas são coletadas no tabuleiro com a peça Moeda. Uma combinação de 3 peças rende o **Ganho de
moeda** configurado (10 por padrão), uma de 4 peças rende 1,5× isso, e uma de 5 peças 2× — a mesma
curva que o dano de Espada usa. Diferente de todo outro valor de combate, o ganho de moeda
deliberadamente **não** escala por nível ou fase, para que os preços fixos da loja continuem
equilibrados durante toda a campanha; só a dificuldade o muda (metade no Fácil, o dobro no Difícil).

O oponente acumula as próprias moedas quando combina a peça Moeda no turno dele. Ele não tem loja e
nunca as gasta; elas só se compensam com as do estudante: o valor guardado é
`max(0, moedas do estudante − moedas do oponente)`, e só numa **vitória** (cada fase vencida, na
Campanha). Uma derrota não guarda nada, e uma partida Demo nunca guarda. O resultado soma-se ao
saldo de PuzzleCoin do estudante, gasto na [loja de loadout](#combat).

## O Que o Professor Pode Ligar

Cada um destes é uma lista simples dos itens configurados no próprio bloco — o mesmo padrão que as
atividades irmãs `mod_playerwords`/`mod_playercross` usam — então a integração nunca exigiu
mudança no `block_playerhud`.

* **Item que representa moedas** — permite que um estudante *transfira* unidades desse item para
  PuzzleCoin a partir da loja, escolhendo quantas (1:1); a troca é uma linha própria abaixo do saldo de PuzzleCoin, escrita
  como frase: o item com o saldo do PlayerHUD e depois "Transferir [quantidade] para PuzzleCoin (1:1)". Normalmente a PlayerCoin do próprio
  PlayerHUD, mas qualquer item serve. É a única forma de as moedas do PlayerHUD chegarem ao
  PuzzleCoin: as vitórias não são mais creditadas automaticamente no PlayerHUD.
* **Custo de nova tentativa** — opcional; um item (e quantidade) cobrado a partir da 2ª tentativa de
  cada Campanha (a primeira tentativa de toda Campanha é grátis; na Partida Única, a partir da 2ª
  partida), sem nunca ampliar o limite de tentativas configurado. O Lobby mostra o custo e o saldo do
  estudante antes do Jogar, e um início recusado por falta do item abre um diálogo que diz o que falta. É pensado para ser conquistado com conteúdo
  de reforço fora do jogo, para que o estudante que mais sofre não seja o que menos pode pagar por
  outra tentativa. Um estudante que só está retomando uma luta nunca é cobrado.
* **Prêmio de vitória** — opcional; um item fixo concedido a cada vitória, além das moedas. Quando
  o limite de tentativas relevante é ilimitado, o item ainda é concedido, mas sem o XP dele, para
  evitar farm de XP. Na Campanha ele é concedido a cada fase vencida, então, com limite definido, o
  painel de economia do PlayerHUD ("Total XP no jogo") conta a atividade como fases × Campanhas × XP
  do item (a Partida Única conta um prêmio por partida permitida), como o `mod_playerwords` e o
  `mod_playercross` já fazem.

Se o `block_playerhud` não estiver instalado, ou for mais antigo que a versão que esta integração
exige (v1.7.1), o formulário avisa e as três opções simplesmente não são oferecidas.
