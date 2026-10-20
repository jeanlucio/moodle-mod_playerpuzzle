# 🎯 Motor de Questões

## Fonte

Cada atividade tem o seu próprio banco de questões, preenchido por três fontes na página
*Gerenciar questões*:

* **Banco de Questões do Moodle** (`core_question`) — o professor importa uma categoria já
  existente, e qualquer questão de Múltipla Escolha de resposta única ou de Verdadeiro/Falso nela
  é copiada para a atividade. Um professor que já mantém um banco de questões pro Quiz reaproveita
  na hora, sem migrar conteúdo nenhum. Importar de novo atualiza as cópias.
* **Cadastro manual** — questões escritas direto na atividade.
* **Geração por IA** — opcional, sempre revisada pelo professor antes de qualquer coisa ser
  salva, e salva como pendente até ser aprovada. Veja
  [Divulgação de Serviço de Terceiros](#third-party).

Só questões aprovadas são sorteadas numa partida.

## JSON Cego — a Resposta Nunca Vaza

O texto da pergunta e todas as opções de resposta são enviados ao navegador, mas **o id da
resposta correta não é**. O `question_fetcher.php` remove esse dado no servidor antes da
pergunta sequer chegar ao cliente. Quando o estudante envia uma escolha, o
`mod_playerpuzzle_validate_answer` confere contra o registro real no banco de dados e devolve só
um booleano mais (numa resposta errada) a opção correta — então nada no código-fonte da página
ou no tráfego de rede revela a resposta com antecedência. Esse é o primeiro dos
[três pilares anti-trapaça](#security) do plugin.

## Mínimo de Questões por Partida

As configurações da atividade permitem que o professor exija um número mínimo de perguntas
respondidas antes que uma partida possa terminar em vitória, e o Lobby mostra essa exigência ao
estudante antes de ele começar. A regra de jogo que aplica isso de fato — reviver o chefe com
metade do HP se o mínimo ainda não foi atingido — ainda não está implementada; veja
[Funcionalidades](#features).
