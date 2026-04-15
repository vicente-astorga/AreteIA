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

        // Generate content if requested
        if ($do_gen) {
            $feedback = optional_param('feedback', '', PARAM_TEXT);
            $res_data = rag_client::generate([
                'course_id'         => $id,
                'step'              => 5,
                'objective'         => $d2,
                'objective_json'    => session_manager::get('d2_json', ''),
                'd1_content'        => session_manager::get('d1', ''),
                'd3_function'       => session_manager::get('d3', ''),
                'd4_modality'       => session_manager::get('d4', ''),
                'chosen_instrument' => $instrument,
                'feedback'          => $feedback
            ]);
            if ($res_data && $res_data->status == 'success') {
                $inst_content = json_encode($res_data->output);
                session_manager::set('inst_content', $inst_content);
            } else {
                $err = $res_data->message ?? 'Error al generar el diseño.';
                if (!empty($res_data->reason)) $err .= " Motivo: " . $res_data->reason;
                echo $OUTPUT->notification('Error de IA: ' . $err, 'error');
                $inst_content = '';
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
            // ... same generate button ...
            echo html_writer::start_tag('div', [
                'style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;',
            ]);
            echo html_writer::tag('p',
                'La IA está lista para redactar las consignas e ítems de tu evaluación.',
                ['style' => 'color:#777; margin-bottom:20px;']
            );
            $gen_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 5, 'do_gen' => 1]));
            echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:center; align-items:center; gap:10px;']);
            echo \local_areteia\step_renderer::render_preview_button(5);
            echo html_writer::link($gen_url, '✨ Generar Diseño con IA', [
                'class' => 'areteia-btn areteia-btn-primary',
                'style' => 'padding:12px 25px;',
            ]);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        } else {
            // Feedback area for iteration
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'background:#fffcf5; border:1px solid #faeeda; padding:15px; margin-bottom:20px;']);
            echo html_writer::tag('strong', '¿Quieres ajustar el diseño?+', ['style' => 'display:block; margin-bottom:10px; font-size:12px; color:#854f0b;']);
            echo html_writer::tag('textarea', '', [
                'name' => 'feedback',
                'class' => 'form-control w-100 mb-2',
                'placeholder' => 'Ej: Haz que las preguntas de RECORDAR sean de selección múltiple...',
                'rows' => 2
            ]);
            echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px; align-items:center;']);
            echo \local_areteia\step_renderer::render_preview_button(5);
            $adjust_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 5, 'do_gen' => 1]));
            echo html_writer::link($adjust_url, 'Ajustar Diseño ✨', [
                'class' => 'areteia-btn',
                'style' => 'font-size:12px;',
                'data-adjust' => '1'
            ]);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');

            // Parse and render structured content
            $data = json_decode($inst_content, true);
            if (!$data || !is_array($data)) {
                // Fallback for old markdown content if any
                echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'margin-bottom:20px;']);
                echo format_text($inst_content, FORMAT_MARKDOWN, ['context' => $context]);
                echo html_writer::end_tag('div');
            } else {
                echo html_writer::tag('h3', $data['title'] ?? 'Propuesta de Evaluación', ['style' => 'color:#185fa5; margin-bottom:15px;']);
                
                // Instructions
                echo html_writer::start_tag('div', ['class' => 'areteia-note', 'style' => 'margin-bottom:20px;']);
                echo html_writer::tag('strong', 'Instrucciones para el estudiante:', ['style' => 'display:block; margin-bottom:5px;']);
                echo format_text($data['instructions'] ?? '', FORMAT_MARKDOWN, ['context' => $context]);
                echo html_writer::end_tag('div');

                // Items list
                echo html_writer::start_tag('div', ['style' => 'display:flex; flex-direction:column; gap:15px; margin-bottom:20px;']);
                foreach (($data['items'] ?? []) as $index => $item) {
                    echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'padding:15px; border-left:4px solid #185fa5;']);
                    echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;']);
                    echo html_writer::tag('span', 'Pregunta ' . ($index + 1), ['style' => 'font-weight:bold; color:#185fa5;']);
                    echo html_writer::start_tag('div', ['style' => 'display:flex; gap:5px;']);
                    echo html_writer::tag('span', $item['bloom_level'], ['class' => 'areteia-tag', 'style' => 'margin-bottom:0; background:#e8f0fe; color:#1a73e8; font-size:9px;']);
                    echo html_writer::tag('span', $item['points'] . ' pts', ['class' => 'areteia-tag', 'style' => 'margin-bottom:0; background:#e6f4ea; color:#1e8e3e; font-size:9px;']);
                    echo html_writer::end_tag('div');
                    echo html_writer::end_tag('div');
                    echo html_writer::tag('div', format_text($item['text'], FORMAT_MARKDOWN, ['context' => $context]));
                    echo html_writer::end_tag('div');
                }
                echo html_writer::end_tag('div');

                // Justification
                if (!empty($data['justification'])) {
                    echo html_writer::start_tag('div', ['style' => 'font-size:12px; color:#666; font-style:italic; padding:10px; background:#f9f9f9; border-radius:8px;']);
                    echo html_writer::tag('strong', 'Justificación Pedagógica: ');
                    echo s($data['justification']);
                    echo html_writer::end_tag('div');
                }
            }

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
