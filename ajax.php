<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AJAX endpoint para salvar o progresso do jogo PlayerPuzzle.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/playerpuzzle/lib.php');

// 1. Recebe os dados enviados pelo Javascript.
$cmid    = required_param('cmid', PARAM_INT);
$ouro    = required_param('ouro', PARAM_INT);
$vitoria = required_param('vitoria', PARAM_INT); // 1 para Vitória, 0 para Derrota.
$dano    = required_param('dano', PARAM_INT);

// 2. Segurança: Verifica se o aluno está logado e se a requisição é legítima.
require_login();
require_sesskey();

// 3. Validações de Contexto do Moodle.
$cm = get_coursemodule_from_id('playerpuzzle', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

// AQUI COMEÇA A GRAVAÇÃO NA BASE DE DADOS.

// Busca o inventário GLOBAL do utilizador (Tabela playerpuzzle_inventory).
$inventario = $DB->get_record('playerpuzzle_inventory', ['userid' => $USER->id]);

if ($inventario) {
    // Se já jogou antes, soma as "coins" novas.
    $inventario->coins += $ouro;
    $inventario->timemodified = time();
    $DB->update_record('playerpuzzle_inventory', $inventario);
} else {
    // Primeira vez que joga, cria a carteira dele com os valores base.
    $novoinventario = new stdClass();
    $novoinventario->userid = $USER->id;
    $novoinventario->coins = $ouro;
    $novoinventario->swordlevel = 1;
    $novoinventario->shieldlevel = 1;
    $novoinventario->timecreated = time();
    $novoinventario->timemodified = time();
    $DB->insert_record('playerpuzzle_inventory', $novoinventario);
}

// Opcional Futuro: Aqui entra a chamada para atualizar a nota no Gradebook do Moodle.

// 4. Devolve a resposta de sucesso para o Javascript.
$resposta = [
    'status' => 'sucesso',
    'mensagem' => 'Progresso salvo com sucesso! Ouro ganho: ' . $ouro,
    'ouro_total' => $inventario ? $inventario->coins : $ouro,
];

echo json_encode($resposta);
die();
