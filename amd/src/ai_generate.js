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
 * "Generate with AI" modal for managequestions.php: asks for a topic/count, previews the
 * generated batch, and only saves whichever entries the teacher leaves checked.
 *
 * @module     mod_playerpuzzle/ai_generate
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {get_string as getString, get_strings as getStrings} from 'core/str';

/**
 * The last batch generate_questions() returned, so save only resends what the teacher
 * left checked instead of re-parsing the rendered preview HTML back into data.
 *
 * @type {Array}
 */
let generatedQuestions = [];

/**
 * Builds a compact "✓ correct · wrong · wrong" summary of one question's answers.
 *
 * @param {Object} question A generated question, as returned by generate_questions().
 * @return {string}
 */
const buildAnswersPreview = (question) => question.answers
    .map((answer) => (answer.iscorrect ? `✓ ${answer.text}` : answer.text))
    .join(' · ');

/**
 * Renders the generated batch into the results region, one checkbox-guarded entry per
 * question, all checked by default.
 *
 * @param {HTMLElement} resultsRegion Container to render the preview list into.
 * @param {Array} questions The generated batch.
 * @return {Promise<void>}
 */
const renderResults = async(resultsRegion, questions) => {
    generatedQuestions = questions;

    const shaped = await Promise.all(questions.map(async(question, index) => ({
        index,
        qtypelabel: await getString(`qtype_${question.qtype}`, 'mod_playerpuzzle'),
        questiontext: question.questiontext,
        hashint: question.hint !== '',
        hint: question.hint,
        answerspreview: buildAnswersPreview(question),
    })));

    const context = {
        hasquestions: shaped.length > 0,
        noresultslabel: await getString('noquestionsgenerated', 'mod_playerpuzzle'),
        savelabel: await getString('savegeneratedquestions', 'mod_playerpuzzle'),
        questions: shaped,
    };

    const {html, js} = await Templates.renderForPromise('mod_playerpuzzle/ai_generate_results', context);
    Templates.replaceNodeContents(resultsRegion, html, js);
};

/**
 * Calls mod_playerpuzzle_generate_questions and renders the preview, or shows the topic
 * field as invalid when left empty.
 *
 * @param {HTMLElement} modalBody The modal's root content element.
 * @param {number} cmid Course module id.
 * @return {Promise<void>}
 */
const runGenerate = async(modalBody, cmid) => {
    const topicInput = modalBody.querySelector('[data-region="topic"]');
    const countSelect = modalBody.querySelector('[data-region="count"]');
    const statusRegion = modalBody.querySelector('[data-region="status"]');
    const resultsRegion = modalBody.querySelector('[data-region="results"]');

    const topic = topicInput.value.trim();
    if (topic === '') {
        topicInput.classList.add('is-invalid');
        topicInput.focus();
        return;
    }
    topicInput.classList.remove('is-invalid');

    statusRegion.textContent = await getString('generatingquestions', 'mod_playerpuzzle');
    resultsRegion.replaceChildren();

    try {
        const result = await Ajax.call([{
            methodname: 'mod_playerpuzzle_generate_questions',
            args: {
                cmid,
                topic,
                count: parseInt(countSelect.value, 10),
                qtype: modalBody.querySelector('[data-region="qtype"]').value,
            },
        }])[0];
        statusRegion.textContent = '';
        await renderResults(resultsRegion, result.questions);
    } catch (error) {
        statusRegion.textContent = '';
        // A failed generation (no AI provider available, or the call itself failing) is
        // an anticipated workflow outcome the server already explains via its own
        // moodle_exception message, not an unexpected bug — Notification.alert() shows
        // that message directly, reserving Notification.exception() for a real failure.
        Notification.alert(await getString('generatewithai', 'mod_playerpuzzle'), error.message);
    }
};

/**
 * Calls mod_playerpuzzle_save_generated_questions with only the checked entries, then
 * reloads the page so the newly saved (pending-approval) questions show up in the list.
 *
 * @param {HTMLElement} modalBody The modal's root content element.
 * @param {number} cmid Course module id.
 * @param {Modal} modal The open modal, to hide before reloading.
 * @return {Promise<void>}
 */
