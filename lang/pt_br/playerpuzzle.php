<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Brazilian Portuguese strings for PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['basebosshp'] = 'HP base do chefe';
$string['basebosshp_help'] = 'HP do chefe no Nível 1, Fase 1. Escala automaticamente nas fases seguintes.';
$string['basestudenthp'] = 'HP base do estudante';
$string['basestudenthp_help'] = 'HP do estudante no Nível 1, Fase 1. Escala automaticamente nas fases seguintes.';
$string['bossansweredcorrect'] = '✓ {$a} (O chefe acertou!)';
$string['bossansweredwrong'] = '✗ {$a} (O chefe errou!)';
$string['bossavatar'] = 'Avatar do chefe';
$string['bosscorrectfeedback'] = '💥 O chefe acertou! Você sofre {$a} de dano!';
$string['bossdamage'] = 'Dano do chefe';
$string['bosssettings'] = 'Configurações do chefe';
$string['bosstrigger'] = '👹 O chefe ativou uma pergunta!';
$string['bosswrongfeedback'] = '😅 O chefe errou! Você teve sorte!';
$string['btnattack'] = '⚔️ Atacar!';
$string['btncontinue'] = 'Continuar';
$string['btnexit'] = '✕ Sair';
$string['btnexitgame'] = '🚪 Sair do jogo';
$string['btnplayagain'] = '🎮 Jogar novamente';
$string['btnquit'] = 'Desistir';
$string['close'] = 'Fechar';
$string['coinscollected'] = 'Moedas coletadas:';
$string['considererrors'] = 'Considerar erros no cálculo da nota';
$string['considererrors_help'] = 'Quando habilitado, o número de respostas erradas entra no cálculo da nota final, além de só verificar se o Mínimo de Perguntas foi atingido. Só fica disponível quando "Mínimo de perguntas por partida" está definido como 1 ou mais.';
$string['courseinstanceslist'] = 'Todas as atividades PlayerPuzzle deste curso serão listadas aqui.';
$string['defeat'] = '💔 DERROTA 💔';
$string['error_hud_cost_qty'] = 'A quantidade precisa ser pelo menos 1 quando um item está configurado.';
$string['exitwarning'] = "Sair agora vai\napagar seu progresso!";
$string['expand'] = '[ Expandir ]';
$string['gamemode'] = 'Modo de jogo';
$string['gamemode_campaign'] = 'Campanha';
$string['gamemode_help'] = 'Campanha: a instância é um jogo completo com níveis e fases, com o HP escalando automaticamente conforme o estudante avança. Partida Única: a instância é uma única partida autocontida e repetível, sem níveis nem fases.';
$string['gamemode_single'] = 'Partida Única';
$string['general'] = 'Configurações gerais';
$string['grademethod'] = 'Método de avaliação';
$string['grademethod_average'] = 'Nota média';
$string['grademethod_average_all'] = 'Média sobre todas as partidas obrigatórias';
$string['grademethod_first'] = 'Primeira tentativa';
$string['grademethod_help'] = 'Define como a nota final é calculada a partir das tentativas de partida do estudante (só no modo Partida Única): <ul><li><strong>Nota mais alta:</strong> a melhor pontuação entre todas as tentativas.</li><li><strong>Nota média:</strong> a média apenas das tentativas de fato realizadas.</li><li><strong>Primeira tentativa:</strong> a pontuação apenas da primeira tentativa.</li><li><strong>Última tentativa:</strong> a pontuação apenas da tentativa mais recente.</li><li><strong>Média sobre todas as partidas obrigatórias:</strong> a soma das pontuações das tentativas dividida pelo máximo de partidas configurado, de modo que qualquer partida não tentada conta como zero. Exige que o máximo de partidas não esteja definido como Ilimitado.</li></ul>';
$string['grademethod_highest'] = 'Nota mais alta';
$string['grademethod_last'] = 'Última tentativa';
$string['hpboss'] = 'Chefe:';
$string['hpyou'] = 'Você:';
$string['hud_coin_item'] = 'Item que representa as moedas';
$string['hud_coin_item_help'] = 'As moedas restantes de uma tentativa vencedora são concedidas como unidades deste item do PlayerHUD. Se não configurado, as moedas são descartadas mesmo quando a partida termina em vitória.';
$string['hud_header'] = 'Integração com o PlayerHUD';
$string['hud_item_deleted'] = 'Item excluído (reconfigure)';
$string['hud_item_disabled'] = '{$a} (desativado)';
$string['hud_noitem'] = 'Nenhum configurado';
$string['hud_notincourse'] = 'A integração com o PlayerHUD aparecerá aqui assim que o bloco PlayerHUD for adicionado a este curso.';
$string['hud_potion_item'] = 'Item que representa a Poção';
$string['hud_potion_item_help'] = 'Opcional. Cada unidade possuída deste item do PlayerHUD equivale a 1 uso consumível de Poção durante o combate, coexistindo com a compra por moeda local.';
$string['hud_retry_cost_item'] = 'Item de custo por retentativa';
$string['hud_retry_cost_item_help'] = 'Opcional. Cobrado ao clicar em "Tentar Novamente" após uma derrota, a partir da segunda tentativa — a primeiríssima tentativa é sempre grátis. Nunca amplia o limite de tentativas/partidas configurado, só cobra um pedágio dentro desse limite. Considere configurar um item que o estudante conquiste através de conteúdo de reforço fora do jogo, não um item ganho jogando o próprio PlayerPuzzle.';
$string['hud_retry_cost_qty'] = 'Quantidade por retentativa';
$string['hud_shield_item'] = 'Item que representa o nível de Escudo';
$string['hud_shield_item_help'] = 'A quantidade deste item do PlayerHUD que o estudante possui é lida como o nível de melhoria do Escudo.';
$string['hud_sword_item'] = 'Item que representa o nível de Espada';
$string['hud_sword_item_help'] = 'A quantidade deste item do PlayerHUD que o estudante possui é lida como o nível de melhoria da Espada.';
$string['hud_win_grant_item'] = 'Item concedido por vitória';
$string['hud_win_grant_item_help'] = 'Concedido toda vez que uma partida é vencida (cada fase, no modo Campanha), separado do saldo de moedas. Nenhum XP é concedido por este item quando o limite de tentativas relevante estiver definido como Ilimitado, seguindo a mesma regra antifarm do próprio PlayerHUD para drops infinitos.';
$string['hud_win_grant_qty'] = 'Quantidade concedida por vitória';
$string['invalidattempttoken'] = 'Esta tentativa é inválida ou já foi enviada.';
$string['levelsandphases'] = 'Níveis e fases';
$string['loading'] = 'Carregando...';
$string['loadinggame'] = 'Carregando o motor do jogo...';
$string['lobby_coinbalance'] = 'Moedas: {$a}';
$string['lobby_currentprogress'] = 'Progresso atual: Nível {$a->level}, Fase {$a->phase}';
$string['lobby_minquestions_notice'] = 'Esta partida exige responder pelo menos {$a} pergunta(s).';
$string['lobby_shieldlevel'] = 'Nível de Escudo: {$a}';
$string['lobby_swordlevel'] = 'Nível de Espada: {$a}';
$string['lobbywelcome'] = 'Bem-vindo ao Lobby! A loja e o botão Jogar estarão aqui em breve.';
$string['max_single_matches'] = 'Número de Partidas Únicas';
$string['maxattempts'] = 'Número máximo de tentativas';
$string['maxattemptsreached'] = 'Você já usou todas as tentativas disponíveis para esta atividade.';
$string['maxlevels'] = 'Número de níveis';
$string['maxlevels_help'] = 'Cada nível contém 10 fases de dificuldade crescente. 1 nível = 10 fases; 10 níveis = 100 fases.';
$string['maxmultiplier'] = 'Multiplicador máximo:';
$string['maxsinglematchesreached'] = 'Você já usou todas as partidas disponíveis para esta atividade.';
$string['minquestions'] = 'Mínimo de perguntas por partida';
$string['minquestions_help'] = 'O estudante precisa responder pelo menos essa quantidade de perguntas (certas ou erradas) antes que a partida possa terminar em vitória. Se o chefe chegar a 0 de HP antes do contador atingir esse valor, o chefe revive com 50% do HP e o combate continua. Defina como 0 para desativar essa exigência.';
$string['modulename'] = 'PlayerPuzzle';
$string['modulename_help'] = 'A atividade PlayerPuzzle permite que o professor crie um jogo RPG Match-3 onde os estudantes combinam gemas para derrotar um chefe, respondem perguntas e ganham moedas.';
$string['modulenameplural'] = 'PlayerPuzzles';
$string['musicoff'] = '🔇 Música';
$string['musicon'] = '🎵 Música';
$string['name'] = 'Nome da fase';
$string['noanswers'] = 'Nenhuma resposta disponível.';
$string['nocategories'] = 'Nenhuma categoria de questões encontrada.';
$string['nonextphase'] = 'Não há próxima fase para avançar — termine a campanha em vez disso.';
$string['phasenotwon'] = 'O dano reportado não elimina o HP do chefe desta fase.';
$string['playercorrect'] = '✓ Correto! O chefe sofre dano!';
$string['playerpuzzle:addinstance'] = 'Adicionar um novo PlayerPuzzle';
$string['playerpuzzle:view'] = 'Visualizar atividade PlayerPuzzle';
$string['playerwrong'] = '✗ Errado! Você sofre {$a} de dano!';
$string['playgame'] = 'Jogar';
$string['pluginadministration'] = 'Administração do PlayerPuzzle';
$string['pluginname'] = 'PlayerPuzzle';
$string['privacy:metadata:bosshp_remaining'] = 'O HP restante do chefe quando esta tentativa terminou.';
$string['privacy:metadata:currentlevel'] = 'O nível que o usuário estava jogando quando esta tentativa foi registrada.';
$string['privacy:metadata:currentphase'] = 'A fase que o usuário estava jogando quando esta tentativa foi registrada.';
$string['privacy:metadata:playerpuzzle_attempts'] = 'Armazena cada tentativa de um estudante numa atividade PlayerPuzzle, incluindo progresso e resultado.';
$string['privacy:metadata:questions_correct'] = 'O número de perguntas respondidas corretamente nesta tentativa.';
$string['privacy:metadata:questions_total'] = 'O número total de perguntas feitas nesta tentativa.';
$string['privacy:metadata:score'] = 'A nota final calculada para esta tentativa.';
$string['privacy:metadata:status'] = 'O status desta tentativa: em andamento, vitória, derrota, tempo esgotado ou abandonada.';
$string['privacy:metadata:timecreated'] = 'O momento em que este registro foi criado.';
$string['privacy:metadata:timefinished'] = 'O momento em que a tentativa terminou.';
$string['privacy:metadata:timemodified'] = 'O momento em que este registro foi modificado pela última vez.';
$string['privacy:metadata:userid'] = 'O ID do usuário ao qual este registro pertence.';
$string['progressindicator'] = 'Nível {$a->level} — Fase {$a->phase} de 10';
$string['progresssaved'] = 'Progresso salvo! (Total de moedas: {$a})';
$string['questioncategory'] = 'Categoria de perguntas';
$string['questionchallenge'] = '⚔️ Desafio Mágico!';
$string['questionerror'] = 'Erro na pergunta.';
$string['questionsettings'] = 'Configurações de perguntas';
$string['requirejserror'] = 'Erro crítico: não foi possível carregar o motor do jogo.';
$string['rules'] = 'Regras do jogo';
$string['saveerror'] = 'Erro ao salvar no servidor.';
$string['savingprogress'] = 'Salvando progresso...';
$string['sfxoff'] = '🔈 Efeitos';
$string['sfxon'] = '🔊 Efeitos';
$string['shrink'] = '[ Reduzir ]';
$string['shuffling'] = 'EMBARALHANDO...';
$string['singlematchheader'] = 'Partida Única';
$string['skip'] = 'Pular';
$string['studentsettings'] = 'Configurações do estudante';
$string['timelimit'] = 'Limite de tempo (minutos)';
$string['timelimit_help'] = 'Defina como 0 para sem limite de tempo.';
$string['unlimited'] = 'Ilimitado';
$string['victory'] = '🌟 VITÓRIA! 🌟';
