<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\rag_client;

/**
 * Step 5 — Diseño del instrumento.
 * AI generates the evaluation instrument content (questions, items, rubric).
 */
class step5 {

    public static function render(array $ctx): void {
        global $PAGE;

        $id         = $ctx['id'];
        $context    = $ctx['context'];
        $do_gen     = optional_param('do_gen', 0, PARAM_INT);
        $instrument = session_manager::get('instrument', '');
        $d2         = session_manager::get('d2', '');
        $inst_content = session_manager::get('inst_content', '');

        $link_params = ['id' => $id];

        // Generate content if requested and none cached
        if ($do_gen && empty($inst_content)) {
            $res_data = rag_client::generate([
                'course_id'         => $id,
                'step'              => 5,
                'objective'         => $d2,
                'chosen_instrument' => $instrument,
            ]);
            if ($res_data && $res_data->status == 'success') {
                $inst_content = $res_data->output;
                session_manager::set('inst_content', $inst_content);
            } else {
                $inst_content = 'Error al generar el contenido de la IA. Por favor, reintenta.';
            }
        }

        echo html_writer::tag('span', 'Paso 5 — Diseño del instrumento', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Construcción pedagógica del instrumento', ['class' => 'areteia-stitle']);

        // Instrument summary card
        echo html_writer::start_tag('div', [
            'class' => 'areteia-card',
            'style' => 'background:#f9f9f9; padding:15px; border:1px dashed #ccc; margin-bottom:15px;',
        ]);
        echo html_writer::tag('strong', "Instrumento: $instrument", [
            'style' => 'display:block; color:#185fa5;',
        ]);
        echo html_writer::tag('small', "Objetivo: $d2", ['style' => 'color:#666;']);
        echo html_writer::end_tag('div');

        if (empty($inst_content)) {
            // Generate prompt
            echo html_writer::start_tag('div', [
                'style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;',
            ]);
            echo html_writer::tag('p',
                'La IA está lista para redactar las consignas e ítems de tu evaluación.',
                ['style' => 'color:#777; margin-bottom:20px;']
            );
            $gen_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 5, 'do_gen' => 1]));
            echo html_writer::link($gen_url, '✨ Generar Diseño con IA', [
                'class' => 'areteia-btn areteia-btn-primary',
                'style' => 'padding:12px 25px;',
            ]);
            echo html_writer::end_tag('div');
        } else {
            // Show generated content
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'margin-bottom:20px; line-height:1.6; position:relative;',
            ]);
            echo html_writer::tag('p', '<strong>Propuesta de la IA:</strong>', [
                'style' => 'margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;',
            ]);

            $formatted = format_text($inst_content, FORMAT_MARKDOWN, ['context' => $context]);
            echo html_writer::tag('div', $formatted, ['class' => 'areteia-markdown-content']);

            // Inner nav
            echo html_writer::start_tag('div', [
                'class' => 'areteia-nav',
                'style' => 'margin-top:20px; border-top:1px solid #eee; padding-top:15px;',
            ]);
            echo html_writer::link(
                new moodle_url($PAGE->url, array_merge($link_params, ['step' => 4])),
                '← Volver',
                ['class' => 'areteia-btn']
            );

            // Regenerate button
            $regen_url = new moodle_url($PAGE->url, array_merge($link_params, [
                'step'         => 5,
                'do_gen'       => 1,
                'inst_content' => ' ',
            ]));
            echo html_writer::link($regen_url, 'Regenerar ✨', [
                'class' => 'areteia-btn',
                'style' => 'margin-left:auto; margin-right:10px;',
            ]);

            echo html_writer::link(
                new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6])),
                'Continuar a Rúbrica →',
                ['class' => 'areteia-btn areteia-btn-primary']
            );
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        }
    }
}
