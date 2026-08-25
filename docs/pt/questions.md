# 🎯 Motor de Questões

## Fonte

O PlayerPuzzle lê as questões do próprio **Banco de Questões** do Moodle (`core_question`) — o
professor escolhe uma categoria já existente nas configurações da atividade, e qualquer questão
de Múltipla Escolha ou Verdadeiro/Falso nela pode ser usada durante o combate. Isso significa que
um professor que já mantém um banco de questões pro Quiz pode apontar o PlayerPuzzle pra mesma
categoria e reaproveitá-la na hora, sem migrar conteúdo nenhum.

Uma segunda fonte de questões, própria do PlayerPuzzle (cadastro manual mais geração assistida
por IA), está planejada pra coexistir ao lado do Banco de Questões real — veja
[Funcionalidades](#features).

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
