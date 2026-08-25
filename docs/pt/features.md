# ✨ Funcionalidades

## ✅ Implementado

* ⚔️ **Combate por Turnos em Match-3:** Um tabuleiro 8×8 (Phaser 3) onde o estudante troca peças
  para atacar um chefe. Combinar peças de "mana" carrega um desafio de pergunta; uma resposta
  correta causa dano extra. Veja [Como o Combate Funciona](#combat).
* 🎮 **Dois Modos de Jogo:** **Campanha** (uma jornada contínua por até 10 níveis × 10 fases
  cada, onde vencer nunca consome uma nova tentativa — só perder consome) e **Partida Única**
  (uma rodada autocontida reaproveitando o padrão `grademethod` do ecossistema PlayerGames). Veja
  [Modos de Jogo](#game-modes).
* 📈 **Escala Linear de HP:** o HP do chefe e do estudante escalam com o nível/fase atual usando
  uma fórmula linear fixa (`base × (1 + taxa × (nível−1) + taxa × (fase−1))`), calculada uma vez
  no servidor e nunca recalculada no cliente.
* 🔄 **Retomada de Campanha:** fechar o navegador no meio de uma campanha e voltar retoma
  exatamente o nível/fase em que o estudante estava — o servidor rotaciona o token anti-replay e
  preserva o progresso de forma transparente.
* 🎯 **Integração com o Banco de Questões Real:** as questões vêm do próprio Banco de Questões do
  Moodle (`core_question`), reaproveitando categorias que o professor já tem no curso — veja
  [Motor de Questões](#questions).
* 🛡️ **Anti-Trapaça em Três Pilares:** JSON Cego (a resposta correta nunca chega ao cliente antes
  do servidor validar), clamp de dano no servidor contra o HP do chefe escalado pela fase, e
  tokens anti-replay de uso único, rotacionados a cada chamada que altera estado.
* 🗨️ **Modal de Pergunta Acessível:** o desafio de pergunta é renderizado como um `<dialog>` HTML
  nativo — captura de foco, tratamento de ESC e centralização vêm todos do próprio navegador,
  com o foco salvo e restaurado pro elemento que abriu o modal.
* 📊 **Indicador de Progresso no HUD:** "Nível X — Fase Y de 10" exibido durante o combate da
  Campanha.
* 💰 **Integração de Economia com o PlayerHUD:** totalmente opcional. Quando o
  [block_playerhud](#ecosystem) está presente no curso, o professor escolhe quais itens do
  próprio bloco representam moedas, nível de espada e nível de escudo — vencer uma partida
  credita moedas, e um item configurável pode ser concedido a cada vitória (com o XP suprimido
  quando o limite de tentativas está ilimitado, pra evitar farming). Sem o PlayerHUD, o plugin
  continua funcionando sozinho, sem nenhuma progressão permanente.
* 🏛️ **Lobby:** mostra o saldo do estudante no PlayerHUD (só os itens que o professor
  configurou), o progresso atual da Campanha, e o aviso configurado de mínimo de questões antes
  da partida começar.
* 🔐 **Privacidade (LGPD/GDPR):** Privacy Provider completo — declaração de metadados,
  exportação e exclusão de todos os dados pessoais armazenados.
* 🧪 **Testes Automatizados:** suíte PHPUnit com 104 casos, verde em toda a matriz de CI — veja a
  seção [Testes Automatizados](#testing).

## ⏳ Em Desenvolvimento / Planejado

* 🧮 **Multiplicador de Combate do PlayerHUD:** `hud_service::get_upgrade_level()` já lê o nível
  de espada/escudo do estudante, mas o `combat.js` ainda não aplica isso como multiplicador de
  dano/defesa durante a partida.
* 🎬 **Debrief Pós-Partida:** uma tela de revisão não-automática ao vencer uma fase — todas as
  perguntas respondidas, certo vs. escolhido, dano causado, moedas ganhas — com botões "Jogar
  Próxima Fase" / "Sair". O endpoint `advance_phase` que alimenta a transição de "próxima fase"
  já existe; a tela em si ainda não.
* 🛍️ **Consumíveis Durante o Combate:** Escudo, Magia Rápida, Dica e Poção, compráveis no meio da
  partida com um saldo de moedas local à sessão (a Poção também poderá ser consumida de um
  estoque permanente do PlayerHUD).
* ❤️‍🩹 **Revive do Chefe / Aplicação do Mínimo de Questões:** a configuração de "mínimo de
  questões por partida" já existe no formulário da atividade e mostra um aviso no Lobby, mas a
  regra de jogo em si (reviver o chefe com metade do HP se o mínimo ainda não foi atingido) ainda
  não está implementada.
* 🎓 **Integração com o Livro de Notas:** `grade_item_update()`/`update_grades()`, escaladas pela
  Nota Máxima configurada — as duas fórmulas de nota (baseada em progresso pra Campanha,
  baseada em `grademethod` pra Partida Única) já estão desenhadas, mas ainda não são enviadas
  pro livro de notas do Moodle.
* ✅ **Regras de Conclusão Customizadas:** `FEATURE_COMPLETION_HAS_RULES` hoje é declarada
  `false` (honestamente, em vez de anunciar uma funcionalidade quebrada) até a implementação
  real existir.
* 💾 **Backup e Restauração:** `FEATURE_BACKUP_MOODLE2` hoje é `false` pelo mesmo motivo.
* 📋 **Relatório do Professor:** uma página de relatório dedicada resumindo o desempenho da
  turma.
* ♿ **Camada Completa de Acessibilidade:** uma camada HTML paralela, sempre presente (uma
  `<table role="grid">` visualmente oculta espelhando o tabuleiro, anúncios `aria-live` jogada a
  jogada, entrada por teclas numéricas `1`–`9`) pra jogar via leitor de tela sem tocar no
  Canvas. O modal de pergunta já segue esse desenho; o resto do loop de combate ainda não.
* 📚 **Banco de Questões Próprio + Geração com IA:** uma segunda fonte de questões, própria do
  PlayerPuzzle (cadastro manual ou geração assistida por IA via `local_aihub`), coexistindo com
  o Banco de Questões real em vez de substituí-lo.
* 🏆 **Ranking:** um placar separado (não vinculado à nota configurada), com agregação própria
  por modo de jogo.
* 🥊 **Modo Disputa (V2):** um futuro modo de confronto direto — arquitetura ainda não fechada.

<p class="page-hint">O plugin é um software em estágio Alpha: tudo em "Implementado" acima
funciona hoje e é coberto pela suíte de testes automatizados; tudo em "Planejado" está
desenhado (ver o roteiro interno do projeto), mas ainda não construído.</p>
