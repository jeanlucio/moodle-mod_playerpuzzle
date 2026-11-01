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

## Gerenciando Questões

Os professores (quem tem a capability *Gerenciar o banco de questões do PlayerPuzzle*) ganham uma
página **Gerenciar questões** na atividade. Ela lista cada questão com sua origem, seu status e
quantas vezes foi sorteada, e oferece:

* **Importar do Banco de Questões** — escolha uma categoria e importe suas questões aprovadas de
  Múltipla Escolha de resposta única e de Verdadeiro/Falso. Importar de novo atualiza as cópias;
  uma questão que saiu da categoria é desativada em vez de excluída, porque tentativas passadas
  ainda podem se referir a ela. Imagens e arquivos incorporados são copiados junto.
* **Adicionar uma questão** à mão, com uma dica opcional.
* **Gerar com IA** — veja abaixo.
* **Aprovar, reativar, editar ou excluir** questões, uma a uma ou em lote.

Só questões **aprovadas** são sorteadas numa partida.

## Geração por IA

Quando há uma fonte de IA disponível (o plugin AI Hub com uma chave, ou o próprio `core_ai` do
Moodle), o professor pode digitar um tema e uma quantidade de questões (até 10). O resultado
aparece como uma **prévia** editável, e só as questões que ele selecionar são salvas — como
*pendentes*. Nada que a IA escreveu chega a um estudante antes de o professor aprovar. Veja
[Divulgação de Serviço de Terceiros](#third-party) para o que é enviado.

## Dicas

Uma questão pode ter uma dica. Ela só aparece para um estudante que gasta um consumível Dica
enquanto aquela questão está aberta, e o professor pode desligar as dicas em toda a atividade.

## Mínimo de Questões por Partida

As configurações da atividade permitem que o professor exija um número mínimo de perguntas
respondidas antes que uma partida possa terminar em vitória (3 por padrão). O Lobby mostra a
exigência antes de a partida começar, um contador mostra o progresso durante ela, e o oponente revive
com metade do HP se cair cedo demais — veja [Como o Combate Funciona](#combat). A contagem é feita
pelo servidor a cada resposta, nunca reportada pelo cliente.
