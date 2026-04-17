<?php
namespace local_areteia\steps;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;
use local_areteia\session_manager;
use local_areteia\step_renderer;

/**
 * Step 6 — Resultado de Selección de Ítems (JSON).
 * Displays the final structured selection before proceeding.
 */
class step6 {

    public static function render(array $ctx): void {
        global $PAGE;

        $id = $ctx['id'];
        
        // Retrieve selection from POST or session cache
        $selection_raw = optional_param('selection_json', '', PARAM_RAW);
        
        if (!empty($selection_raw)) {
            session_manager::set('final_selection_json', $selection_raw);
        } else {
            $selection_raw = session_manager::get('final_selection_json', '');
        }

        echo html_writer::tag('span', 'Paso 6 — Selección Final (JSON)', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Estructura técnica del instrumento generado', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p', 
            'Esta es la representación estructurada de los ítems que has seleccionado. Este JSON será utilizado para construir la rúbrica y exportar a Moodle.',
            ['class' => 'areteia-sdesc']
        );

        if (empty($selection_raw)) {
            echo html_writer::tag('div', 'No hay ítems seleccionados. Por favor, vuelve al paso anterior.', ['class' => 'alert alert-warning']);
        } else {
            $data = json_decode($selection_raw, true);
            
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'background:#2d2d2d; border:none; padding:0; border-radius:12px; overflow:hidden; margin-bottom:20px;']);
            
            // JSON Header
            echo html_writer::start_tag('div', ['style' => 'background:#1e1e1e; padding:10px 20px; display:flex; justify-content:space-between; align-items:center;']);
            echo html_writer::tag('span', 'instrument_selection.json', ['style' => 'color:#aaa; font-family:monospace; font-size:12px;']);
            echo html_writer::tag('button', '📋 Copiar JSON', [
                'class' => 'btn-copy-json',
                'onclick' => 'copyJsonToClipboard()',
                'style' => 'background:transparent; border:1px solid #444; color:#ccc; font-size:11px; padding:4px 10px; border-radius:4px; cursor:pointer;'
            ]);
            echo html_writer::end_tag('div');
            
            // JSON Content
            echo html_writer::start_tag('pre', [
                'id' => 'final-json-preview',
                'style' => 'margin:0; padding:20px; color:#9cdcfe; background:#2d2d2d; font-family: "Fira Code", "Courier New", monospace; font-size:13px; max-height:500px; overflow-y:auto; line-height:1.5;'
            ]);
            echo s(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo html_writer::end_tag('pre');
            
            echo html_writer::end_tag('div');
            
            // Client-side script for copying and styling (simple highlighting simulation)
            echo '
            <script>
            function copyJsonToClipboard() {
                const text = document.getElementById("final-json-preview").innerText;
                navigator.clipboard.writeText(text).then(() => {
                    const btn = document.querySelector(".btn-copy-json");
                    const oldText = btn.innerText;
                    btn.innerText = "✅ ¡Copiado!";
                    setTimeout(() => btn.innerText = oldText, 2000);
                });
            }
            </script>
            ';
        }

        // Navigation
        $link_params = ['id' => $id];
        $prev_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 5]));
        $next_url = new moodle_url($PAGE->url, array_merge($link_params, ['step' => 7]));

        step_renderer::render_nav(6, $prev_url, $next_url, 'Continuar a Rúbrica →');
    }
}
