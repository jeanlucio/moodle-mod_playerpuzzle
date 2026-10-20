# 🔎 Divulgação de Serviço de Terceiros

O PlayerPuzzle funciona sem nenhum serviço externo. A jogabilidade nunca faz requisição de rede, e
as questões que o estudante vê vêm do próprio Banco de Questões do Moodle (veja
[Motor de Questões](#questions)) ou do banco de questões da própria atividade.

## Geração opcional de questões por IA

O único recurso que chega a um serviço externo é o gerador de questões por IA, **opcional**, na
página *Gerenciar questões*. Ele só está disponível a quem tem a capability
`mod/playerpuzzle:managequestions`, e só quando há uma fonte de IA disponível.

* **O que é enviado:** um prompt composto pelo tema digitado pelo professor, pela quantidade de
  questões pedida (no máximo 10), pelo nome do idioma da interface e por instruções fixas de
  formato. Nenhum dado de estudante nem conteúdo do curso é incluído.
* **Para onde vai:** pelo plugin companheiro
  [local_aihub](https://github.com/jeanlucio/moodle-local_aihub) quando ele está instalado e tem
  uma chave configurada (traga sua própria chave), e senão pelo subsistema `core_ai` do próprio
  Moodle, onde a configuração "Habilitar ferramentas de IA" do curso ou da atividade é respeitada.
  O provedor é, portanto, o que o administrador configurou no hub ou no `core_ai`, e os termos e o
  custo desse provedor se aplicam.
* **O que acontece com a resposta:** ela é tratada como entrada não confiável. Cada questão é
  validada e reformatada, uma entrada malformada é descartada, e o que sobra é salvo como
  *pendente* (`approved = 0`). Uma questão pendente nunca é sorteada numa partida até o professor
  aprová-la.
* **Sem fonte de IA:** o gerador não é oferecido, e o cadastro manual e a importação do Banco de
  Questões continuam funcionando.
