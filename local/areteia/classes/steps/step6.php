<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\rag_client;

/**
 * Step 6 — Instrumento de calificación.
 * AI generates a rubric for the previously designed instrument.
 */
class step6 {

    public static function render(array $ctx): void {
        global $PAGE;

        $id             = $ctx['id'];
        $context        = $ctx['context'];
        $do_gen         = optional_param('do_gen', 0, PARAM_INT);
        $d2             = session_manager::get('d2', '');
        $inst_content   = session_manager::get('inst_content', '');
        $rubric_content = session_manager::get('rubric_content', '');

        $link_params = ['id' => $id];

        // Generate rubric if requested and none cached
        if ($do_gen && empty($rubric_content)) {
            $res_data = rag_client::generate([
                'course_id'          => $id,
                'step'               => 6,
                'objective'          => $d2,
                'instrument_content' => $inst_content,
            ]);
            if ($res_data && $res_data->status == 'success') {
                $rubric_content = $res_data->output;
                session_manager::set('rubric_content', $rubric_content);
            } else {
                $rubric_content = 'Error al generar la rúbrica.';
            }
        }

        echo html_writer::tag('span', 'Paso 6 — Instrumento de calificación', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Definición de criterios de evaluación', ['class' => 'areteia-stitle']);

        if (empty($rubric_content)) {
            // Generate prompt
            echo html_writer::start_tag('div', [
                'style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;',
            ]);
            echo html_writer::tag('p',
                'AreteIA puede crear una rúbrica personalizada para tu instrumento.',
                ['style' => 'color:#777; margin-bottom:20px;']
            );
            $gen_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6, 'do_gen' => 1]));
            echo html_writer::link($gen_url, '✨ Generar Rúbrica con IA', [
                'class' => 'areteia-btn areteia-btn-primary',
            ]);
            echo html_writer::end_tag('div');
        } else {
            // Show generated rubric
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'margin-bottom:20px;']);
            echo html_writer::tag('p', '<strong>Rúbrica Propuesta:</strong>', [
                'style' => 'margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;',
            ]);

            $formatted = format_text($rubric_content, FORMAT_MARKDOWN, ['context' => $context]);
            echo html_writer::tag('div', $formatted, ['class' => 'areteia-markdown-content']);

            // Inner nav
            echo html_writer::start_tag('div', [
                'class' => 'areteia-nav',
                'style' => 'margin-top:20px; border-top:1px solid #eee; padding-top:15px;',
            ]);
            echo html_writer::link(
                new moodle_url($PAGE->url, array_merge($link_params, ['step' => 5])),
                '← Volver',
                ['class' => 'areteia-btn']
            );

            // Regenerate button
            $regen_url = new moodle_url($PAGE->url, array_merge($link_params, [
                'step'           => 6,
                'do_gen'         => 1,
                'rubric_content' => ' ',
            ]));
            echo html_writer::link($regen_url, 'Regenerar ✨', [
                'class' => 'areteia-btn',
                'style' => 'margin-left:auto; margin-right:10px;',
            ]);

            echo html_writer::link(
                new moodle_url($PAGE->url, array_merge($link_params, ['step' => 7])),
                'Finalizar y Revisar →',
                ['class' => 'areteia-btn areteia-btn-primary']
            );
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        }
    }
}
