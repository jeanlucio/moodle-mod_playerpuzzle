# 🎮 Modos de Jogo

O professor escolhe um modo por instância da atividade.

## Campanha

Uma jornada contínua por até 10 **níveis**, cada um dividido em 10 **fases**. Vencer uma fase nunca
gasta uma nova tentativa — só uma derrota gasta, e o limite de **Número máximo de tentativas** configurado
conta apenas as tentativas que terminaram em derrota, nunca uma fase vencida pelo caminho.

* **Retomada:** fechar o navegador no meio da campanha e voltar retoma exatamente o nível e a fase
  em que o estudante estava. Depois de uma derrota, a próxima tentativa retoma nesse mesmo nível e
  fase, em vez de recomeçar do zero.
* **Escalonamento de HP:** o HP máximo do adversário e do estudante cresce com o nível e a fase atuais,
  por uma fórmula linear fixa calculada uma única vez no servidor:

  | | Fórmula |
  |---|---|
  | HP do adversário | `base × (1 + 0,5 × (nível − 1) + 0,1 × (fase − 1))` |
  | HP do estudante | `base × (1 + 0,3 × (nível − 1) + 0,05 × (fase − 1))` |

  O adversário escala mais rápido que o estudante — as fases finais devem parecer mais difíceis, não só
  mais longas. O dano do adversário escala do mesmo jeito; a recompensa em moedas não (veja
  [Combate](#combat)).
* **Avanço:** depois de vencer uma fase, o servidor confere se a luta reportada realmente zerou o
  HP do adversário daquela fase, rotaciona o token, e o estudante segue para a próxima. A mesma
  requisição não pode ser reproduzida para pular várias fases a partir de uma vitória.
* **Dificuldade por fase:** o estudante escolhe a dificuldade no Lobby para a primeira fase e de
  novo, a cada tela "Fase concluída", para a seguinte.

## Partida Única

Uma rodada única e autocontida — sem níveis nem fases, sempre lutada no HP base configurado. O
professor define quantas partidas um estudante pode jogar (de 1 a 10, ou ilimitado) e pode
acrescentar um **cooldown** (em minutos, horas ou dias) entre as partidas. O estudante escolhe a
dificuldade no Lobby a cada partida.

## Nota

A nota é sempre escalada pela **Nota Máxima** da atividade (pontos, ou sem nota — escalas não são
suportadas), nunca por um 100 fixo, e nunca muda com a dificuldade escolhida.

**Campanha**

```
nota = (fase mais avançada vencida ÷ total de fases configuradas) × Nota Máxima
```

"Fase mais avançada vencida" é o maior nível/fase que o estudante alcançou com uma vitória, em
todas as suas tentativas — não uma contagem de tentativas vencidas, porque uma única tentativa
contínua pode cobrir dezenas de fases.

**Partida Única** — cada partida vale a Nota Máxima (vitória) ou zero (derrota), e o **Método de
avaliação** do professor combina as partidas: maior, média, primeira, última, ou média sobre todas
as partidas exigidas (uma partida não jogada conta como zero).

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
