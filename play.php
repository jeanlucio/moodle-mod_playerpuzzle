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
 * The gameplay controller page.
 *
 * @package    mod_playerpuzzle
 * @copyright  2026 Jean Lúcio <jeanlucio@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('playerpuzzle', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$playerpuzzle = $DB->get_record('playerpuzzle', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/playerpuzzle:view', $context);

// Page configuration.
$PAGE->set_url('/mod/playerpuzzle/play.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($playerpuzzle->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Clean layout for gaming (hides blocks if the theme supports it).
$PAGE->set_pagelayout('incourse');
$PAGE->blocks->show_only_fake_blocks();

// 1. ENGINE CALLS (Security).
$token = \mod_playerpuzzle\local\engine\security::generate_attempt_token((int)$playerpuzzle->id, (int)$USER->id);

// BUSCA DE QUESTÕES E RESPOSTAS (Moodle 4.0+).
$categoryid = (int)$playerpuzzle->questioncategory;

$sql = "SELECT q.id, q.name, q.questiontext
          FROM {question} q
          JOIN {question_versions} qv ON qv.questionid = q.id
          JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
         WHERE qbe.questioncategoryid = :categoryid
           AND qv.status = 'ready'";

$questions = $DB->get_records_sql($sql, ['categoryid' => $categoryid]);

if ($questions) {
    foreach ($questions as $q) {
        $answers = $DB->get_records('question_answers', ['question' => $q->id], 'id ASC', 'id, answer, fraction');
        $q->answers = $answers ? array_values($answers) : [];
    }
}

// 2. JAVASCRIPT INJECTION (AMD).

// AS VARIÁVEIS QUE HAVIAM SUMIDO ESTÃO AQUI DE VOLTA.
$spriteurls = [];
for ($i = 0; $i < 7; $i++) {
    $spriteurls[] = $OUTPUT->image_url('sprites/item' . $i, 'mod_playerpuzzle')->out(false);
}

$bossbasename = str_replace('.png', '', $playerpuzzle->bossavatar);
$bossurl = $OUTPUT->image_url('bosses/' . $bossbasename, 'mod_playerpuzzle')->out(false);
$bgurl = $OUTPUT->image_url('bg_landscape', 'mod_playerpuzzle')->out(false);
// -------------------------------------------------------------

$jsconfig = [
    'cmid' => $cm->id,
    'token' => $token,
    'bosshp' => $playerpuzzle->bosshp,
    'bossdamage' => $playerpuzzle->bossdamage,
    'bossavatar' => $playerpuzzle->bossavatar,
    'bossurl' => $bossurl,
    'bgurl' => $bgurl,
    'spriteurls' => $spriteurls,
    'questions' => array_values($questions),
];

// Carrega o Phaser globalmente ANTES do nosso módulo AMD.
$PAGE->requires->js(new moodle_url('/mod/playerpuzzle/javascript/phaser.min.js'));

// O Moodle só chama o script, sem passar dados pesados por parâmetro.
$PAGE->requires->js_call_amd('mod_playerpuzzle/game_boot', 'init', []);

// 3. TEMPLATE RENDER DATA.
$templatedata = [
    'gametitle' => format_string($playerpuzzle->name),
    'loadingtext' => get_string('loadinggame', 'mod_playerpuzzle'),
    // Passamos o JSON diretamente para o HTML.
    'gameconfig' => json_encode($jsconfig),
];

// OUTPUT.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_playerpuzzle/game_layout', $templatedata);
echo $OUTPUT->footer();
