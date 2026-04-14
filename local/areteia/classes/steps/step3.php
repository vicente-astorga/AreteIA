<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\lock_manager;
use local_areteia\rag_client;
use local_areteia\step_renderer;

/**
 * Step 3 — Diálogo con la IA.
 * Four pedagogical dimensions (content type, objective, function, modality)
 * plus optional RAG-powered AI feedback.
 */
class step3 {

    public static function render(array $ctx): void {
        global $PAGE;

        $id         = $ctx['id'];
        $path       = optional_param('path', 'B', PARAM_ALPHA);
        $show_ai    = optional_param('ai', 0, PARAM_INT);
        $use_moodle = session_manager::get('use_moodle', 1);

        $d1 = session_manager::get('d1', '');
        $d2 = session_manager::get('d2', '');
        $d3 = session_manager::get('d3', '');
        $d4 = session_manager::get('d4', '');

        // URL params for links within this step
        $step_params = [
            'id'         => $id,
            'step'       => 3,
            'path'       => $path,
            'use_moodle' => $use_moodle,
            'ai'         => $show_ai,
        ];

        $is_locked = lock_manager::is_locked(3);

        echo html_writer::tag('span', 'Paso 3 — Diálogo con la IA', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Clarificación del objetivo de evaluación', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p', 'Dimensiones clave para definir qué y cómo evaluar.', ['class' => 'areteia-sdesc']);

        // Lock banner
        if ($is_locked) {
            $unlock_url = new moodle_url($PAGE->url, array_merge($step_params, ['unlock' => 1]));
            lock_manager::render_lock_banner(
                '🔒 Opción bloqueada',
                'La edición está protegida porque ya avanzaste.',
                new moodle_url($PAGE->url, ['step' => 0, 'unlock' => 2]),
                '🔓 Desbloquear (Se borrará el progreso posterior)'
            );
        }

        // D1: Tipo de contenido
        self::render_dimension(
            'Dimensión 1 — Tipo de contenido',
            '¿Qué tipo de contenido es el foco principal?',
            ['Factual', 'Conceptual', 'Procedimental', 'Actitudinal'],
            'd1', $d1, $step_params, $is_locked
        );

        // D2: Objetivo (textarea)
        echo html_writer::start_tag('div', ['class' => 'areteia-dim']);
        echo html_writer::tag('div', 'Dimensión 2 — Objetivo de evaluación', ['class' => 'dlbl']);
        echo html_writer::tag('div',
            'Formulá el objetivo: ¿qué querés saber si pueden hacer?',
            ['class' => 'dq', 'style' => 'font-weight:bold; margin-bottom:10px;']
        );
        echo html_writer::tag('div',
            '«Quiero saber si pueden [verbo] [contenido] [condiciones]»',
            ['class' => 'otpl', 'style' => 'background:#e6f1fb; padding:15px; border-radius:8px; border:1px solid #85b7eb; color:#185fa5; margin-bottom:10px;']
        );
        $ta_attrs = [
            'name'        => 'd2',
            'class'       => 'form-control w-100 mb-2',
            'placeholder' => 'Escribe aquí el objetivo...',
            'rows'        => 3,
        ];
        if ($is_locked) {
            $ta_attrs['readonly'] = 'readonly';
        }
        echo html_writer::tag('textarea', $d2, $ta_attrs);
        echo html_writer::end_tag('div');

        // D3: Función
        self::render_dimension(
            'Dimensión 3 — Función de la evaluación',
            null,
            ['Diagnóstica', 'Formativa', 'Sumativa'],
            'd3', $d3, $step_params, $is_locked
        );

        // D4: Modalidad
        self::render_dimension(
            'Dimensión 4 — Modalidad',
            null,
            ['Individual', 'Grupal/Colaborativa', 'Pares (Peer)'],
            'd4', $d4, $step_params, $is_locked,
            'border-bottom:none; padding-bottom:0;'
        );

        // AI Feedback / RAG
        if ($show_ai && !empty($d2)) {
            self::render_rag_feedback($id, $d2);
        } else if ($show_ai) {
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card t-ia',
                'style' => 'border-left: 5px solid #185fa5; background: #f0f7ff; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '✨ Sugerencia de la IA', [
                'style' => 'display:block; margin-bottom:10px; color:#185fa5;',
            ]);
            echo html_writer::tag('div',
                'Escribe tu objetivo para que la IA lo analice usando los materiales del curso.',
                ['style' => 'font-size:12px; color:#666;']
            );
            echo html_writer::end_tag('div');
        }

        // Navigation
        $prev_url = new moodle_url($PAGE->url, array_merge($step_params, ['step' => 2]));
        $next_url = new moodle_url($PAGE->url, array_merge($step_params, ['step' => 4]));
        $can_continue = ($d1 && $d2 && $d3 && $d4);

        echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
        echo html_writer::link($prev_url, '← Anterior', ['class' => 'areteia-btn']);
        echo html_writer::tag('span', 'Paso 3 de 7', ['class' => 'areteia-ncnt']);

        $btn_class = 'areteia-btn areteia-btn-primary ' . ($can_continue ? '' : 'disabled');
        $btn_text  = $can_continue ? 'Ver Sugerencias →' : 'Completa todas las dimensiones';
        $btn_style = $can_continue ? '' : 'opacity:0.5; cursor:not-allowed;';
        echo html_writer::link($next_url, $btn_text, [
            'id'    => 'next-step-btn',
            'class' => $btn_class,
            'style' => $btn_style,
        ]);

        echo html_writer::end_tag('div');
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Render a dimension with pill options.
     */
    private static function render_dimension(
        string $label,
        ?string $question,
        array $options,
        string $param_name,
        string $current_value,
        array $step_params,
        bool $is_locked,
        string $extra_style = ''
    ): void {
        global $PAGE;

        $attrs = ['class' => 'areteia-dim'];
        if ($extra_style) {
            $attrs['style'] = $extra_style;
        }
        echo html_writer::start_tag('div', $attrs);
        echo html_writer::tag('div', $label, ['class' => 'dlbl']);

        if ($question) {
            echo html_writer::tag('div', $question, [
                'class' => 'dq',
                'style' => 'font-weight:bold; margin-bottom:10px;',
            ]);
        }

        echo html_writer::start_tag('div', ['class' => 'opts', 'style' => 'display:flex; gap:10px; flex-wrap:wrap;']);
        foreach ($options as $o) {
            $active = ($current_value == $o) ? 'main' : '';
            $url    = new moodle_url($PAGE->url, array_merge($step_params, [$param_name => $o]));
            lock_manager::render_pill($o, $url, "opt $active", $is_locked);
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }

    /**
     * Render RAG search results.
     */
    private static function render_rag_feedback(int $id, string $d2): void {
        $search_data = rag_client::search($id, $d2);

        echo html_writer::start_tag('div', [
            'class' => 'areteia-card t-ia',
            'style' => 'border-left: 5px solid #185fa5; background: #f0f7ff; margin-bottom:20px;',
        ]);
        echo html_writer::tag('strong', '✨ Análisis de Contexto (RAG)', [
            'style' => 'display:block; margin-bottom:10px; color:#185fa5;',
        ]);

        if ($search_data && $search_data->status == 'success' && !empty($search_data->results)) {
            echo html_writer::tag('p',
                'He encontrado estos fragmentos relevantes en tus materiales para justificar tu objetivo:',
                ['style' => 'font-size:12px; margin-bottom:8px; font-weight:bold;']
            );
            echo '<ul style="font-size:11px; color:#555; list-style:none; padding:0;">';
            foreach (array_slice($search_data->results, 0, 2) as $res) {
                echo '<li style="background:rgba(255,255,255,0.5); padding:8px; border-radius:5px; margin-bottom:5px; border-left:3px solid #85b7eb;">';
                echo '<em>"' . s(mb_strimwidth($res->text, 0, 150, "...")) . '"</em><br>';
                echo '<small style="color:#185fa5;">— Fuente: ' . s($res->filename) . '</small>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo html_writer::tag('p',
                'No se encontraron fragmentos específicos en los PDFs, pero puedo ayudarte con sugerencias generales.',
                ['style' => 'font-size:12px; color:#666;']
            );
        }

        echo html_writer::end_tag('div');
    }
}
