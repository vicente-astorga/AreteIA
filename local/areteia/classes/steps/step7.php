<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\step_renderer;

/**
 * Step 7 — Resultado final.
 * Preview of the complete instrument and export to Moodle.
 */
class step7 {

    public static function render(array $ctx): void {
        global $PAGE;

        $context        = $ctx['context'];
        $instrument     = session_manager::get('instrument', '');
        $inst_content   = session_manager::get('inst_content', '');
        $rubric_content = session_manager::get('rubric_content', '');
        $exported       = session_manager::get('exported', 0);
        $cmid           = session_manager::get('cmid', 0);

        echo html_writer::tag('span', 'Paso 7 — Resultado final', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Instrumento de evaluación finalizado', ['class' => 'areteia-stitle']);

        // Export success banner
        if ($exported == 1) {
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #28a745; background: #f4fff4; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '🚀 ¡Actividad publicada en Moodle!', [
                'style' => 'color:#28a745; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p', 'La tarea ha sido creada exitosamente.', [
                'style' => 'font-size:12px; margin-bottom:10px;',
            ]);
            echo html_writer::link(
                new moodle_url('/mod/assign/view.php', ['id' => $cmid]),
                'Ir a la actividad en Moodle ↗',
                ['class' => 'areteia-btn areteia-btn-primary external', 'target' => '_blank']
            );
            echo html_writer::end_tag('div');
        }

        // Preview card
        echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'margin-bottom:20px;']);
        echo html_writer::tag('p', "<strong>Vista previa final: $instrument</strong>", [
            'style' => 'color:#185fa5; font-size:1.1em; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;',
        ]);

        // Instrument preview
        echo html_writer::tag('div', '<strong>Consignas:</strong>', [
            'style' => 'font-size:13px; font-weight:bold; margin-bottom:5px;',
        ]);
        $preview_inst = mb_strimwidth($inst_content, 0, 500, '...');
        echo html_writer::tag('div',
            format_text($preview_inst, FORMAT_MARKDOWN, ['context' => $context]),
            [
                'class' => 'areteia-markdown-content',
                'style' => 'font-size:12px; background:#fcfcfc; padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid #eee;',
            ]
        );

        // Rubric preview
        echo html_writer::tag('div', '<strong>Rúbrica:</strong>', [
            'style' => 'font-size:13px; font-weight:bold; margin-bottom:5px;',
        ]);
        $preview_rub = mb_strimwidth($rubric_content, 0, 500, '...');
        echo html_writer::tag('div',
            format_text($preview_rub, FORMAT_MARKDOWN, ['context' => $context]),
            [
                'class' => 'areteia-markdown-content',
                'style' => 'font-size:12px; background:#fcfcfc; padding:10px; border-radius:8px; border:1px solid #eee;',
            ]
        );
        echo html_writer::end_tag('div');

        // Navigation
        $prev_url   = new moodle_url($PAGE->url, ['step' => 6]);
        $export_url = new moodle_url($PAGE->url, ['action' => 'export']);

        if ($exported == 1) {
            step_renderer::render_nav(7, $prev_url, null, '', [], '✔ Publicado con éxito');
        } else {
            step_renderer::render_nav(7, $prev_url, $export_url, '🚀 Publicar en Moodle');
        }
    }
}
