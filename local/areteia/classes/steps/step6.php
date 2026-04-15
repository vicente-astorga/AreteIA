<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\rag_client;
use local_areteia\step_renderer;

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

        // Generate rubric if requested
        if ($do_gen) {
            $feedback = optional_param('feedback', '', PARAM_TEXT);
            $res_data = rag_client::generate([
                'course_id'          => $id,
                'step'               => 6,
                'objective'          => session_manager::get('d2', ''),
                'objective_json'     => session_manager::get('d2_json', ''),
                'd1_content'         => session_manager::get('d1', ''),
                'd3_function'        => session_manager::get('d3', ''),
                'd4_modality'        => session_manager::get('d4', ''),
                'instrument_content' => $inst_content,
                'feedback'           => $feedback
            ]);
            if ($res_data && $res_data->status == 'success') {
                $rubric_content = json_encode($res_data->output);
                session_manager::set('rubric_content', $rubric_content);
            } else {
                $err = $res_data->message ?? 'Error al generar la rúbrica.';
                if (!empty($res_data->reason)) $err .= " Motivo: " . $res_data->reason;
                echo $OUTPUT->notification('Error de IA: ' . $err, 'error');
                $rubric_content = '';
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
            echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:center; align-items:center; gap:10px;']);
            echo step_renderer::render_preview_button(6);
            echo html_writer::link($gen_url, '✨ Generar Rúbrica con IA', [
                'class' => 'areteia-btn areteia-btn-primary',
            ]);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        } else {
            // Feedback area for iteration
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'background:#fffcf5; border:1px solid #faeeda; padding:15px; margin-bottom:20px;']);
            echo html_writer::tag('strong', '¿Ajustar criterios o niveles?', ['style' => 'display:block; margin-bottom:10px; font-size:12px; color:#854f0b;']);
            echo html_writer::tag('textarea', '', [
                'name' => 'feedback',
                'class' => 'form-control w-100 mb-2',
                'placeholder' => 'Ej: Añade un nivel "En Proceso" o sé más estricto con la ortografía...',
                'rows' => 2
            ]);
            echo html_writer::start_tag('div', ['style' => 'display:flex; gap:10px; align-items:center;']);
            echo step_renderer::render_preview_button(6);
            $adjust_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 6, 'do_gen' => 1]));
            echo html_writer::link($adjust_url, 'Ajustar Rúbrica ✨', [
                'class' => 'areteia-btn',
                'style' => 'font-size:12px;',
                'data-adjust' => '1'
            ]);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');

            // Parse and render table
            $data = json_decode($rubric_content, true);
            if (!$data || !is_array($data)) {
                echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'margin-bottom:20px;']);
                echo format_text($rubric_content, FORMAT_MARKDOWN, ['context' => $context]);
                echo html_writer::end_tag('div');
            } else {
                echo html_writer::tag('h3', $data['title'] ?? 'Rúbrica de Evaluación', ['style' => 'color:#185fa5; margin-bottom:15px;']);
                
                echo html_writer::start_tag('div', ['class' => 'table-responsive', 'style' => 'margin-bottom:20px;']);
                echo html_writer::start_tag('table', ['class' => 'table table-bordered areteia-table', 'style' => 'font-size:12px;']);
                
                // Header (Levels)
                echo html_writer::start_tag('thead', ['style' => 'background:#f0f4f8;']);
                echo html_writer::start_tag('tr');
                echo html_writer::tag('th', 'Criterio', ['style' => 'width:200px;']);
                
                // Detect levels from the first criterion to build columns
                $first_crit = $data['criteria'][0] ?? null;
                $level_count = $first_crit ? count($first_crit['levels']) : 0;
                
                if ($first_crit) {
                    foreach ($first_crit['levels'] as $lvl) {
                        echo html_writer::tag('th', $lvl['label'] . ' (' . $lvl['score'] . ' pts)', ['style' => 'text-align:center;']);
                    }
                }
                echo html_writer::end_tag('tr');
                echo html_writer::end_tag('thead');

                // Body (Criteria)
                echo html_writer::start_tag('tbody');
                foreach (($data['criteria'] ?? []) as $crit) {
                    echo html_writer::start_tag('tr');
                    echo html_writer::start_tag('td', ['style' => 'background:#fafafa;']);
                    echo html_writer::tag('strong', $crit['name'], ['style' => 'display:block;']);
                    echo html_writer::tag('small', $crit['description'], ['style' => 'color:#777;']);
                    echo html_writer::end_tag('td');

                    foreach (($crit['levels'] ?? []) as $lvl) {
                        echo html_writer::tag('td', $lvl['description']);
                    }
                    echo html_writer::end_tag('tr');
                }
                echo html_writer::end_tag('tbody');

                echo html_writer::end_tag('table');
                echo html_writer::end_tag('div');

                // Justification
                if (!empty($data['justification'])) {
                    echo html_writer::start_tag('div', ['style' => 'font-size:12px; color:#666; font-style:italic; padding:10px; background:#f9f9f9; border-radius:8px;']);
                    echo html_writer::tag('strong', 'Justificación Pedagógica (Directrices): ');
                    echo s($data['justification']);
                    echo html_writer::end_tag('div');
                }
            }

            // Bottom navigation handled by step_renderer (calculates prev/next URLs based on action sequence)
            step_renderer::render_nav(6);
            echo html_writer::end_tag('div'); // card
        }
    }
}
