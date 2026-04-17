<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\data_provider;
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

        // ----------------------------------------------------------------
        // Quiz injection block
        // ----------------------------------------------------------------
        $quiz_injected = optional_param('quiz_injected', 0, PARAM_INT);
        $quiz_error    = optional_param('quiz_error', 0, PARAM_INT);
        $quiz_cmid     = optional_param('quiz_cmid', 0, PARAM_INT);

        // Success banner
        if ($quiz_injected == 1) {
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left:5px solid #28a745; background:#f4fff4; margin-top:20px;',
            ]);
            echo html_writer::tag('strong', '🎯 ¡Cuestionario publicado en Moodle!', [
                'style' => 'color:#28a745; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p', '3 preguntas creadas correctamente.', [
                'style' => 'font-size:12px; margin-bottom:10px;',
            ]);
            if ($quiz_cmid) {
                echo html_writer::link(
                    new moodle_url('/mod/quiz/view.php', ['id' => $quiz_cmid]),
                    'Ir al cuestionario ↗',
                    ['class' => 'areteia-btn areteia-btn-primary external', 'target' => '_blank']
                );
            }
            echo html_writer::end_tag('div');
        }

        // Error banner
        if ($quiz_error == 1) {
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left:5px solid #dc3545; background:#fff4f4; margin-top:20px;',
            ]);
            echo html_writer::tag('strong', '❌ Error al crear el cuestionario', [
                'style' => 'color:#dc3545; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'Revisá los logs de Moodle para más detalles.',
                ['style' => 'font-size:12px; margin:0;']
            );
            echo html_writer::end_tag('div');
        }

        // Quiz injection form (always shown unless just published)
        if ($quiz_injected != 1) {
            $questions = self::get_fake_questions();
            $sections  = data_provider::get_course_sections($ctx['id']);

            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'margin-top:20px; border-left:5px solid #6c63ff; background:#f8f7ff;',
            ]);
            echo html_writer::tag('strong', '❓ Cuestionario de evaluación', [
                'style' => 'color:#6c63ff; display:block; margin-bottom:4px;',
            ]);
            echo html_writer::tag('p', count($questions) . ' preguntas listas para publicar (' .
                implode(', ', array_map(function($q) { return $q['type']; }, $questions)) . ')', [
                'style' => 'font-size:12px; color:#666; margin-bottom:15px;',
            ]);

            // Section selector form
            $inject_url = new moodle_url($PAGE->url, ['action' => 'inject_quiz', 'id' => $ctx['id']]);
            echo html_writer::start_tag('form', [
                'method' => 'POST',
                'action' => $inject_url->out(false),
                'style'  => 'display:flex; gap:10px; align-items:center; flex-wrap:wrap;',
            ]);
            echo html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'name'  => 'sesskey',
                'value' => sesskey(),
            ]);

            // Section select
            echo html_writer::start_tag('select', [
                'name'  => 'section_num',
                'class' => 'form-control',
                'style' => 'max-width:280px; font-size:13px;',
            ]);
            foreach ($sections as $sec) {
                echo html_writer::tag('option', s($sec['name']), ['value' => $sec['num']]);
            }
            echo html_writer::end_tag('select');

            echo html_writer::tag('button', '📋 Publicar Cuestionario en Moodle', [
                'type'  => 'submit',
                'class' => 'areteia-btn areteia-btn-primary',
                'style' => 'font-size:13px;',
            ]);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('div');
        }
    }

    /**
     * Returns the fake questions for quiz injection.
     * In the future these will come from AI-generated content.
     *
     * @return array
     */
    public static function get_fake_questions(): array {
        return [
            [
                'type'    => 'multichoice',
                'text'    => '¿Cuál de los siguientes es un ejemplo de evaluación formativa?',
                'options' => [
                    'Examen final del semestre',
                    'Retroalimentación continua durante el proceso de aprendizaje',
                    'Prueba de admisión universitaria',
                    'Calificación numérica trimestral',
                ],
                'correct' => 1, // índice de la opción correcta (0-based)
            ],
            [
                'type'    => 'truefalse',
                'text'    => 'La taxonomía de Bloom clasifica los objetivos de aprendizaje en niveles cognitivos jerárquicos.',
                'correct' => true,
            ],
            [
                'type' => 'essay',
                'text' => 'Describe cómo diseñarías una evaluación auténtica para tu asignatura. Fundamenta tu respuesta considerando el contexto pedagógico del curso.',
            ],
        ];
    }
}
