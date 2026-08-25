# 🎮 Modos de Jogo

O professor escolhe um modo por instância da atividade.

## Campanha

Uma jornada contínua por até 10 **níveis**, cada um dividido em 10 **fases**. Vencer uma fase
nunca consome uma nova tentativa — só perder (ou o tempo esgotar) consome, e o limite
configurado de **Máximo de Tentativas** só conta tentativas finalizadas (perdidas/tempo
esgotado/abandonadas), nunca uma fase vencida ao longo do caminho.

* **Retomada:** fechar o navegador no meio de uma campanha e voltar retoma exatamente o
  nível/fase em que o estudante estava — o servidor rotaciona o token anti-replay e pega a
  tentativa em andamento mais recente, em vez de reiniciar no Nível 1.
* **Escala de HP:** tanto o HP máximo do chefe quanto o do estudante escalam com o nível/fase
  atual, usando uma fórmula linear fixa calculada uma vez no servidor:

  | | Fórmula |
  |---|---|
  | HP do Chefe | `base × (1 + 0.5 × (nível − 1) + 0.1 × (fase − 1))` |
  | HP do Estudante | `base × (1 + 0.3 × (nível − 1) + 0.05 × (fase − 1))` |

  O chefe escala mais rápido que o estudante — as fases mais avançadas devem parecer mais
  difíceis, não só mais longas.
* **Avançando:** ao vencer uma fase, o servidor confere se o dano reportado realmente zerou o HP
  do chefe daquela fase antes de permitir o avanço, e rotaciona o token de novo — a mesma
  requisição não pode ser reproduzida pra pular várias fases a partir de uma única vitória real.

## Partida Única

Uma única rodada autocontida — sem níveis ou fases. A nota reaproveita o padrão `grademethod`
já estabelecido pelas atividades irmãs `mod_playerwords`/`mod_playercross`: o professor define
um limite de **Máximo de Partidas** (ou deixa ilimitado) por um seletor `0`–`10`, na mesma
escala de uma rodada curta e repetível, em vez de uma campanha longa.

## Por Que Dois Modos em Vez de Um Só

Uma "tentativa" de Campanha e uma "tentativa" de Partida Única são estruturalmente diferentes —
uma jornada contínua atravessando várias fases contra rodadas independentes e repetíveis —
então o plugin modela os dois separadamente em vez de forçar um único esquema de
nota/contagem de tentativas caber nos dois casos. `question_fetcher.php`, `save_progress.php` e
o Lobby leem o `gamemode` configurado pra saber quais regras se aplicam.
