# 🔐 Segurança e Anti-Trapaça

Todo número de combate que o estudante vê é uma afirmação que o cliente faz sobre si mesmo. O
PlayerPuzzle não trata nada disso como confiável até o servidor confirmar, através de três
pilares:

## 1. Servidor Como Fonte da Verdade

O HP do adversário e do estudante pro nível/fase atuais são calculados uma única vez, no servidor
(`combat::calculate_boss_hp()`/`calculate_student_hp()`), e entregues ao cliente como
configuração somente-leitura. O cliente nunca os recalcula.

## 2. Resultados Verificados

Limitar um número não é o mesmo que saber que ele foi ganho jogando, então o servidor calcula o
resultado por conta própria. Quando uma partida começa, ele emite uma semente aleatória, e o
tabuleiro é gerado a partir dela dos dois lados. O cliente registra cada troca e cada resultado de
pergunta, e envia o registro junto com o checkpoint que já salva a cada poucos segundos; o servidor
**refaz** o registro com a sua própria cópia das regras e deriva o dano, o HP, as moedas e o
resultado reais.

* O valor creditado é sempre o que o servidor derivou — uma afirmação inflada é ignorada (e
  registrada como um evento `replay_diverged`, para que uma divergência sistemática fique
  visível).
* Uma vitória que o servidor não consegue confirmar reinicia a fase, e a tentativa continua
  contando. Se não restar nenhum reinício, conta como derrota. Uma luta que a reprodução não
  consegue seguir (`replay_inconclusive`) credita só o que o registro ainda consegue verificar.
* Se cada pergunta foi respondida corretamente vem sempre do julgamento do próprio servidor, nunca
  do registro do cliente.
* As moedas são controladas por um livro-razão no servidor, com um teto de plausibilidade ligado ao
  dano causado.
* Avançar de fase exige, além disso, que o dano reportado zere o HP do adversário daquela fase.
* Visitantes (a conta de visitante, ou um usuário logado que está no curso apenas por acesso de
  visitante) só podem jogar a Demo: nada do que fazem é avaliado ou guardado.

As regras do cliente e do servidor são mantidas idênticas por vetores de teste compartilhados: uma
execução sem navegador do motor JavaScript real produz os tabuleiros e cascatas esperados, e a
versão em PHP precisa coincidir com eles bit a bit.

## 3. Tokens Anti-Replay de Uso Único

Toda chamada que altera estado (iniciar uma partida, retomar uma tentativa de campanha, avançar
uma fase) emite um token novo e invalida o anterior. Uma requisição capturada não pode ser
reproduzida pra disparar a mesma recompensa duas vezes — retomar uma campanha no meio da
jornada rotaciona o token exatamente como terminar uma faz, então a garantia se mantém mesmo
numa sessão que atravessa vários carregamentos de página, não só dentro de uma única partida. As
operações econômicas (consumir um token, avançar de fase, comprar ou transferir moedas) também
rodam sob uma trava atômica por tentativa ou por estudante, então duas requisições paralelas não
conseguem ambas ter sucesso.

## JSON Cego

O id da resposta correta nunca é enviado ao cliente — veja [Motor de Questões](#questions) pra
como isso é garantido na camada de dados, não só na interface.
