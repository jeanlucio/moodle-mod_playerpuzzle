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

## O Que Já Está Ligado Hoje

* ✅ Creditar moedas na vitória.
* ✅ Ler o nível de upgrade de espada/escudo do estudante
  (`hud_service::get_upgrade_level()`).
* ⏳ Aplicar esse nível de upgrade como um multiplicador de dano/defesa de verdade durante o
  combate — o leitor existe e está testado; o `combat.js` ainda não o consome.
* ⏳ Itens de Poção/custo de retentativa/prêmio de vitória — configuráveis hoje, ainda não
  consumidos pela jogabilidade.

Veja [Funcionalidades](#features) pra a divisão completa entre implementado e planejado.