const saveSelected = async(modalBody, cmid, modal) => {
    const checkboxes = [...modalBody.querySelectorAll('[data-region="keep"]')];
    const selected = checkboxes
        .filter((checkbox) => checkbox.checked)
        .map((checkbox) => generatedQuestions[parseInt(checkbox.dataset.index, 10)]);

    if (selected.length === 0) {
        return;
    }
    // The level and phase the batch is saved for, any outside a Campaign, which offers neither.
    const placement = region => {
        const select = modalBody.querySelector(`[data-region="${region}"]`);
        return select ? parseInt(select.value, 10) : 0;
    };

    try {
        await Ajax.call([{
            methodname: 'mod_playerpuzzle_save_generated_questions',
            args: {cmid, questions: selected, level: placement('level'), phase: placement('phase')},
        }])[0];
        await modal.hide();
        window.location.reload();
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * The options of a level or phase select: any, then each one up to the last.
 *
 * @param {string} prefix 'questionlevel' or 'questionphase'.
 * @param {number} last The last level or phase.
 * @return {Promise<Array>} Each {value, label}.
 */
const placementOptions = async(prefix, last) => {
    const component = 'mod_playerpuzzle';
    const keys = [{key: `${prefix}_any`, component}];
    for (let value = 1; value <= last; value++) {
        keys.push({key: `${prefix}_n`, component, param: value});
    }
    const labels = await getStrings(keys);
    return labels.map((label, value) => ({value, label}));
};

/**
 * Builds and opens the "Generate with AI" modal.
 *
 * @param {number} cmid Course module id.
 * @param {number} maxlevels The Campaign's number of levels, 0 outside a Campaign.
 * @return {Promise<void>}
 */
const openGenerateModal = async(cmid, maxlevels) => {
    const counts = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((value) => ({value, selected: value === 5}));
    const component = 'mod_playerpuzzle';
    const [qtypelabel, both, multichoice, truefalse] = await getStrings([
        {key: 'ai_qtype', component},
        {key: 'ai_qtype_both', component},
        {key: 'qtype_multichoice', component},
        {key: 'qtype_truefalse', component},
    ]);
    const context = {
        topiclabel: await getString('aitopic', component),
        countlabel: await getString('aicount', component),
        generatelabel: await getString('generatebutton', component),
        counts,
        qtypelabel,
        qtypes: [
            {value: 'both', label: both, selected: true},
            {value: 'multichoice', label: multichoice, selected: false},
            {value: 'truefalse', label: truefalse, selected: false},
        ],
        hasplacement: maxlevels > 0,
    };
    if (context.hasplacement) {
        // A level and a phase of the Campaign, 10 phases in every level.
        context.levellabel = await getString('questionlevel', component);
        context.levels = await placementOptions('questionlevel', maxlevels);
        context.phaselabel = await getString('questionphase', component);
        context.phases = await placementOptions('questionphase', 10);
        context.placementnote = await getString('ai_placement_note', component);
    }
    const {html, js} = await Templates.renderForPromise('mod_playerpuzzle/ai_generate_form', context);

    const modal = await Modal.create({
        title: await getString('generatewithai', 'mod_playerpuzzle'),
        body: html,
        show: true,
        removeOnClose: true,
    });
    Templates.runTemplateJS(js);

    const modalBody = modal.getRoot()[0];

    modalBody.addEventListener('click', (event) => {
        if (event.target.closest('[data-action="pp-ai-run-generate"]')) {
            runGenerate(modalBody, cmid);
        } else if (event.target.closest('[data-action="pp-ai-save-selected"]')) {
            saveSelected(modalBody, cmid, modal);
        }
    });
};

/**
 * Wires the "Generate with AI" trigger button on managequestions.php.
 *
 * @param {number} cmid Course module id.
 * @param {number} maxlevels The Campaign's number of levels, 0 outside a Campaign.
 */
export const init = (cmid, maxlevels = 0) => {
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-action="pp-ai-generate"]')) {
            openGenerateModal(cmid, maxlevels);
        }
    });
};
