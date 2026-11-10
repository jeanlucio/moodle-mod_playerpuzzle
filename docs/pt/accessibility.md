# ♿ Acessibilidade

## O Desenho

O jogo é desenhado com elementos HTML comuns num palco que se ajusta à página, e não num canvas, então
não há um desenho opaco que a tecnologia assistiva deixe passar. As peças, o cenário e os efeitos ficam
escondidos dos leitores de tela, e o jogo é operado por controles reais e rotulados, e não por uma
camada de última hora:

* Uma `<table role="grid">` real fica sobre o tabuleiro 8×8, e suas células são as mesmas em que o
  mouse e o toque agem. Ela é navegável célula a célula com as setas, e cada célula é lida com o nome
  da peça. O contorno de foco só aparece para quem usa o teclado.
* Os botões (desistir, ajuda, música, efeitos sonoros, tela cheia, usar item) são elementos `<button>`
  reais com nomes acessíveis. O histórico e o balão do tutorial não são lidos em voz alta, então a
  região viva abaixo continua sendo o único canal.
* No início do turno do estudante, as jogadas disponíveis são anunciadas por uma região
  `aria-live="polite"`, que também carrega os demais eventos do turno — dano causado e recebido,
  barra de energia cheia, uma pergunta respondida, o oponente revivendo, uma fase avançada.
* As teclas numéricas `1`–`9` executam a jogada anunciada correspondente, e `Espaço` relê a lista
  de jogadas disponíveis.
* O desafio de pergunta é um `<dialog>` HTML nativo: o foco é preso e centralizado pelo próprio
  navegador, e devolvido ao elemento que o disparou quando ele fecha.
* O Lobby, a loja, o ranking e o relatório são páginas e formulários comuns do Moodle, então
  seguem o comportamento de acessibilidade do próprio tema.

## Narração Falada

Os anúncios também podem ser falados pela Web Speech API do navegador, com a opção **Narrar os
anúncios do jogo em voz alta** nas configurações do Lobby. Ela vem desligada por padrão, para
nunca competir com o leitor de tela que o estudante já usa, e a escolha é lembrada por usuário.

## Testes

Uma feature Behat lê os anúncios e joga pelo teclado usando apenas os passos padrão do Moodle. Ela
prova o mecanismo; conferir com um leitor de tela de verdade (NVDA, JAWS, VoiceOver) continua
valendo a pena para o timing e a pronúncia, que nenhum framework de teste captura.
