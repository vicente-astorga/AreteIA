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
 * Step 4 — Sugerencias de la IA.
 * AI proposes pedagogically justified instruments based on the objective.
 */
class step4 {

    public static function render(array $ctx): void {
        global $PAGE, $OUTPUT;

        $id         = $ctx['id'];
        $summary    = $ctx['summary'];
        $sel_sug    = optional_param('sel_sug', '', PARAM_TEXT);
        $do_gen     = optional_param('do_gen', 0, PARAM_INT);
        $instrument = session_manager::get('instrument', '');
        $d2         = session_manager::get('d2', '');
        $d1         = session_manager::get('d1', '');
        $d3         = session_manager::get('d3', '');
        $d4         = session_manager::get('d4', '');
        $s_sugs_raw = session_manager::get('s_sugs', '');

        // Minimal params for links in this step
        $link_params = ['id' => $id];

        echo html_writer::tag('span', 'Paso 4 — Sugerencias de la IA', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Instrumentos recomendados', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p',
            'La IA propone una batería de instrumentos justificados pedagógicamente basados en tu objetivo.',
            ['class' => 'areteia-sdesc']
        );

        // Lock banner: protect downstream generated content
        $is_locked = lock_manager::is_locked(4);
        if ($is_locked) {
            lock_manager::render_lock_banner(
                '🔒 Opción bloqueada',
                'La edición está protegida porque ya avanzaste.',
                new moodle_url($PAGE->url, ['step' => 0, 'unlock' => 2]),
                '🔓 Desbloquear (Se borrará el progreso posterior)'
            );
        }

        // Parse cached suggestions from session
        $sugs = [];
        if ($s_sugs_raw) {
            $sugs = @json_decode($s_sugs_raw, true) ?: [];
        }

        // Generate suggestions via AI if requested and none cached
        if ($do_gen && empty($sugs)) {
            $sugs = self::generate_suggestions($id, $d2, $d1, $d3, $d4, $summary);
        }

        // Show generate button if no suggestions yet
        if (empty($sugs)) {
            echo html_writer::start_tag('div', [
                'style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;',
            ]);
            echo html_writer::tag('p',
                'Haz clic para que la IA analice tu objetivo y proponga instrumentos.',
                ['style' => 'color:#777; margin-bottom:20px;']
            );
            $gen_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 4, 'do_gen' => 1]));
            echo html_writer::link($gen_url, '✨ Generar Sugerencias con IA', [
                'class'   => 'areteia-btn areteia-btn-primary',
                'style'   => 'padding:12px 25px;',
                'data-ia' => '1',
            ]);
            echo html_writer::end_tag('div');
        }

        // Suggestion cards
        echo html_writer::start_tag('div', [
            'class' => 'sug-grid',
            'style' => 'display:flex; flex-direction:column; gap:10px; margin-bottom:20px;',
        ]);

        foreach ($sugs as $s) {
            self::render_suggestion_card($s, $sel_sug, $instrument, $link_params, $is_locked);
        }

        echo html_writer::end_tag('div');

        // Navigation
        $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 3]));
        $effective_sel = $sel_sug ?: $instrument;

        echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
        echo html_writer::link($prev_url, '← Anterior', ['class' => 'areteia-btn']);
        echo html_writer::tag('span', 'Paso 4 de 7', ['class' => 'areteia-ncnt']);

        if ($effective_sel) {
            $btn_attrs = ['class' => 'areteia-btn areteia-btn-primary'];
            $btn_label = ($effective_sel === $instrument) ? 'Continuar →' : 'Confirmar Instrumento →';

            // Warn before destroying downstream content
            if (!empty(session_manager::get('inst_content'))
                && $instrument !== ''
                && $effective_sel !== $instrument
            ) {
                $btn_attrs['data-confirm'] = '¡Atención! Confirmar este instrumento eliminará permanentemente la evaluación y rúbrica que tenías generada. ¿Estás seguro?';
            }

            $next_url = new moodle_url($PAGE->url, array_merge($link_params, [
                'step'       => 5,
                'instrument' => $effective_sel,
            ]));
            echo html_writer::link($next_url, $btn_label, $btn_attrs);
        } else {
            echo html_writer::tag('span', 'Selecciona un instrumento para continuar', [
                'class' => 'areteia-btn disabled',
                'style' => 'opacity:0.5; cursor:not-allowed;',
            ]);
        }

        echo html_writer::end_tag('div');
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Call the AI to generate instrument suggestions, cache in session.
     */
    private static function generate_suggestions(
        int $id,
        string $d2,
        string $d1,
        string $d3,
        string $d4,
        array $summary
    ): array {
        global $OUTPUT;

        $res_data = rag_client::generate([
            'course_id'  => $id,
            'step'       => 4,
            'objective'  => $d2,
            'summary'    => $summary['summary'],
            'dimensions' => "Contenido: $d1, Función: $d3, Modalidad: $d4",
        ]);

        if ($res_data && $res_data->status == 'success') {
            $raw = preg_replace('/^```json|```$/m', '', $res_data->output);
            $sugs = @json_decode(trim($raw), true);
            if (empty($sugs)) {
                $sugs = @json_decode(trim($raw), true);
            }
            if (!empty($sugs)) {
                session_manager::set('s_sugs', json_encode($sugs));
            }
            return $sugs ?: [];
        }

        $err = $res_data->message ?? 'Error en el servicio de IA.';
        echo $OUTPUT->notification('Error de IA: ' . $err, 'error');
        return [];
    }

    /**
     * Render a single suggestion card.
     */
    private static function render_suggestion_card(
        array $s,
        string $sel_sug,
        string $instrument,
        array $link_params,
        bool $is_locked = false
    ): void {
        global $PAGE;

        $name       = $s['name'] ?? 'Sin nombre';
        $is_current = ($instrument === $name);

        // Determine selection state
        $is_sel = '';
        if ($sel_sug === $name) {
            $is_sel = 'is-sel';
        } elseif (empty($sel_sug) && $is_current) {
            $is_sel = 'is-sel';
        }

        $sug_url    = new moodle_url($PAGE->url, array_merge($link_params, ['sel_sug' => $name]));
        $card_attrs = [
            'href'  => $sug_url,
            'class' => "areteia-card sug-card $is_sel",
            'style' => 'padding:15px; border-radius:10px; text-decoration:none; color:inherit; position:relative;',
        ];

        // Warn if selecting a different instrument with existing generated content
        if (!empty(session_manager::get('inst_content'))
            && $instrument !== ''
            && $name !== $instrument
        ) {
            $card_attrs['data-confirm'] = 'Si confirmas un nuevo instrumento, se borrará tu diseño y rúbrica actuales. ¿Seleccionar para explorar?';
        }

        if ($is_locked) {
            unset($card_attrs['href']);
            $card_attrs['style'] .= ' opacity:0.6; cursor:not-allowed;';
            echo html_writer::start_tag('div', $card_attrs);
        } else {
            echo html_writer::start_tag('a', $card_attrs);
        }

        if ($is_current) {
            echo html_writer::tag('span', 'Instrumento en uso', [
                'style' => 'position:absolute; top:10px; right:10px; font-size:10px; background:#e6f4ea; color:#1e8e3e; padding:2px 8px; border-radius:10px; border:1px solid #1e8e3e;',
            ]);
        }

        echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:space-between; margin-bottom:5px;']);
        echo html_writer::tag('strong', $name, ['style' => 'color:#185fa5;']);
        echo html_writer::end_tag('div');

        echo html_writer::tag('div',
            '<strong>Fundamentación:</strong> ' . ($s['why'] ?? 'N/A'),
            ['style' => 'font-size:12px; color:#555; margin-bottom:3px;']
        );
        echo html_writer::tag('div',
            '<em>Limitación:</em> ' . ($s['lim'] ?? 'N/A'),
            ['style' => 'font-size:11px; color:#999;']
        );

        echo html_writer::end_tag($is_locked ? 'div' : 'a');
    }
}
