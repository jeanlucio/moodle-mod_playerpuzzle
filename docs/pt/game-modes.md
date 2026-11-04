# 🎮 Modos de Jogo

O professor escolhe um modo por instância da atividade.

## Campanha

Uma jornada contínua por até 10 **níveis**, cada um dividido em 10 **fases**. Vencer uma fase nunca
gasta uma nova tentativa — só uma derrota gasta. O **Número máximo de tentativas** vale para cada
Campanha.

* **Jogar de novo:** uma Campanha termina quando a última fase é vencida ou quando as tentativas dela
  acabam (fica com o progresso que alcançou). O estudante pode então começar outra Campanha no Nível
  1, Fase 1, até o **Número máximo de Campanhas** (de 1 a 10, ou ilimitado, o padrão). Uma Campanha
  nova começa com a PuzzleCoin e os consumíveis do estudante zerados, por isso o Lobby pede
  confirmação antes.
* **Retomada:** fechar o navegador no meio da campanha e voltar retoma exatamente o nível e a fase
  em que o estudante estava. Depois de uma derrota, a próxima tentativa retoma nesse mesmo nível e
  fase, em vez de recomeçar do zero.
* **Escalonamento do oponente:** o HP máximo do oponente cresce com o nível e a fase atuais, por uma
  fórmula linear fixa calculada uma única vez no servidor:

  | | Fórmula |
  |---|---|
  | HP do oponente | `base × (1 + 0,5 × (nível − 1) + 0,1 × (fase − 1))` |

  O dano do oponente escala do mesmo jeito; a recompensa em moedas não (veja [Combate](#combat)). O
  estudante não cresce sozinho: ele acompanha comprando melhorias para o herói (veja
  [Heróis](#herois)), e o HP dele parte do **HP base do estudante** da atividade.
* **Avanço:** depois de vencer uma fase, o servidor confere se a luta reportada realmente zerou o
  HP do oponente daquela fase, rotaciona o token, e o estudante segue para a próxima. A mesma
  requisição não pode ser reproduzida para pular várias fases a partir de uma vitória.
* **Dificuldade por fase:** o estudante escolhe a dificuldade no Lobby para a primeira fase e de
  novo, a cada tela "Fase concluída", para a seguinte.

## Heróis

Na Campanha o estudante escolhe um de seis heróis numa tela própria, a primeira da atividade, e fica com
ele até o fim daquela Campanha; uma Campanha nova pergunta de novo. Na Partida Única e na Demo o herói é só
o visual do estudante, e a Partida Única abre a escolha com o último herói usado.

* **Especialidade:** cada herói é especialista em HP (os magos), ataque (os guerreiros) ou armadura (os
  arqueiros), e começa um pouco à frente nela, um vigésimo do que aquele atributo pode alcançar.
* **Melhorias:** depois de cada fase, e pelo Lobby, o estudante gasta PuzzleCoin em melhorias de HP, ataque
  ou armadura (12 moedas cada). O que ele compra vale a partir da próxima fase.
* **Estágios e evolução:** o herói tem quatro visuais. Cada estágio limita quantas melhorias de cada
  atributo podem ser compradas, um quarto do total da Campanha por estágio (o total cresce com o número de
  níveis). Em 25%, 50% e 75% da Campanha o herói evolui de graça, assim que a especialidade dele está no
  teto do estágio, o que libera o teto do estágio seguinte.
* **A loja:** o mesmo painel, com os consumíveis, no Lobby e na tela entre as fases.
* **O cartão do oponente:** a loja abre com o cartão do próximo oponente: a imagem, o nome, onde ele é
  enfrentado na Campanha e o que o diferencia (HP extra, golpes mais fortes, armadura, os poderes e, quando
  a fase já existe, os itens que ele traz). O cartão do chefão final diz também que ele se adapta ao seu
  herói. O estudante pode recolher o cartão. Durante a luta, o botão **?** no alto do palco abre o mesmo
  cartão, para reler os poderes depois do primeiro encontro, quando o aviso do jogo já não aparece.

## Partida Única

Uma rodada única e autocontida — sem níveis nem fases, sempre lutada no HP base configurado. O
professor define quantas partidas um estudante pode jogar (de 1 a 10, ou ilimitado) e pode
acrescentar um **cooldown** (em minutos, horas ou dias) entre as partidas. O estudante escolhe a
dificuldade no Lobby a cada partida.

## Nota

A nota é sempre escalada pela **Nota Máxima** da atividade (pontos, ou sem nota — escalas não são
suportadas), nunca por um 100 fixo, e nunca muda com a dificuldade escolhida.

**Campanha** — cada Campanha é pontuada sozinha:

```
nota da Campanha = (fase mais avançada vencida ÷ total de fases configuradas) × Nota Máxima
```

"Fase mais avançada vencida" é o maior nível/fase que o estudante alcançou com uma vitória naquela
Campanha, em todas as tentativas dela — não uma contagem de tentativas vencidas, porque uma única
tentativa contínua pode cobrir dezenas de fases. O **Método de avaliação** do professor combina as
Campanhas: maior, média, primeira, última, ou média sobre todas as Campanhas exigidas. A primeira
Campanha conta desde o início; cada uma das seguintes só quando termina, então começar outra nunca
baixa a nota.

**Partida Única** — cada partida vale a Nota Máxima (vitória) ou zero (derrota), e o **Método de
avaliação** do professor combina as partidas do mesmo jeito (na média sobre todas as partidas
exigidas, uma partida não jogada conta como zero).

**Considerar erros** — disponível quando a atividade exige um mínimo de questões. Na Campanha, a
taxa de progresso é calculada em média com a parcela de perguntas respondidas corretamente; na
Partida Única, uma vitória é ponderada pela taxa de acerto daquela partida.

A nota é enviada ao livro de notas após cada fase ou partida, então nunca fica esperando o fim de
uma campanha, e é recalculada para todos quando uma configuração de avaliação muda.

## Por Que Dois Modos em Vez de Um

Uma "tentativa" de Campanha e uma "tentativa" de Partida Única são estruturalmente diferentes — uma
jornada contínua por várias fases contra rodadas independentes e repetíveis —, então o plugin as
modela separadamente em vez de forçar um único esquema de nota e contagem de tentativas a servir
para as duas.
