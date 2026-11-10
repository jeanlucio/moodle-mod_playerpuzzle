# ♿ Acessibilidade

## O Desenho

O jogo é desenhado com elementos HTML comuns num palco que se ajusta à página, e não num canvas, então
não há um desenho opaco que a tecnologia assistiva deixe passar. As peças, o cenário e os efeitos ficam
escondidos dos leitores de tela, e o jogo é operado por controles reais e rotulados, e não por uma
camada de última hora:

* Uma `<table role="grid">` real fica sobre o tabuleiro 8×8, e suas células são as mesmas em que o
  mouse e o toque agem. Ela é navegável célula a célula com as setas, e cada célula é lida com o nome
  da peça. O contorno de foco só aparece para quem usa o teclado. **Enter** seleciona a peça da célula em
  foco (anunciada e marcada com `aria-selected`), e Enter numa célula vizinha troca as duas; Enter na mesma
  peça desfaz a seleção. As teclas numéricas abaixo fazem uma jogada sem percorrer a grade.
* A área do jogo é uma região rotulada (com o nome da atividade), e não uma região de aplicação, então o
  leitor de tela mantém o modo de navegação normal ao redor do tabuleiro e dos botões.
* Os botões (desistir, ajuda, música, efeitos sonoros, tela cheia, usar item) são elementos `<button>`
  reais com nomes acessíveis; música, efeitos sonoros e tela cheia também informam se estão ligados ou
  desligados (`aria-pressed`). O histórico e o balão do tutorial não são lidos em voz alta, então a
  região viva abaixo continua sendo o único canal.
* No início do turno do estudante, as jogadas disponíveis são anunciadas por uma região
  `aria-live="polite"`, que também carrega os demais eventos do turno — dano causado e recebido,
  barra de energia cheia, uma pergunta respondida, o oponente revivendo, uma fase avançada.
* As teclas numéricas `1`–`9` executam a jogada anunciada correspondente, e `Espaço` relê a lista
  de jogadas disponíveis.
* O desafio de pergunta é um `<dialog>` HTML nativo: o foco é preso e centralizado pelo próprio
  navegador, e devolvido ao elemento que o disparou quando ele fecha. Quando abre, o foco vai para a
  pergunta, então o leitor de tela começa pelo que está sendo perguntado e as respostas vêm em seguida,
  por Tab. A resposta escolhida é exposta como pressionada (`aria-pressed`). Apertar Atacar mantém o foco
  onde está até a resposta ser julgada, e então um botão Continuar separado, novo na página, o recebe: o teclado
  nunca é jogado para fora do diálogo, e o leitor de tela encontra o Continuar como um controle novo, e não como
  um antigo que mudou. Tudo fora de um
  diálogo modal aberto fica inerte e não é ouvido, então enquanto um diálogo está aberto os anúncios vão
  para uma região viva dentro dele.
* O Lobby, a loja, o ranking e o relatório são páginas e formulários comuns do Moodle, então
  seguem o comportamento de acessibilidade do próprio tema.

## Emoji e som

* Os emojis dos textos do jogo ficam fora da árvore de acessibilidade (cada um vai num `span` com
  `aria-hidden`) e são removidos do que é anunciado ou falado, então um botão lê "Atacar!" e não
  "espadas cruzadas Atacar!".
* A música fica desligada até o estudante ligar, e os efeitos sonoros ficam ligados: o navegador não
  consegue saber se um leitor de tela está rodando, e uma música em laço por baixo da voz dele é o pior
  padrão. Quem já escolheu mantém a escolha. Os dois têm botões no jogo (com o estado) e chaves no Lobby.

## Narração Falada

Os anúncios também podem ser falados pela Web Speech API do navegador, com a opção **Narrar os
anúncios do jogo em voz alta** nas configurações do Lobby. Ela vem desligada por padrão, para
nunca competir com o leitor de tela que o estudante já usa, e a escolha é lembrada por usuário.

## Testes

Uma feature Behat lê os anúncios e joga pelo teclado usando apenas os passos padrão do Moodle. Ela
prova o mecanismo; conferir com um leitor de tela de verdade (NVDA, JAWS, VoiceOver) continua
valendo a pena para o timing e a pronúncia, que nenhum framework de teste captura.
