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
 * Step 1 — Contexto objetivo.
 * Shows imported course data and manages RAG embedding build.
 */
class step1 {

    public static function render(array $ctx): void {
        global $PAGE, $OUTPUT;

        $id        = $ctx['id'] ?: optional_param('id', 0, PARAM_INT);
        $summary   = $ctx['summary'];
        $files     = $ctx['files'];
        $use_moodle = session_manager::get('use_moodle', 1);

        
        echo html_writer::tag('p', 'Contexto pedagógico de la asignatura', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p',
            'Verificá los recursos importados de Moodle para asegurar que la biblioteca se haya generado correctamente',
            ['class' => 'areteia-sdesc']
        );

        // Lock banner: protect downstream progress
        $is_locked = lock_manager::is_locked(1);
        if ($is_locked) {
            lock_manager::render_lock_banner(
                '🔒 Opción bloqueada',
                'Esta sección está protegida porque ya avanzaste.',
                new moodle_url($PAGE->url, ['step' => 0, 'unlock' => 2]),
                '🔓 Desbloquear (Se borrará el progreso)'
            );
        }

        // Check embedding status from Python service
        $status_result    = rag_client::status($id);
        $status_data      = $status_result['data'];
        $status_raw       = $status_result['raw'];
        $already_ingested = ($status_data && !empty($status_data->embedding_exists));
        $service_down     = ($status_raw === false || empty($status_raw));

        if ($use_moodle) {
            self::render_moodle_fields($id, $summary, $files, $already_ingested, $service_down, $status_data);
        } else {
            echo $OUTPUT->notification('Te pedimos disculpa. La carga manual no está disponible en esta versión .', 'warning');
        }

        // Bottom section depends on ingestion state
        $ingested = optional_param('ingested', 0, PARAM_INT);
        self::render_ingestion_status($id, $ingested, $already_ingested, $service_down);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function render_moodle_fields(
        int $id,
        array $summary,
        array $files,
        bool $already_ingested,
        bool $service_down,
        ?object $status_data
    ): void {
        global $PAGE;

        echo html_writer::start_tag('div', ['class' => 'areteia-fields']);

        // --- Field: Asignatura ---
        echo html_writer::start_tag('div', ['class' => 'areteia-fr']);
        echo html_writer::start_tag('div', ['class' => 'areteia-flbl']);
        echo 'Asignatura ';
        echo html_writer::end_tag('div');
        echo html_writer::tag('div', $summary['fullname'], ['class' => 'areteia-fb fc']);
        echo html_writer::end_tag('div');

        // --- Field: Programa / Resumen ---
        $sum_ok = optional_param('sum_ok', 0, PARAM_INT);
        echo html_writer::start_tag('div', ['class' => 'areteia-fr']);
        echo html_writer::start_tag('div', ['class' => 'areteia-flbl']);
        echo html_writer::tag('span', $res_tag[1], ['class' => $res_tag[0]]);
        echo html_writer::end_tag('div');
        echo html_writer::tag('div', $summary['summary'] ?: 'Sin resumen en Moodle', ['class' => 'areteia-fb fw']);
        echo html_writer::start_tag('div', ['class' => 'areteia-fa']);
        $conf_url = new moodle_url($PAGE->url, ['step' => 1, 'sum_ok' => 1]);
        echo html_writer::link($conf_url, 'Confirmar', ['class' => 'fb-btn ok', 'style' => 'text-decoration:none']);
        echo html_writer::tag('button', 'Editar', [
            'class'   => 'fb-btn',
            'onclick' => "alert('Edición de resumen próximamente en el prototipo.')",
        ]);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        // --- Field: Materiales ---
        echo html_writer::start_tag('div', ['class' => 'areteia-fr']);
        echo html_writer::start_tag('div', ['class' => 'areteia-flbl']);
        echo 'Recursos detectados' ;

        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', ['class' => 'areteia-fb fw']);
        if ($already_ingested) {
            echo html_writer::tag('div', 'Embeddings detectados y persistentes.', [
                'class' => 'areteia-fb fw',
                'style' => 'color:#28a745; font-weight:bold;',
            ]);
            if (!empty($status_data->path)) {
                echo html_writer::tag('small', 'Ruta: ' . s($status_data->path), [
                    'style' => 'display:block; color:#999; font-size:10px; margin-top:4px;',
                ]);
            }
        } else if ($service_down) {
            echo html_writer::tag('div',
                'El servicio de IA aún no ha arrancado (posible reinicio de PC). Reintentando...',
                ['class' => 'areteia-fb fw', 'style' => 'color:#ff9800;']
            );
        } else {
            $tree = \local_areteia\data_provider::get_course_materials_tree($id);
            echo html_writer::start_tag('div', [
                'class' => 'areteia-fb fw', 
                'style' => 'margin-bottom:15px; display: flex; justify-content: space-between; align-items: center;'
            ]);
            echo html_writer::tag('span', "Seleccioná los recursos para la IA:", ['style' => 'font-weight:bold;']);
            echo html_writer::tag('span', 'Calculando...', [
                'id' => 'selection-count-badge', 
                'class' => 'sb-tag sb-warn',
                'style' => 'margin-left:10px; padding: 4px 10px; border-radius: 12px; font-size: 11px;'
            ]);
            echo html_writer::end_tag('div');
            self::render_materials_tree($tree);
        }

        echo html_writer::end_tag('div'); // areteia-fb
        echo html_writer::end_tag('div'); // areteia-fr
        echo html_writer::end_tag('div'); // areteia-fields
    }

    /**
     * Recursively render the hierarchical materials tree.
     */
    private static function render_materials_tree(array $tree): void {
        echo html_writer::start_tag('div', ['class' => 'areteia-tree', 'id' => 'materials-tree']);
        self::render_tree_node($tree, 0);
        echo html_writer::end_tag('div');
    }

    /**
     * Helper to render a single tree node and its children.
     */
    private static function render_tree_node(array $node): void {
        $type = $node['type'];
        $id   = $node['id'];
        $name = $node['name'];
        $uid  = "tree-{$type}-{$id}";
        
        $has_children = !empty($node['sections']) || !empty($node['activities']) || !empty($node['files']);

        // Icons based on type
        $icons = [
            'course'   => '🎓',
            'section'  => '📁',
            'activity' => '🧩',
            'file'     => '📄'
        ];
        $icon = $icons[$type] ?? '•';

        echo html_writer::start_tag('div', ['class' => "tree-node tree-{$type}"]);

        echo html_writer::start_tag('div', ['class' => 'tree-row']);
        
        // Toggle chevron (only if there are children)
        if ($has_children) {
            echo html_writer::tag('span', '▼', ['class' => 'tree-toggle', 'title' => 'Colapsar/Expandir']);
        } else {
            echo html_writer::tag('span', '', ['class' => 'tree-toggle-spacer']);
        }

        $checked = 'checked'; // Default to checked
        $attr = [
            'type'           => 'checkbox',
            'class'          => 'tree-cb',
            'id'             => $uid,
            'data-type'      => $type,
            'data-id'        => $id,
            'value'          => ($type === 'file' ? ($node['relpath'] ?? '') : $id)
        ];
        if ($checked) $attr['checked'] = 'checked';

        echo html_writer::empty_tag('input', $attr);
        echo html_writer::start_tag('label', ['for' => $uid, 'class' => 'tree-label-text']);
        echo html_writer::tag('span', $icon, ['class' => 'tree-icon']);
        echo html_writer::tag('span', s($name), ['class' => 'tree-name']);
        echo html_writer::end_tag('label');
        echo html_writer::end_tag('div'); // tree-row

        // Children wrapper
        if ($has_children) {
            echo html_writer::start_tag('div', ['class' => 'tree-children']);
            if (!empty($node['sections'])) {
                foreach ($node['sections'] as $s) self::render_tree_node($s);
            }
            if (!empty($node['activities'])) {
                foreach ($node['activities'] as $a) self::render_tree_node($a);
            }
            if (!empty($node['files'])) {
                foreach ($node['files'] as $f) self::render_tree_node($f);
            }
            echo html_writer::end_tag('div');
        }

        echo html_writer::end_tag('div'); // tree-node
    }

    private static function render_ingestion_status(
        int $id,
        int $ingested,
        bool $already_ingested,
        bool $service_down
    ): void {
        global $PAGE;

        $prev_url = new moodle_url($PAGE->url, ['step' => 0]);

        if ($already_ingested || $ingested == 1) {
            // Success
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #28a745; background: #f4fff4; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '✨ Biblioteca creada con éxito!', [
                'style' => 'color:#28a745; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'La IA ya tiene acceso a los recursos de tu curso para darte mejores respuestas.',
                ['style' => 'font-size:12px; margin:0;']
            );
            echo html_writer::end_tag('div');

            $delete_url = new moodle_url($PAGE->url, ['step' => 1, 'action' => 'delete_rag']);
            echo html_writer::start_tag('div', ['style' => 'text-align:right; margin-bottom: 20px;']);
            echo html_writer::link($delete_url, '🗑️ Eliminar Biblioteca y reiniciar', [
                'class' => 'areteia-btn', 
                'style' => 'background: #fff; color: #dc3545; border: 1px solid #dc3545;',
                'data-confirm' => '¿Estás seguro de que deseas eliminar la biblioteca? Tendrás que volver a procesar los documentos si cambias de opinión.',
            ]);
            echo html_writer::end_tag('div');

            // --- RAG Search Test Box ---
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #6c63ff; background: #f8f7ff; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '🔍 Probá tu biblioteca', [
                'style' => 'color:#6c63ff; display:block; margin-bottom:8px;',
            ]);
            echo html_writer::tag('p',
                'Escribí una consulta de prueba y mirá qué fragmentos devuelve la IA desde tus documentos.',
                ['style' => 'font-size:12px; margin:0 0 12px 0; color:#666;']
            );
            echo html_writer::start_tag('div', [
                'style' => 'display:flex; gap:8px; align-items:stretch;',
            ]);
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'id' => 'rag-search-input',
                'placeholder' => 'Ej: ¿Qué dice el material sobre gamificación?',
                'class' => 'areteia-input',
                'style' => 'flex:1; padding:10px 14px; border:1px solid #ddd; border-radius:8px; font-size:13px;',
            ]);
            echo html_writer::tag('button', 'Buscar', [
                'id' => 'rag-search-btn',
                'class' => 'areteia-btn areteia-btn-primary',
                'style' => 'padding:10px 20px; border:none; border-radius:8px; cursor:pointer; font-size:13px;',
                'data-courseid' => $id,
            ]);
            echo html_writer::end_tag('div');
            echo html_writer::tag('div', '', [
                'id' => 'rag-search-results',
                'style' => 'margin-top:16px;',
            ]);
            echo html_writer::end_tag('div');

            step_renderer::render_nav(1, $prev_url, new moodle_url($PAGE->url, ['step' => 2]), 'Continuar al paso 2 →');

        } else if ($ingested == 2) {
            // Empty content
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #ffca28; background: #fffcf0; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '⚠️ Sin texto extraíble', [
                'style' => 'color:#ffca28; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'Moodle entregó los archivos, pero la IA no encontró texto (quizás sean PDFs escaneados o carpetas vacías). Esto limitará las sugerencias de RAG.',
                ['style' => 'font-size:12px; line-height:1.4; margin:0;']
            );
            echo html_writer::end_tag('div');

            step_renderer::render_nav(1, $prev_url, new moodle_url($PAGE->url, ['step' => 2]), 'Continuar al paso 2 →');

        } else if ($ingested == 3) {
            // Processing in background — but check if it's actually already done
            $real_status = \local_areteia\rag_client::status($id);
            if (!empty($real_status['data']->embedding_exists)) {
                self::render_ingestion_status($id, 1, true, false);
                return;
            }

            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #17a2b8; background: #f4f8ff; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '⏳ Construyendo biblioteca de IA...', [
                'style' => 'color:#17a2b8; display:block; margin-bottom:10px;',
            ]);
            
            // Progress Bar Container
            echo html_writer::start_tag('div', ['class' => 'areteia-progress-container']);
            echo html_writer::start_tag('div', ['class' => 'areteia-progress-bar-wrap']);
            echo html_writer::tag('div', '', [
                'id' => 'areteia-ingestion-bar',
                'class' => 'areteia-progress-bar-fill',
                'style' => 'width: 5%;'
            ]);
            echo html_writer::end_tag('div');
            
            echo html_writer::start_tag('div', ['class' => 'areteia-progress-info']);
            echo html_writer::tag('span', 'Iniciando...', ['id' => 'areteia-ingestion-status', 'class' => 'areteia-progress-status']);
            echo html_writer::tag('span', '5%', ['id' => 'areteia-ingestion-percent']);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div'); // progress-container

            echo html_writer::tag('p',
                'Una vez finalizado, podrás continuar con el proceso.',
                ['style' => 'font-size:12px; line-height:1.4; margin-top:15px; color:#666;']
            );
            echo html_writer::end_tag('div');
            
            // Initialize the poller
            echo html_writer::tag('script', "document.addEventListener('DOMContentLoaded', () => { initIngestionPoller($id); });");

            
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link($prev_url, '← Volver', ['class' => 'areteia-btn']);
            echo html_writer::tag('span', 'Paso 1 de 7', ['class' => 'areteia-ncnt']);
            echo html_writer::tag('span', 'Procesando...', [
                'class' => 'areteia-btn disabled',
                'style' => 'opacity:0.7; cursor:wait;',
            ]);
            echo html_writer::end_tag('div');

        } else if ($ingested == -1) {
            // Error
            echo html_writer::start_tag('div', [
                'class' => 'areteia-card',
                'style' => 'border-left: 5px solid #dc3545; background: #fff4f4; margin-bottom:20px;',
            ]);
            echo html_writer::tag('strong', '❌ Error de conexión', [
                'style' => 'color:#dc3545; display:block; margin-bottom:5px;',
            ]);
            echo html_writer::tag('p',
                'Hubo un fallo al intentar conectarse al servicio Python. Verifica los logs de docker.',
                ['style' => 'font-size:12px; margin:0;']
            );
            echo html_writer::end_tag('div');

            $retry_url = new moodle_url($PAGE->url, ['id' => $id, 'action' => 'ingest']);
            step_renderer::render_nav(1, $prev_url, $retry_url, 'Reintentar Construcción');

        } else if ($service_down) {
            // Service not ready
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link($prev_url, '← Volver', ['class' => 'areteia-btn']);
            echo html_writer::tag('span', 'Paso 1 de 7', ['class' => 'areteia-ncnt']);
            echo html_writer::tag('span', 'Esperando al servicio de IA...', [
                'class' => 'areteia-btn disabled',
                'style' => 'opacity:0.7; cursor:wait;',
            ]);
            echo html_writer::end_tag('div');

        } else {
            // Ready to build - Use a form to send the selected files list via POST
            echo html_writer::start_tag('form', [
                'action' => new moodle_url($PAGE->url, ['step' => 1, 'action' => 'ingest']),
                'method' => 'POST',
                'id'     => 'areteia-ingest-form',
                'style'  => 'display:contents;'
            ]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'selected_files', 'id' => 'selected-files-input', 'value' => '']);
            
            $ing_url = new moodle_url($PAGE->url, ['step' => 1, 'action' => 'ingest']);
            step_renderer::render_nav(1, $prev_url, null, 'Confirmar y construir Biblioteca', [
                'id'      => 'confirm-ingest-btn',
                'data-ia' => '1'
            ]);
            
            echo html_writer::end_tag('form');
        }
    }
}
