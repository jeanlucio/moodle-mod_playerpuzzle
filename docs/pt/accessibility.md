# ♿ Acessibilidade

## O Desenho

A jogabilidade Match-3 vive num Canvas, que é opaco pra leitores de tela por natureza. A
abordagem de acessibilidade do PlayerPuzzle é uma camada HTML paralela, sempre presente, em vez
de um complemento pensado depois:

* Uma `<table role="grid">` visualmente oculta espelha o estado do tabuleiro, navegável célula a
  célula.
* Eventos jogada a jogada (jogadas disponíveis, dano causado, HP restante do chefe, barra de
  energia cheia, uma pergunta respondida) são anunciados por uma região `aria-live="polite"`,
  então um usuário de leitor de tela recebe a mesma informação que um jogador vidente lê no HUD.
* As teclas numéricas `1`–`9` executam a jogada correspondentemente anunciada; `Espaço` relê a
  lista de jogadas disponíveis.
* `aria-live="assertive"` é reservado pra eventos que interrompem o turno (por exemplo, sofrer
  dano de uma carga de veneno).

## O Que Já Está Implementado Hoje

* ✅ **O modal de pergunta** — o momento em que a jogabilidade mais precisa ser acessível — é um
  `<dialog>` HTML nativo, não uma sobreposição de Canvas. Abri-lo captura o foco e centraliza
  usando o próprio comportamento do navegador; fechá-lo restaura o foco pro elemento que o
  abriu.

## O Que Está Planejado

* ⏳ O tabuleiro HTML paralelo (`<table role="grid">`), os anúncios `aria-live` jogada a jogada,
  e o esquema de entrada por teclas numéricas descritos acima estão desenhados, mas ainda não
  construídos.
* ⏳ Um anúncio de avanço de fase ("Você avançou para o Nível 5, Fase 2") assim que a tela de
  debrief pós-partida existir — veja [Funcionalidades](#features).

O `speechSynthesis` nunca é usado como canal padrão de anúncio, pra evitar conflito com
qualquer leitor de tela que o estudante já esteja usando.
