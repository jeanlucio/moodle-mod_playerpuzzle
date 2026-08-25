# 🔎 Divulgação de Serviço de Terceiros

**Não aplicável hoje.** O PlayerPuzzle não chama nenhum serviço externo atualmente — toda
questão vem do próprio Banco de Questões do Moodle (veja [Motor de Questões](#questions)), e
nenhuma requisição de rede sai do servidor como parte da jogabilidade.

## Planejado

Uma segunda fonte de questões — cadastro manual mais geração assistida por IA **opcional**
através do plugin companheiro
[local_aihub](https://github.com/jeanlucio/moodle-local_aihub), recorrendo ao próprio `core_ai`
do Moodle — está desenhada para uma fase futura (veja [Funcionalidades](#features)). Quando ela
existir, esta seção vai divulgar os provedores exatos, o que é transmitido, e qualquer
implicação de custo, seguindo o mesmo padrão já documentado pelo
[local_playergames](https://jeanlucio.github.io/moodle-local_playergames/pt.html#security) e
pelo próprio [local_aihub](https://github.com/jeanlucio/moodle-local_aihub). Assim como
nesses plugins, a geração por IA será totalmente opcional e nunca vai incluir dados de
estudante num prompt.
