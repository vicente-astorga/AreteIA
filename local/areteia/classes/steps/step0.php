<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\lock_manager;
use local_areteia\step_renderer;

/**
 * Step 0 — Punto de entrada.
 * The teacher chooses between importing from Moodle or manual context entry.
 */
class step0 {

    public static function render(array $ctx): void {
        global $PAGE, $SESSION;

        $id = $ctx['id'];

        
        echo html_writer::tag('p', '¿Tenés tu curso cargado en Moodle?', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p',
            'AreteIA detectó recursos en este curso. ¿Querés importarlos automáticamente o cargarlos manualmente?',
            ['class' => 'areteia-sdesc']
        );

        // Determine which option is selected (URL takes priority, then session).
        $s0_active = isset($_GET['use_moodle'])
            ? optional_param('use_moodle', 1, PARAM_INT)
            : (session_manager::get('use_moodle') !== null ? (int)session_manager::get('use_moodle') : null);

        $sel_moodle = ($s0_active !== null && $s0_active === 1) ? 'sel' : '';
        $sel_manual = ($s0_active !== null && $s0_active === 0) ? 'sel' : '';

        $has_downstream = session_manager::has_any('d1', 's_sugs', 'instrument');
        $is_locked = $has_downstream && $s0_active !== null;

        // Lock banner
        if ($is_locked) {
            lock_manager::render_lock_banner(
                '🔒 Opción bloqueada',
                'La edición está protegida porque ya avanzaste.',
                new moodle_url($PAGE->url, ['step' => 0, 'unlock' => 2]),
                '🔓 Desbloquear (Se borrará el progreso posterior)'
            );
        }

        // Option cards
        echo html_writer::start_tag('div', ['class' => 's0-grid']);

        // --- Moodle card ---
        $moodle_url = new moodle_url($PAGE->url, ['use_moodle' => 1]);
        [$open, $close] = lock_manager::option_tags($moodle_url, "s0-card $sel_moodle", $is_locked);
        echo $open;
        echo html_writer::tag('strong', 'Si, usar recursos de Moodle');
        echo html_writer::tag('span', 'AreteIA importará recursos automáticamente. Recomendado para este curso.');
        echo $close;

        // --- Manual card ---
        $manual_url = new moodle_url($PAGE->url, ['use_moodle' => 0]);
        [$open, $close] = lock_manager::option_tags($manual_url, "s0-card $sel_manual", $is_locked);
        echo $open;
        echo html_writer::tag('strong', 'No, cargar manualmente');
        echo html_writer::tag('span', 'Empezarás con un formulario vacío para definir el contexto pedagógico desde cero.');
        echo $close;

        echo html_writer::end_tag('div');


        // Navigation
        $action = $ctx['action'] ?? 'lib';
        $next_url = null;
        if ($s0_active !== null) {
            $next_url = new moodle_url($PAGE->url, [
                'step'       => 1,
                'use_moodle' => $s0_active,
                'action'     => ($s0_active ? 'sync' : $action), // Step 0 can trigger sync action
            ]);
        }

        $clear_btn = '';
        if (session_manager::exists()) {
            $clear_url = new moodle_url($PAGE->url, ['step' => 0, 'clear' => 1, 'action' => $action]);
            $clear_btn = html_writer::link($clear_url, 'Borrar Progreso 🗑️', [
                'class' => 'areteia-btn',
                'style' => 'font-size:11px;',
            ]);
        }

        step_renderer::render_nav(
            0,
            null, // No back button for step 0
            $next_url,
            'Continuar →',
            [],
            ($s0_active === null ? 'Selecciona una opción' : null)
        );

        // Prepend the clear button manually if needed, or just let it be. 
        // Actually, let's keep it simple and just use the render_nav.

        // Handle clear action (at the end, like the original)
        if (optional_param('clear', 0, PARAM_INT)) {
            session_manager::clear();
            $redir_url = new moodle_url($PAGE->url, ['step' => 0]);
            if ($ctx['is_ajax']) {
                $redir_url->param('ajax', 1);
            }
            redirect($redir_url);
        }
    }
}
