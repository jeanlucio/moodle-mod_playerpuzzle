# 💰 Integração com o PlayerHUD

O PlayerPuzzle **não tem nenhuma economia própria permanente**. Moedas e níveis de upgrade vivem
inteiramente no [block_playerhud](#ecosystem), e o PlayerPuzzle só lê/escreve nele quando o
bloco está presente no curso — o plugin funciona sozinho, sem nenhuma configuração, se ele não
estiver.

## Itens Escolhidos pelo Professor, Nunca Auto-Detectados

Pra cada curso, o professor escolhe explicitamente quais itens do próprio bloco representam:

* **Moedas** — creditadas quando uma partida é vencida (o ouro coletado durante o combate é
  depositado aqui).
* **Nível de espada** — lido como um nível de upgrade pro dano do estudante.
* **Nível de escudo** — lido como um nível de upgrade pra defesa do estudante.
* **Poção** — opcional; também pode ser consumida de um estoque permanente do PlayerHUD, em vez
  de (ou junto com) a compra local por moeda de sessão.
* **Custo de retentativa** — opcional; cobrado a partir da 2ª tentativa do estudante, sem nunca
  ampliar o limite de tentativas configurado. Pensado pra ser conquistado através de conteúdo de
  reforço fora do jogo (um vídeo, outra atividade), não jogando o PlayerPuzzle em si — assim o
  estudante que mais precisa de tentativas não é também o que menos consegue pagar por elas.
* **Prêmio de vitória** — opcional; um item fixo concedido a cada vitória, além das moedas.

Cada um desses é um simples dropdown dos itens já configurados no bloco — o mesmo padrão já
usado pelas atividades irmãs `mod_playerwords`/`mod_playercross` — então adicionar essa
integração nunca exigiu nenhuma mudança no `block_playerhud` em si.

## Ganho de Moeda e a Compensação do Chefe

Combinar a peça Moeda (veja [Como o Combate Funciona](#combat)) rende moedas numa curva de
combo: uma combinação de 3 rende o valor base configurado em "Ganho de Moeda", uma de 4 rende
1,5x esse valor, e uma de 5 rende 2x — a mesma curva que o dano da Espada usa. Diferente de todo
o resto do combate, o Ganho de Moeda deliberadamente **não** escala por nível ou fase, então o
professor pode configurar preços fixos de consumíveis (Poção, Escudo, Magia Rápida, Dica) que
continuam equilibrados durante toda a campanha, em vez de ficarem proporcionalmente mais baratos
conforme ela avança.

O chefe também acumula sua própria conta de Moeda quando combina a peça no próprio turno — ele
não tem loja e nunca gasta esse valor. Seu único propósito é compensar o saldo do estudante ao
fim da partida: a contagem final de moedas do estudante é `max(0, moedas do estudante − moedas
do chefe)`, creditada via PlayerHUD só em vitória. Isso dá à IA do chefe um motivo pra "competir"
pela mesma peça que o estudante quer, em vez de ela ficar inerte sempre que a IA combiná-la.

## O Que Já Está Ligado Hoje

* ✅ Creditar moedas na vitória.
* ✅ Ler o nível de upgrade de espada/escudo do estudante
  (`hud_service::get_upgrade_level()`).
* ⏳ Aplicar esse nível de upgrade como um multiplicador de dano/defesa de verdade durante o
  combate — o leitor existe e está testado; o `combat.js` ainda não o consome.
* ⏳ Itens de Poção/custo de retentativa/prêmio de vitória — configuráveis hoje, ainda não
  consumidos pela jogabilidade.

Veja [Funcionalidades](#features) pra a divisão completa entre implementado e planejado.
