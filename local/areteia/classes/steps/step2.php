<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\lock_manager;
use local_areteia\step_renderer;

/**
 * Step 2 — Bifurcación.
 * The teacher chooses Path A (already knows the instrument) or Path B (undecided).
 */
class step2 {

    public static function render(array $ctx): void {
        global $PAGE;

        $path = session_manager::get('path', '');
        $sel_a = ($path === 'A') ? 'sel' : '';
        $sel_b = ($path === 'B') ? 'sel' : '';

        $has_downstream = session_manager::has_any('d1', 's_sugs');
        $is_locked = $has_downstream && $path !== '';

        echo html_writer::tag('span', 'Paso 2 — Bifurcación', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', '¿Ya tenés en mente un instrumento de evaluación?', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p',
            'El sistema ofrece dos caminos. El diálogo del paso 3 se adapta según lo que respondas acá.',
            ['class' => 'areteia-sdesc']
        );

        // Lock banner
        if ($is_locked) {
            $url_params = ['id' => $ctx['id'], 'step' => 2];
            lock_manager::render_lock_banner(
                '🔒 Opción bloqueada',
                'La edición está protegida porque ya avanzaste.',
                new moodle_url($PAGE->url, ['step' => 0, 'unlock' => 2]),
                '🔓 Desbloquear (Se borrará el progreso posterior)'
            );
        }

        // Path cards
        echo html_writer::start_tag('div', ['class' => 's0-grid']);

        $path_a_url = new moodle_url($PAGE->url, ['step' => 2, 'path' => 'A']);
        [$open, $close] = lock_manager::option_tags($path_a_url, "s0-card $sel_a", $is_locked);
        echo $open;
        echo html_writer::tag('strong', 'Camino A — Ya sé qué quiero');
        echo html_writer::tag('span', 'Indicarás el instrumento que tenés en mente. La IA lo validará contra el objetivo que emerja en el paso 3.');
        echo $close;

        $path_b_url = new moodle_url($PAGE->url, ['step' => 2, 'path' => 'B']);
        [$open, $close] = lock_manager::option_tags($path_b_url, "s0-card $sel_b", $is_locked);
        echo $open;
        echo html_writer::tag('strong', 'Camino B — No lo tengo decidido');
        echo html_writer::tag('span', 'Avanzarás sin elegir. El paso 3 trabajará el objetivo desde cero y la IA sugerirá instrumentos después.');
        echo $close;

        echo html_writer::end_tag('div');

        echo html_writer::tag('div',
            'Nota: En el Camino A, la IA no descarta tu instrumento automáticamente, sino que lo problematiza pedagógicamente al final.',
            ['class' => 'areteia-note']
        );

        // Navigation
        $prev_url = new moodle_url($PAGE->url, ['step' => 1]);
        if ($path) {
            $next_url = new moodle_url($PAGE->url, ['step' => 3, 'path' => $path]);
            step_renderer::render_nav(2, $prev_url, $next_url, 'Continuar al paso 3 →');
        } else {
            step_renderer::render_nav(2, $prev_url, null, '', [], 'Selecciona un camino →');
        }
    }
}
