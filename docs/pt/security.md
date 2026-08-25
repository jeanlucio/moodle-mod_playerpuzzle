# 🔐 Segurança e Anti-Trapaça

Todo número de combate que o estudante vê é uma afirmação que o cliente faz sobre si mesmo. O
PlayerPuzzle não trata nada disso como confiável até o servidor confirmar, através de três
pilares:

## 1. Servidor Como Fonte da Verdade

O HP do chefe e do estudante pro nível/fase atuais são calculados uma única vez, no servidor
(`combat::calculate_boss_hp()`/`calculate_student_hp()`), e entregues ao cliente como
configuração somente-leitura. O cliente nunca os recalcula.

## 2. Dano Verificado por Sanidade

Qualquer dano que o cliente reporte ao fim de uma partida é limitado contra o HP real do chefe,
já escalado pela fase, antes de ser aceito — nunca o HP base, e nunca o que o cliente afirma que
deveria ser o teto. Avançar uma fase (`advance_phase`) aplica a mesma regra: o servidor exige
que o dano reportado tenha realmente zerado o HP do chefe daquela fase antes de permitir a
transição.

Uma checagem de sanidade mais completa — considerando multiplicadores de upgrade do PlayerHUD e
tempo de jogo decorrido — está desenhada, mas ainda não implementada; veja
[Funcionalidades](#features).

## 3. Tokens Anti-Replay de Uso Único

Toda chamada que altera estado (iniciar uma partida, retomar uma tentativa de campanha, avançar
uma fase) emite um token novo e invalida o anterior. Uma requisição capturada não pode ser
reproduzida pra disparar a mesma recompensa duas vezes — retomar uma campanha no meio da
jornada rotaciona o token exatamente como terminar uma faz, então a garantia se mantém mesmo
numa sessão que atravessa vários carregamentos de página, não só dentro de uma única partida.

## JSON Cego

O id da resposta correta nunca é enviado ao cliente — veja [Motor de Questões](#questions) pra
como isso é garantido na camada de dados, não só na interface.
