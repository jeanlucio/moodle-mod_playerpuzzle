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
página **Gerenciar questões** na atividade. Ela lista cada questão com as respostas, o tipo e a origem,
o nível e a fase a que se destina, o status e quantas vezes foi sorteada, e oferece:

* **Importar do Banco de Questões** — um botão ao lado de *Adicionar questão* abre o painel de
  importação; escolha uma categoria e importe suas questões aprovadas de
  Múltipla Escolha de resposta única e de Verdadeiro/Falso. Importar de novo atualiza as cópias;
  uma questão que saiu da categoria é desativada em vez de excluída, porque tentativas passadas
  ainda podem se referir a ela. Imagens e arquivos incorporados são copiados junto. A lista de categorias mostra quantas questões
  cada uma importaria, sem contar as que a importação ignora (outros tipos de questão, várias
  alternativas corretas ou nenhuma).
* **Adicionar uma questão** à mão, com uma dica opcional.
* **Gerar com IA** — veja abaixo.
* **Aprovar, reativar, editar ou excluir** questões, uma a uma ou em lote.
* **Definir o nível e a fase** para os quais uma questão serve, uma a uma no formulário da questão
  ou nas questões selecionadas de uma vez — veja abaixo.

Só questões **aprovadas** são sorteadas numa partida. Uma questão já respondida numa fase não é perguntada
de novo nessa fase enquanto o conjunto tiver outra; quando todas já foram feitas, voltam a entrar.

**Localizar e ordenar.** A lista pode ser filtrada por nível (na Campanha), por fase (inclusive
*Sem nível definido* e *Sem fase definida*) e por status (aprovadas, pendentes, desativadas), e
ordenada por mais recentes, nível e fase, status, mais sorteadas, tipo ou fonte. Os filtros se
aplicam assim que mudam e acompanham cada ação, então aprovar, editar ou definir o nível ou a fase
numa lista filtrada traz o professor de volta à mesma lista. As ações em lote (aprovar, definir
nível, definir fase, excluir) aparecem numa barra assim que há questões marcadas.

## Questões por nível e por fase

Na Campanha, cada questão pode servir a um **nível** e a uma **fase** de nível (todo nível tem
10). Os dois são independentes e começam em *Qualquer*, então o professor só preenche o que quiser
usar:

| O professor define | Para quê | Exemplo |
|---|---|---|
| Só o nível | Um conteúdo por nível: uma unidade, um capítulo | O nível 2 pergunta sobre o capítulo 2 |
| Só a fase | Dificuldade crescente dentro de cada nível | As fáceis abrem o nível, as difíceis o fecham |
| Os dois | Uma questão para um ponto exato da Campanha | A questão do chefão do nível 3 |

Cada fase é jogada com as questões cujo nível e cuja fase batem com ela ou são *Qualquer*. Quando
nenhuma bate, o jogo sorteia entre todas as questões aprovadas, então uma fase que ninguém preparou
nunca fica sem questões. A Partida Única e a Demo não têm níveis nem fases e sorteiam sempre entre
todas as aprovadas. Uma nova importação do banco nunca altera o nível nem a fase de uma questão.

Os níveis oferecidos vão até o número de níveis da atividade. Se o professor diminuir esse número
depois, as questões marcadas para um nível que deixou de existir nunca são sorteadas, e a lista de
questões avisa quantas são.

## Geração por IA

Quando há uma fonte de IA disponível (o plugin AI Hub com uma chave, ou o próprio `core_ai` do
Moodle), o professor pode digitar um tema, uma quantidade de questões (até 10) e o tipo delas:
*Múltipla escolha e Verdadeiro ou Falso*, só *Múltipla escolha* ou só *Verdadeiro ou Falso*. Na Campanha,
também pode escolher o nível e a fase para os quais as questões são salvas. O resultado aparece como
uma **prévia** editável, e só as questões que ele selecionar são salvas — como *pendentes*. Nada que a IA escreveu chega a um estudante antes de o professor aprovar. Veja
[Divulgação de Serviço de Terceiros](#third-party) para o que é enviado.

## Dicas

Uma questão pode ter uma dica. Ela só aparece para um estudante que gasta um consumível Dica
enquanto aquela questão está aberta, e o professor pode desligar as dicas em toda a atividade.

## Mínimo de Questões por Partida

As configurações da atividade permitem que o professor exija um número mínimo de perguntas
respondidas antes que uma partida possa terminar em vitória (3 por padrão). O Lobby mostra a
exigência antes de a partida começar, um contador mostra o progresso durante ela, e o oponente fica
com 1 HP se cair cedo demais — veja [Como o Combate Funciona](#combat). A contagem é feita
pelo servidor a cada resposta, nunca reportada pelo cliente.
