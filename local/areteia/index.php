<?php
require_once(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);
$step = optional_param('step', 0, PARAM_INT);
$use_moodle = optional_param('use_moodle', 1, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

require_login();

if (!$id) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Please provide a Course ID (?id=XX)', 'error');
    echo $OUTPUT->footer();
    die();
}

require_once($CFG->libdir . '/filelib.php');

$context = context_course::instance($id);
$PAGE->set_url(new moodle_url('/local/areteia/index.php', ['id' => $id, 'step' => $step]));
$PAGE->set_context($context);
$PAGE->set_title('AretéIA — Flujo docente');
$PAGE->set_heading('AretéIA — Flujo módulo docente');
$PAGE->set_pagelayout('report'); // Wider layout

// Include our custom CSS by inlining it for maximum compatibility/reliability in the prototype
// Check if it is an AJAX request to return only the inner content
$is_ajax = optional_param('ajax', 0, PARAM_INT);
if ($is_ajax) {
    // Note: We don't call $OUTPUT->header() for AJAX
    ob_start();
} else {
    echo $OUTPUT->header();
    // Include our custom CSS by inlining it for maximum compatibility/reliability in the prototype
    echo '<style>' . file_get_contents(__DIR__ . '/styles.css') . '</style>';
    
    // AJAX script for navigation and state preservation
    echo '<script>';
    echo <<<JS
    document.addEventListener("click", e => {
        const link = e.target.closest("a.opt, a.s0-card, a.sug-card, a.areteia-btn, a.fb-btn, a.areteia-dot");
        if (!link || link.classList.contains("external")) return;
        
        // Handle confirmation if requested - BEFORE fetch
        if (link.dataset.confirm && !confirm(link.dataset.confirm)) {
            e.preventDefault();
            return;
        }

        e.preventDefault();
        const url = new URL(link.href);
        url.searchParams.set("ajax", "1");
        
        // Auto-capture the objective if we are in Step 3
        const d2Area = document.querySelector('textarea[name="d2"]');
        if (d2Area) { url.searchParams.set("d2", d2Area.value); }
        
        fetch(url).then(r => {
            if (!r.ok) throw new Error("Server error " + r.status);
            // Clean up ajax parameter for the browser history
            const finalUrl = new URL(r.url);
            finalUrl.searchParams.delete("ajax");
            window.history.pushState({}, "", finalUrl.toString());
            return r.text();
        }).then(html => {
            document.getElementById("areteia-main").innerHTML = html;
            if (url.searchParams.has("step") && url.searchParams.get("step") !== new URL(location.href).searchParams.get("step")) {
                window.scrollTo({ top: 0, behavior: "smooth" });
            }
            if (typeof initStep3Reactivity === "function") initStep3Reactivity();
            if (typeof initGenerativeLoading === "function") initGenerativeLoading();
        }).catch(err => {
            console.error(err);
            alert("Error en la comunicación con el servidor. Por favor, reintenta.");
            // Reset button if found
            const loadingBtn = document.querySelector('.areteia-btn-primary.is-loading');
            if (loadingBtn) {
                loadingBtn.classList.remove('is-loading');
                loadingBtn.innerHTML = loadingBtn.dataset.oldHtml || "Error - Reintentar";
                loadingBtn.style.opacity = '1';
            }
        });
    });
    
    // Reactivity for Step 3 textarea and buttons
    function initStep3Reactivity() {
        const btn = document.getElementById("next-step-btn");
        const d2 = document.querySelector('textarea[name="d2"]');
        
        if (!btn || !d2) return;
        
        const updateBtn = () => {
            const hasD2 = d2.value.trim().length > 0;
            const activeOpts = document.querySelectorAll(".opt.main").length;
            const expectedOpts = 3; 
            
            if (hasD2 && activeOpts >= expectedOpts) {
                btn.classList.remove("disabled");
                btn.style.opacity = "1";
                btn.style.cursor = "pointer";
                btn.innerHTML = "Ver Sugerencias →";
            } else {
                btn.classList.add("disabled");
                btn.style.opacity = "0.5";
                btn.style.cursor = "not-allowed";
                btn.innerHTML = "Completa todas las dimensiones";
            }
        };
        
        d2.addEventListener("input", updateBtn);
        updateBtn();
    }
    
    function initGenerativeLoading() {
        document.querySelectorAll('.areteia-btn-primary').forEach(btn => {
            if (btn.dataset.bound) return;
            btn.dataset.bound = "1";
            btn.addEventListener('click', function(e) {
                // If the link is already loading, prevent clicking again
                if (this.classList.contains('is-loading')) { e.preventDefault(); return; }
                
                // Detection for IA buttons (either dash sparkle or data-ia)
                let isIA = this.innerText.includes('✨') || this.dataset.ia === "1";
                let label = isIA ? 'Generando con IA...' : 'Cargando...';
                
                this.classList.add('is-loading');
                this.dataset.oldHtml = this.innerHTML;
                this.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> ' + label;
                this.style.opacity = '0.7';
                // Don't disable pointer events globally; the handler above handles it
            });
        });
    }

    window.addEventListener("popstate", () => location.reload());
    document.addEventListener("DOMContentLoaded", () => {
        initStep3Reactivity();
        initGenerativeLoading();
    });
    </script>
JS;
    echo '</script>';
}

// --- Logic for Sync/Ingest (Step 0/1 background) ---
if ($action === 'sync') {
    // Release session lock so AJAX/Nav can work while sync happens
    if (method_exists('\core\session\manager', 'write_close')) {
        \core\session\manager::write_close();
    }
    
    $files = \local_areteia\data_provider::get_course_files($id, true);
    $summary = \local_areteia\data_provider::get_course_summary($id);
    
    // Send a minimal payload to Python
    $payload = json_encode(['course' => $summary, 'files' => []]);
    $curl = new \curl(['ignoresecurity' => true]);
    $curl->setHeader('Content-Type: application/json');
    $curl->setopt(['CURLOPT_TIMEOUT' => 60, 'CURLOPT_CONNECTTIMEOUT' => 20]);
    $curl->post('http://python_rag:8000/sync', $payload);
    
    $redir_url = new moodle_url($PAGE->url, ['step' => 1, 'use_moodle' => 1]);
    if ($is_ajax) $redir_url->param('ajax', 1);
    redirect($redir_url);
}

if ($action === 'ingest') {
    $payload = json_encode(['course_id' => $id]);
    $curl = new \curl(['ignoresecurity' => true]);
    $curl->setHeader('Content-Type: application/json');
    
    // Increase timeout since chunking/embedding can take several minutes on CPU
    $curl->setopt(['CURLOPT_TIMEOUT' => 600, 'CURLOPT_CONNECTTIMEOUT' => 30]);
    \core_php_time_limit::raise(600);
    
    $response = $curl->post('http://python_rag:8000/ingest', $payload);
    $res_data = @json_decode($response);
    
    $ing_state = ($res_data && $res_data->status == 'success') ? ($res_data->chunks > 0 ? 1 : 2) : -1;
    if ($res_data && $res_data->chunks == 0) $ing_state = 2; // Empty content

    $redir_url = new moodle_url($PAGE->url, ['step' => 1, 'use_moodle' => 1, 'ingested' => $ing_state]);
    if ($is_ajax) $redir_url->param('ajax', 1);
    redirect($redir_url);
}

// Action handling moved inside try block for stability

try {
    // --- SESSION STATE MANAGEMENT ---
    global $SESSION;
    if (!isset($SESSION->areteia)) {
        $SESSION->areteia = new stdClass();
    }

    // List of parameters to persist (either from URL or from SESSION)
    $params_to_save = ['use_moodle', 'path', 'ingested', 'sum_ok', 'd1', 'd2', 'd3', 'd4', 'sel_sug', 'instrument', 'exported', 'cmid'];
    
    // Cascading invalidation logic
    $dim_changed = false;
    foreach (['use_moodle', 'path', 'd1', 'd2', 'd3', 'd4'] as $dim) {
        $val = optional_param($dim, null, ($dim == 'd2' ? PARAM_RAW : PARAM_TEXT));
        if ($val !== null && isset($SESSION->areteia->$dim)) {
            $val_clean = trim(str_replace("\r\n", "\n", (string)$val));
            $sess_clean = trim(str_replace("\r\n", "\n", (string)$SESSION->areteia->$dim));
            if ($val_clean !== '' && $val_clean !== $sess_clean) {
                $dim_changed = true;
            }
        }
    }

    $unlock = optional_param('unlock', 0, PARAM_INT);
    if ($unlock) {
        $dim_changed = true;
        if ($unlock == 2) {
            // Hard reset for step 0 and 2 changes
            unset($SESSION->areteia->d1);
            unset($SESSION->areteia->d2);
            unset($SESSION->areteia->d3);
            unset($SESSION->areteia->d4);
        }
    }

    if ($dim_changed) {
        // Clear everything downstream if dimensions change
        unset($SESSION->areteia->s_sugs);
        unset($SESSION->areteia->sel_sug);
        unset($SESSION->areteia->instrument);
        unset($SESSION->areteia->inst_content);
        unset($SESSION->areteia->rubric_content);
    }

    $inst_val = optional_param('instrument', null, PARAM_TEXT);
    if ($inst_val !== null && isset($SESSION->areteia->instrument) && $SESSION->areteia->instrument !== $inst_val) {
        // Clear generated content if instrument changes
        unset($SESSION->areteia->inst_content);
        unset($SESSION->areteia->rubric_content);
    }

    foreach ($params_to_save as $p) {
        $val = optional_param($p, null, ($p == 'd2' ? PARAM_RAW : PARAM_TEXT)); // Use null as default to detect if present
        if ($val !== null) {
            $SESSION->areteia->$p = $val;
        }
    }

    // Local variables for convenience
    $use_moodle = $SESSION->areteia->use_moodle ?? 1;
    $path = $SESSION->areteia->path ?? '';
    $ingested = $SESSION->areteia->ingested ?? 0;
    $sum_ok = $SESSION->areteia->sum_ok ?? 0;
    $d1 = $SESSION->areteia->d1 ?? '';
    $d2 = $SESSION->areteia->d2 ?? '';
    $d3 = $SESSION->areteia->d3 ?? '';
    $d4 = $SESSION->areteia->d4 ?? '';
    $sel_sug = $SESSION->areteia->sel_sug ?? '';
    $instrument = $SESSION->areteia->instrument ?? '';
    $exported = $SESSION->areteia->exported ?? 0;
    $cmid = $SESSION->areteia->cmid ?? 0;

    // List of parameters to persist in URL (only small configuration IDs/steps for basic nav)
    $url_params = [
        'id' => $id,
        'step' => $step
    ];

    // Capture incoming large content and store in SESSION if provided
    $in_sugs = optional_param('s_sugs', '', PARAM_RAW);
    if ($in_sugs) $SESSION->areteia->s_sugs = $in_sugs;
    $s_sugs_raw = $SESSION->areteia->s_sugs ?? '';

    $in_inst = optional_param('inst_content', '', PARAM_RAW);
    if ($in_inst) $SESSION->areteia->inst_content = $in_inst;
    $inst_content = $SESSION->areteia->inst_content ?? '';

    // Handle Export action inside the try/catch for better debugging
    if ($action === 'export') {
        // If empty (already handled by session capture above, but just in case)
        $inst_name = $SESSION->areteia->instrument . ' - AretéIA';
        $final_desc = $SESSION->areteia->inst_content;
        if (!empty($SESSION->areteia->rubric_content)) {
            $final_desc .= "\n\n### Rúbrica\n" . $SESSION->areteia->rubric_content;
        }
        
        if (!$inst_name) $inst_name = 'Evaluación AretéIA';
        if (!$final_desc) $final_desc = 'Instrumento generado por AretéIA.';

        $moduleinfo = \local_areteia\data_provider::create_assign_activity($id, $inst_name, $final_desc);
        
        $redir_url = new moodle_url($PAGE->url, ['step' => 7, 'exported' => 1, 'cmid' => $moduleinfo->coursemodule]);
        if ($is_ajax) $redir_url->param('ajax', 1);
        redirect($redir_url);
    }

    $in_rub = optional_param('rubric_content', '', PARAM_RAW);
    if ($in_rub) $SESSION->areteia->rubric_content = $in_rub;
    $rubric_content = $SESSION->areteia->rubric_content ?? '';

    // Parameters for all internal links - NO LARGE STRINGS HERE to avoid 414
    $all_params = $url_params;

    $summary = \local_areteia\data_provider::get_course_summary($id);
    $files = \local_areteia\data_provider::get_course_files($id);

    if (!$is_ajax) {
        echo html_writer::start_tag('div', ['class' => 'areteia-wrap', 'id' => 'areteia-main']);
    }
    
    echo html_writer::start_tag('div', ['class' => 'areteia-inner']);
    echo html_writer::tag('p', 'AretéIA · Prototipo', ['class' => 'areteia-subtitle']);

    // Progress Bar
    echo html_writer::start_tag('div', ['class' => 'areteia-progress']);
    for ($i = 0; $i <= 7; $i++) {
        $class = ($i < $step) ? 'done' : (($i == $step) ? 'active' : 'pending');
        $dot_url = new moodle_url($PAGE->url, array_merge($all_params, ['step' => $i]));
        echo html_writer::link($dot_url, $i, ['class' => 'areteia-dot ' . $class]);
        if ($i < 7) {
            $line_class = ($i < $step) ? 'done' : '';
            echo html_writer::tag('div', '', ['class' => 'areteia-line ' . $line_class]);
        }
    }
    echo html_writer::end_tag('div');

    // Main Card
    echo html_writer::start_tag('div', ['class' => 'areteia-card']);

    if ($step == 0) {
        echo html_writer::tag('span', 'Paso 0 — Punto de entrada', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', '¿Tenés tu curso cargado en Moodle?', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p', 'AretéIA ha detectado contenido en este curso. ¿Querés importar los datos automáticamente o cargar el contexto manualmente?', ['class' => 'areteia-sdesc']);
        
        $s0_active = isset($_GET['use_moodle']) ? optional_param('use_moodle', 1, PARAM_INT) : ($SESSION->areteia->use_moodle ?? null);
        $sel_moodle = ($s0_active !== null && (int)$s0_active === 1) ? 'sel' : '';
        $sel_manual = ($s0_active !== null && (int)$s0_active === 0) ? 'sel' : '';

        $has_downstream = (!empty($SESSION->areteia->d1) || !empty($SESSION->areteia->s_sugs) || !empty($SESSION->areteia->instrument));

        if ($has_downstream && $s0_active !== null) {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'border-left: 5px solid #fac775; background: #fffcf0; margin-bottom:20px; padding:15px;']);
            echo html_writer::tag('strong', '🔒 Opción bloqueada', ['style' => 'color:#633806; display:block; margin-bottom:5px;']);
            echo html_writer::tag('p', 'Tu modo de inicio está protegido porque ya avanzaste en el diseño pedagógico.', ['style' => 'font-size:12px; margin-bottom:15px; color:#555;']);
            $unlock_url = new moodle_url($PAGE->url, array_merge($url_params, ['step' => 0, 'unlock' => 2]));
            echo html_writer::link($unlock_url, '🔓 Cambiar de modo (Se borrará progreso)', ['class' => 'areteia-btn', 'style' => 'border-color:#fac775; color:#633806;']);
            echo html_writer::end_tag('div');
        }

        echo html_writer::start_tag('div', ['class' => 's0-grid']);
        
        $moodle_url = new moodle_url($PAGE->url, ['use_moodle' => 1]);
        if ($has_downstream && $s0_active !== null) {
            echo html_writer::start_tag('span', ['class' => "s0-card $sel_moodle", 'style' => 'opacity:0.6; cursor:not-allowed;']);
        } else {
            echo html_writer::start_tag('a', ['href' => $moodle_url, 'class' => "s0-card $sel_moodle"]);
        }
        echo html_writer::tag('strong', 'Sí, usar contenidos de Moodle');
        echo html_writer::tag('span', 'AretéIA importará archivos, secciones y actividades automáticamente. Recomendado para este curso.');
        echo ($has_downstream && $s0_active !== null) ? html_writer::end_tag('span') : html_writer::end_tag('a');

        $manual_url = new moodle_url($PAGE->url, ['use_moodle' => 0]);
        if ($has_downstream && $s0_active !== null) {
            echo html_writer::start_tag('span', ['class' => "s0-card $sel_manual", 'style' => 'opacity:0.6; cursor:not-allowed;']);
        } else {
            echo html_writer::start_tag('a', ['href' => $manual_url, 'class' => "s0-card $sel_manual"]);
        }
        echo html_writer::tag('strong', 'No, cargar manualmente');
        echo html_writer::tag('span', 'Empezarás con un formulario vacío para definir el contexto pedagógico desde cero.');
        echo ($has_downstream && $s0_active !== null) ? html_writer::end_tag('span') : html_writer::end_tag('a');

        echo html_writer::end_tag('div');

        echo html_writer::tag('div', 'Nota: En ambos casos el proceso de diseño es exactamente el mismo.', ['class' => 'areteia-note']);

        echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
        
        // Option to clear session for testing (now on the left)
        if (isset($SESSION->areteia)) {
            $clear_url = new moodle_url($PAGE->url, ['step' => 0, 'clear' => 1]);
            echo html_writer::link($clear_url, 'Borrar Progreso 🗑️', ['class' => 'areteia-btn', 'style' => 'font-size:11px;']);
        } else {
            echo '<span></span>'; // Placeholder
        }

        echo html_writer::tag('span', 'Paso 0 de 7', ['class' => 'areteia-ncnt']);
        
        if ($s0_active !== null) {
            // Trigger sync if Moodle is selected, go to Step 1
            $s0_url = new moodle_url($PAGE->url, ['step' => 1, 'use_moodle' => $s0_active, 'action' => ($s0_active ? 'sync' : '')]);
            echo html_writer::link($s0_url, 'Sincronizar y Continuar →', ['class' => 'areteia-btn areteia-btn-primary']);
        } else {
            echo html_writer::tag('span', 'Selecciona una opción', ['class' => 'areteia-btn disabled', 'style' => 'opacity:0.5;']);
        }
        echo html_writer::end_tag('div');

        if (optional_param('clear', 0, PARAM_INT)) {
            unset($SESSION->areteia);
            $redir_url = new moodle_url($PAGE->url, ['step' => 0]);
            if ($is_ajax) $redir_url->param('ajax', 1);
            redirect($redir_url);
        }
    } 
    else if ($step == 1) {
        echo html_writer::tag('span', 'Paso 1 — Contexto objetivo', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Contexto pedagógico de la asignatura', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p', 'Verificá la información importada de Moodle para asegurar que el RAG tenga el contexto correcto.', ['class' => 'areteia-sdesc']);

        // Check if embeddings already exist to avoid redundant builds
        $curl = new \curl(['ignoresecurity' => true]);
        $status_json = $curl->get('http://python_rag:8000/status/' . $id);
        $status_data = @json_decode($status_json);
        $already_ingested = ($status_data && !empty($status_data->embedding_exists));
        $service_down = ($status_json === false || empty($status_json));

        if ($use_moodle) {
            echo html_writer::start_tag('div', ['class' => 'areteia-fields']);
            
            // Asignatura
            echo html_writer::start_tag('div', ['class' => 'areteia-fr']);
            echo html_writer::start_tag('div', ['class' => 'areteia-flbl']);
            echo 'Asignatura ' . html_writer::tag('span', 'Moodle', ['class' => 'areteia-origin']);
            echo html_writer::tag('span', 'Confirmado', ['class' => 'sb-tag sb-ok']);
            echo html_writer::end_tag('div');
            echo html_writer::tag('div', $summary['fullname'], ['class' => 'areteia-fb fc']);
            echo html_writer::end_tag('div');

            // Resumen/Programa
            $sum_ok = optional_param('sum_ok', 0, PARAM_INT);
            echo html_writer::start_tag('div', ['class' => 'areteia-fr']);
            echo html_writer::start_tag('div', ['class' => 'areteia-flbl']);
            echo 'Programa / Resumen ' . html_writer::tag('span', 'Moodle', ['class' => 'areteia-origin']);
            $res_tag = (!empty($summary['summary']) || $sum_ok) ? ['sb-tag sb-ok', 'Confirmado'] : ['sb-tag sb-warn', 'Verificar'];
            echo html_writer::tag('span', $res_tag[1], ['class' => $res_tag[0]]);
            echo html_writer::end_tag('div');
            echo html_writer::tag('div', $summary['summary'] ?: 'Sin resumen en Moodle', ['class' => 'areteia-fb fw']);
            echo html_writer::start_tag('div', ['class' => 'areteia-fa']);
            
            $conf_url = new moodle_url($PAGE->url, ['step' => 1, 'sum_ok' => 1]);
            echo html_writer::link($conf_url, 'Confirmar', ['class' => 'fb-btn ok', 'style' => 'text-decoration:none']);
            echo html_writer::tag('button', 'Editar', ['class' => 'fb-btn', 'onclick' => "alert('Edición de resumen próximamente en el prototipo.')"]);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');

            // Materiales
            echo html_writer::start_tag('div', ['class' => 'areteia-fr']);
            echo html_writer::start_tag('div', ['class' => 'areteia-flbl']);
            echo 'Materiales detectados ' . html_writer::tag('span', 'Moodle', ['class' => 'areteia-origin']);
            if ($already_ingested) {
                $chunks = $status_data->chunks ?? 0;
                echo html_writer::tag('span', "Verificado ($chunks fragmentos)", ['class' => 'sb-tag sb-ok']);
            } else if ($service_down) {
                echo html_writer::tag('span', 'Servicio no disponible', ['class' => 'sb-tag sb-warn', 'style' => 'background:#ff9800']);
            } else {
                echo html_writer::tag('span', 'Verificar', ['class' => 'sb-tag sb-warn']);
            }
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', ['class' => 'areteia-fb fw']);
            
            if ($already_ingested) {
                echo html_writer::tag('div', 'Embeddings detectados y persistentes.', ['class' => 'areteia-fb fw', 'style' => 'color:#28a745; font-weight:bold;']);
                if (!empty($status_data->path)) {
                    echo html_writer::tag('small', 'Ruta: ' . s($status_data->path), ['style' => 'display:block; color:#999; font-size:10px; margin-top:4px;']);
                }
            } else if ($service_down) {
                echo html_writer::tag('div', 'El servicio de IA aún no ha arrancado (posible reinicio de PC). Reintentando...', ['class' => 'areteia-fb fw', 'style' => 'color:#ff9800;']);
            } else {
                echo html_writer::tag('div', 'Se han detectado ' . count($files) . ' archivos listos para RAG.', ['class' => 'areteia-fb fw']);
            }
            if (count($files) > 0) {
                echo '<ul style="list-style:none; padding:0; font-size:12px; margin-top:10px; color:#555;">';
                foreach (array_slice($files, 0, 10) as $f) {
                    echo '<li style="margin-bottom:4px; display:flex; align-items:center;">';
                    echo '<span style="color:#28a745; margin-right:8px;">📄</span>';
                    echo '<div>' . s($f['filename']) . ' <br><small style="color:#999;">' . s($f['module']) . '</small></div>';
                    echo '</li>';
                }
                if (count($files) > 10) echo '<li style="color:#999; margin-left:22px;">... y ' . (count($files) - 10) . ' más.</li>';
                echo '</ul>';
            }
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        } else {
            echo $OUTPUT->notification('Carga manual no implementada en este prototipo.', 'warning');
        }

        $ingested = optional_param('ingested', 0, PARAM_INT);
        if ($already_ingested || $ingested == 1) {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'border-left: 5px solid #28a745; background: #f4fff4; margin-bottom:20px;']);
            echo html_writer::tag('strong', '✨ ¡Embeddings construidos con éxito!', ['style' => 'color:#28a745; display:block; margin-bottom:5px;']);
            echo html_writer::tag('p', 'La IA ya tiene acceso a los contenidos de tu curso para darte mejores respuestas.', ['style' => 'font-size:12px; margin:0;']);
            echo html_writer::end_tag('div');
            
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link(new moodle_url($PAGE->url, ['step' => 0]), '← Volver', ['class' => 'areteia-btn']);
            echo html_writer::tag('span', 'Paso 1 de 7', ['class' => 'areteia-ncnt']);
            $next_url = new moodle_url($PAGE->url, ['step' => 2]);
            echo html_writer::link($next_url, 'Continuar al paso 2 →', ['class' => 'areteia-btn areteia-btn-primary']);
            echo html_writer::end_tag('div');
        } else if ($ingested == 2) {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'border-left: 5px solid #ffca28; background: #fffcf0; margin-bottom:20px;']);
            echo html_writer::tag('strong', '⚠️ Sin texto extraíble', ['style' => 'color:#ffca28; display:block; margin-bottom:5px;']);
            echo html_writer::tag('p', 'Moodle entregó los archivos, pero la IA no encontró texto (quizás sean PDFs escaneados o carpetas vacías). Esto limitará las sugerencias de RAG.', ['style' => 'font-size:12px; line-height:1.4; margin:0;']);
            echo html_writer::end_tag('div');
            
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link(new moodle_url($PAGE->url, ['step' => 0]), '← Volver', ['class' => 'areteia-btn']);
            echo html_writer::tag('span', 'Paso 1 de 7', ['class' => 'areteia-ncnt']);
            $next_url = new moodle_url($PAGE->url, ['step' => 2]);
            echo html_writer::link($next_url, 'Continuar al paso 2 →', ['class' => 'areteia-btn areteia-btn-primary']);
            echo html_writer::end_tag('div');
        } else if ($ingested == -1) {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'border-left: 5px solid #dc3545; background: #fff4f4; margin-bottom:20px;']);
            echo html_writer::tag('strong', '❌ Error de conexión', ['style' => 'color:#dc3545; display:block; margin-bottom:5px;']);
            echo html_writer::tag('p', 'Hubo un fallo al intentar conectarse al servicio Python. Verifica los logs de docker.', ['style' => 'font-size:12px; margin:0;']);
            echo html_writer::end_tag('div');
            
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link(new moodle_url($PAGE->url, ['step' => 0]), '← Volver', ['class' => 'areteia-btn']);
            echo html_writer::tag('span', 'Paso 1 de 7', ['class' => 'areteia-ncnt']);
            $ing_url = new moodle_url($PAGE->url, ['id' => $id, 'action' => 'ingest']);
            echo html_writer::link($ing_url, 'Reintentar Construcción', ['class' => 'areteia-btn areteia-btn-primary']);
            echo html_writer::end_tag('div');
        } else if ($service_down) {
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link(new moodle_url($PAGE->url, ['step' => 0]), '← Volver', ['class' => 'areteia-btn']);
            echo html_writer::tag('span', 'Paso 1 de 7', ['class' => 'areteia-ncnt']);
            echo html_writer::tag('span', 'Esperando al servicio de IA...', ['class' => 'areteia-btn disabled', 'style' => 'opacity:0.7; cursor:wait;']);
            echo '<script>setTimeout(() => location.reload(), 3000);</script>';
            echo html_writer::end_tag('div');
        } else {
            echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
            echo html_writer::link(new moodle_url($PAGE->url, ['step' => 0]), '← Volver', ['class' => 'areteia-btn']);
            echo html_writer::tag('span', 'Paso 1 de 7', ['class' => 'areteia-ncnt']);
            $ing_url = new moodle_url($PAGE->url, ['step' => 1, 'action' => 'ingest']);
            echo html_writer::link($ing_url, 'Confirmar y Construir Embeddings', ['class' => 'areteia-btn areteia-btn-primary']);
            echo html_writer::end_tag('div');
        }
    }
    else if ($step == 2) {
        echo html_writer::tag('span', 'Paso 2 — Bifurcación', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', '¿Ya tenés en mente un instrumento de evaluación?', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p', 'El sistema ofrece dos caminos. El diálogo del paso 3 se adapta según lo que respondas acá.', ['class' => 'areteia-sdesc']);

        // Use $path from session
        $sel_a = ($path === 'A') ? 'sel' : '';
        $sel_b = ($path === 'B') ? 'sel' : '';

        $has_downstream = (!empty($SESSION->areteia->d1) || !empty($SESSION->areteia->s_sugs));

        if ($has_downstream && $path !== '') {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'border-left: 5px solid #fac775; background: #fffcf0; margin-bottom:20px; padding:15px;']);
            echo html_writer::tag('strong', '🔒 Opción bloqueada', ['style' => 'color:#633806; display:block; margin-bottom:5px;']);
            echo html_writer::tag('p', 'Tu camino está protegido porque ya avanzaste en el diseño pedagógico.', ['style' => 'font-size:12px; margin-bottom:15px; color:#555;']);
            $unlock_url = new moodle_url($PAGE->url, array_merge($url_params, ['step' => 2, 'unlock' => 2]));
            echo html_writer::link($unlock_url, '🔓 Cambiar camino (Se borrará progreso)', ['class' => 'areteia-btn', 'style' => 'border-color:#fac775; color:#633806;']);
            echo html_writer::end_tag('div');
        }

        echo html_writer::start_tag('div', ['class' => 's0-grid']); 
        
        $path_a_url = new moodle_url($PAGE->url, ['step' => 2, 'path' => 'A']);
        if ($has_downstream && $path !== '') {
            echo html_writer::start_tag('span', ['class' => "s0-card $sel_a", 'style' => 'opacity:0.6; cursor:not-allowed;']);
        } else {
            echo html_writer::start_tag('a', ['href' => $path_a_url, 'class' => "s0-card $sel_a"]);
        }
        echo html_writer::tag('strong', 'Camino A — Ya sé qué quiero');
        echo html_writer::tag('span', 'Indicarás el instrumento que tenés en mente. La IA lo validará contra el objetivo que emerja en el paso 3.');
        echo ($has_downstream && $path !== '') ? html_writer::end_tag('span') : html_writer::end_tag('a');

        $path_b_url = new moodle_url($PAGE->url, ['step' => 2, 'path' => 'B']);
        if ($has_downstream && $path !== '') {
            echo html_writer::start_tag('span', ['class' => "s0-card $sel_b", 'style' => 'opacity:0.6; cursor:not-allowed;']);
        } else {
            echo html_writer::start_tag('a', ['href' => $path_b_url, 'class' => "s0-card $sel_b"]);
        }
        echo html_writer::tag('strong', 'Camino B — No lo tengo decidido');
        echo html_writer::tag('span', 'Avanzarás sin elegir. El paso 3 trabajará el objetivo desde cero y la IA sugerirá instrumentos después.');
        echo ($has_downstream && $path !== '') ? html_writer::end_tag('span') : html_writer::end_tag('a');
        
        echo html_writer::end_tag('div');

        echo html_writer::tag('div', 'Nota: En el Camino A, la IA no descarta tu instrumento automáticamente, sino que lo problematiza pedagógicamente al final.', ['class' => 'areteia-note']);

        echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
        echo html_writer::link(new moodle_url($PAGE->url, ['step' => 1]), '← Anterior', ['class' => 'areteia-btn']);
        echo html_writer::tag('span', 'Paso 2 de 7', ['class' => 'areteia-ncnt']);
        
        if ($path) {
            echo html_writer::link(new moodle_url($PAGE->url, ['step' => 3, 'path' => $path]), 'Continuar al paso 3 →', ['class' => 'areteia-btn areteia-btn-primary']);
        } else {
            echo html_writer::tag('span', 'Selecciona un camino →', ['class' => 'areteia-btn disabled', 'style' => 'opacity:0.5;']);
        }
        echo html_writer::end_tag('div');
    }
    else if ($step == 3) {
        $path = optional_param('path', 'B', PARAM_ALPHA);
        $show_ai = optional_param('ai', 0, PARAM_INT);

        echo html_writer::tag('span', 'Paso 3 — Diálogo con la IA', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Clarificación del objetivo de evaluación', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p', 'Dimensiones clave para definir qué y cómo evaluar.', ['class' => 'areteia-sdesc']);

        // We collect core params for merging into links. Dimensions stay in session.
        $all_params = [
            'id' => $id, 
            'step' => $step, 
            'path' => $path, 
            'use_moodle' => $use_moodle,
            'ai' => $show_ai
        ];

        // Helper for dimensions with confirmation logic
        $has_downstream = (!empty($SESSION->areteia->s_sugs) || !empty($SESSION->areteia->instrument));
        $is_locked = $has_downstream;

        if ($is_locked) {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'border-left: 5px solid #fac775; background: #fffcf0; margin-bottom:20px; padding:15px;']);
            echo html_writer::tag('strong', '🔒 Dimensiones bloqueadas', ['style' => 'color:#633806; display:block; margin-bottom:5px;']);
            echo html_writer::tag('p', 'Tu diseño está protegido porque ya has generado contenido en los siguientes pasos.', ['style' => 'font-size:12px; margin-bottom:15px; color:#555;']);
            
            $unlock_url = new moodle_url($PAGE->url, array_merge($all_params, ['unlock' => 1]));
            echo html_writer::link($unlock_url, '🔓 Desbloquear y Editar (Se borrará progreso)', [
                'class' => 'areteia-btn', 
                'style' => 'border-color:#fac775; color:#633806;'
            ]);
            echo html_writer::end_tag('div');
        }

        // D1: Tipo de contenido
        echo html_writer::start_tag('div', ['class' => 'areteia-dim']);
        echo html_writer::tag('div', 'Dimensión 1 — Tipo de contenido', ['class' => 'dlbl']);
        echo html_writer::tag('div', '¿Qué tipo de contenido es el foco principal?', ['class' => 'dq', 'style' => 'font-weight:bold; margin-bottom:10px;']);
        $opts1 = ['Factual', 'Conceptual', 'Procedimental', 'Actitudinal'];
        echo html_writer::start_tag('div', ['class' => 'opts', 'style' => 'display:flex; gap:10px; flex-wrap:wrap;']);
        foreach ($opts1 as $o) {
            $active = ($d1 == $o) ? 'main' : '';
            if ($is_locked) {
                echo html_writer::tag('span', $o, ['class' => "opt $active", 'style' => 'opacity:0.6; cursor:not-allowed;']);
            } else {
                $link_url = new moodle_url($PAGE->url, array_merge($all_params, ['d1' => $o]));
                echo html_writer::link($link_url, $o, ['class' => "opt $active"]);
            }
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        // D2: Objetivo
        echo html_writer::start_tag('div', ['class' => 'areteia-dim']);
        echo html_writer::tag('div', 'Dimensión 2 — Objetivo de evaluación', ['class' => 'dlbl']);
        echo html_writer::tag('div', 'Formulá el objetivo: ¿qué querés saber si pueden hacer?', ['class' => 'dq', 'style' => 'font-weight:bold; margin-bottom:10px;']);
        echo html_writer::tag('div', '«Quiero saber si pueden [verbo] [contenido] [condiciones]»', ['class' => 'otpl', 'style' => 'background:#e6f1fb; padding:15px; border-radius:8px; border:1px solid #85b7eb; color:#185fa5; margin-bottom:10px;']);
        
        $ta_attrs = ['name' => 'd2', 'class' => 'form-control w-100 mb-2', 'placeholder' => 'Escribe aquí el objetivo...', 'rows' => 3];
        if ($is_locked) $ta_attrs['readonly'] = 'readonly';
        echo html_writer::tag('textarea', $d2, $ta_attrs);
        echo html_writer::end_tag('div');

        // D3: Función
        echo html_writer::start_tag('div', ['class' => 'areteia-dim']);
        echo html_writer::tag('div', 'Dimensión 3 — Función de la evaluación', ['class' => 'dlbl']);
        $opts3 = ['Diagnóstica', 'Formativa', 'Sumativa'];
        echo html_writer::start_tag('div', ['class' => 'opts', 'style' => 'display:flex; gap:10px;']);
        foreach ($opts3 as $o) {
            $active = ($d3 == $o) ? 'main' : '';
            if ($is_locked) {
                echo html_writer::tag('span', $o, ['class' => "opt $active", 'style' => 'opacity:0.6; cursor:not-allowed;']);
            } else {
                $link_url = new moodle_url($PAGE->url, array_merge($all_params, ['d3' => $o]));
                echo html_writer::link($link_url, $o, ['class' => "opt $active"]);
            }
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        // D4: Modalidad
        echo html_writer::start_tag('div', ['class' => 'areteia-dim', 'style' => 'border-bottom:none; padding-bottom:0;']);
        echo html_writer::tag('div', 'Dimensión 4 — Modalidad', ['class' => 'dlbl']);
        $opts4 = ['Individual', 'Grupal/Colaborativa', 'Pares (Peer)'];
        echo html_writer::start_tag('div', ['class' => 'opts', 'style' => 'display:flex; gap:10px;']);
        foreach ($opts4 as $o) {
            $active = ($d4 == $o) ? 'main' : '';
            if ($is_locked) {
                echo html_writer::tag('span', $o, ['class' => "opt $active", 'style' => 'opacity:0.6; cursor:not-allowed;']);
            } else {
                $link_url = new moodle_url($PAGE->url, array_merge($all_params, ['d4' => $o]));
                echo html_writer::link($link_url, $o, ['class' => "opt $active"]);
            }
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');

        // AI Feedback Section & RAG Context
        if ($show_ai && !empty($d2)) {
            // Call Python search for RAG results
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setHeader('Content-Type: application/json');
            $search_payload = json_encode(['course_id' => $id, 'query' => $d2]);
            $search_response = $curl->post('http://python_rag:8000/search', $search_payload);
            $search_data = @json_decode($search_response);
            
            echo html_writer::start_tag('div', ['class' => 'areteia-card t-ia', 'style' => 'border-left: 5px solid #185fa5; background: #f0f7ff; margin-bottom:20px;']);
            echo html_writer::tag('strong', '✨ Análisis de Contexto (RAG)', ['style' => 'display:block; margin-bottom:10px; color:#185fa5;']);
            
            if ($search_data && $search_data->status == 'success' && !empty($search_data->results)) {
                echo html_writer::tag('p', 'He encontrado estos fragmentos relevantes en tus materiales para justificar tu objetivo:', ['style' => 'font-size:12px; margin-bottom:8px; font-weight:bold;']);
                echo '<ul style="font-size:11px; color:#555; list-style:none; padding:0;">';
                foreach (array_slice($search_data->results, 0, 2) as $res) {
                    echo '<li style="background:rgba(255,255,255,0.5); padding:8px; border-radius:5px; margin-bottom:5px; border-left:3px solid #85b7eb;">';
                    echo '<em>"' . s(mb_strimwidth($res->text, 0, 150, "...")) . '"</em><br>';
                    echo '<small style="color:#185fa5;">— Fuente: ' . s($res->filename) . '</small>';
                    echo '</li>';
                }
                echo '</ul>';
            } else {
                echo html_writer::tag('p', 'No se encontraron fragmentos específicos en los PDFs, pero puedo ayudarte con sugerencias generales.', ['style' => 'font-size:12px; color:#666;']);
            }
            echo html_writer::end_tag('div');
        } else if ($show_ai) {
            echo html_writer::start_tag('div', ['class' => 'areteia-card t-ia', 'style' => 'border-left: 5px solid #185fa5; background: #f0f7ff; margin-bottom:20px;']);
            echo html_writer::tag('strong', '✨ Sugerencia de la IA', ['style' => 'display:block; margin-bottom:10px; color:#185fa5;']);
            echo html_writer::tag('div', 'Escribe tu objetivo para que la IA lo analice usando los materiales del curso.', ['style' => 'font-size:12px; color:#666;']);
            echo html_writer::end_tag('div');
        }

        echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
        echo html_writer::link(new moodle_url($PAGE->url, array_merge($all_params, ['step' => 2])), '← Anterior', ['class' => 'areteia-btn']);
        echo html_writer::tag('span', 'Paso 3 de 7', ['class' => 'areteia-ncnt']);
        
        // Validation: Ensure dimensions are not empty before proceeding
        $next_url = new moodle_url($PAGE->url, array_merge($all_params, ['step' => 4]));
        $can_continue = ($d1 && $d2 && $d3 && $d4);
        $btn_class = "areteia-btn areteia-btn-primary " . ($can_continue ? "" : "disabled");
        $btn_text = $can_continue ? "Ver Sugerencias →" : "Completa todas las dimensiones";
        $btn_style = $can_continue ? "" : "opacity:0.5; cursor:not-allowed;";
        
        echo html_writer::link($next_url, $btn_text, ['id' => 'next-step-btn', 'class' => $btn_class, 'style' => $btn_style]);
        echo html_writer::end_tag('div');
    }
    else if ($step == 4) {
        $path = optional_param('path', 'B', PARAM_ALPHA);
        $sel_sug = optional_param('sel_sug', '', PARAM_TEXT);
        
        // Params for step 4 links
        $s3_params = ['id' => $id];

        echo html_writer::tag('span', 'Paso 4 — Sugerencias de la IA', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Instrumentos recomendados', ['class' => 'areteia-stitle']);
        echo html_writer::tag('p', 'La IA propone una batería de instrumentos justificados pedagógicamente basados en tu objetivo.', ['class' => 'areteia-sdesc']);

        // Use $s_sugs_raw from session logic at the top
        $do_gen = optional_param('do_gen', 0, PARAM_INT);
        $sugs = [];

        if ($s_sugs_raw) {
            $sugs = @json_decode($s_sugs_raw, true);
        }

        if ($do_gen && empty($sugs)) {
            // --- FETCH AI SUGGESTIONS ---
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setHeader('Content-Type: application/json');
            $curl->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_CONNECTTIMEOUT' => 20]);
            $gen_payload = json_encode([
                'course_id' => $id,
                'step' => 4,
                'objective' => $d2,
                'summary' => $summary['summary'],
                'dimensions' => "Contenido: $d1, Función: $d3, Modalidad: $d4"
            ]);
            
            $response = $curl->post('http://python_rag:8000/generate', $gen_payload);
            $res_data = @json_decode($response);
            
            if ($res_data && $res_data->status == 'success') {
                $raw_json = $res_data->output;
                $raw_json = preg_replace('/^```json|```$/m', '', $raw_json);
                $sugs = @json_decode(trim($raw_json), true);
                if (empty($sugs)) {
                    $raw_json = trim($raw_json);
                    $sugs = @json_decode($raw_json, true);
                }
                $s_sugs_raw = json_encode($sugs);
                $SESSION->areteia->s_sugs = $s_sugs_raw;
            } else {
                $err_msg = $res_data->message ?? 'Error en el servicio de IA.';
                echo $OUTPUT->notification('Error de IA: ' . $err_msg, 'error');
            }
        }

        if (empty($sugs)) {
            echo html_writer::start_tag('div', ['style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;']);
            echo html_writer::tag('p', 'Haz clic para que la IA analice tu objetivo y proponga instrumentos.', ['style' => 'color:#777; margin-bottom:20px;']);
            $gen_url = new moodle_url($PAGE->url, array_merge($s3_params, ['step' => 4, 'do_gen' => 1]));
            echo html_writer::link($gen_url, '✨ Generar Sugerencias con IA', ['class' => 'areteia-btn areteia-btn-primary', 'style' => 'padding:12px 25px;', 'data-ia' => '1']);
            echo html_writer::end_tag('div');
        }

        echo html_writer::start_tag('div', ['class' => 'sug-grid', 'style' => 'display:flex; flex-direction:column; gap:10px; margin-bottom:20px;']);
        
        foreach ($sugs as $s) {
            $name = $s['name'] ?? 'Sin nombre';
            $is_current = ($instrument === $name);
            $is_sel = '';
            if ($sel_sug === $name) {
                $is_sel = 'is-sel'; // Highlight specifically clicked item
            } elseif (empty($sel_sug) && $is_current) {
                $is_sel = 'is-sel'; // Fallback to current instrument if nothing clicked yet
            }
            
            $sug_url = new moodle_url($PAGE->url, array_merge($s3_params, ['sel_sug' => $name]));
            $card_attrs = [
                'href' => $sug_url,
                'class' => "areteia-card sug-card $is_sel",
                'style' => 'padding:15px; border-radius:10px; text-decoration:none; color:inherit; position:relative;'
            ];
            
            // Add warning if clicking a DIFFERENT option while downstream data exists
            if (!empty($SESSION->areteia->inst_content) && $instrument !== '' && $name !== $instrument) {
                $card_attrs['data-confirm'] = "Si confirmas un nuevo instrumento, se borrará tu diseño y rúbrica actuales. ¿Seleccionar para explorar?";
            }
            
            echo html_writer::start_tag('a', $card_attrs);
            
            if ($is_current) {
                echo html_writer::tag('span', 'Instrumento en uso', ['style' => 'position:absolute; top:10px; right:10px; font-size:10px; background:#e6f4ea; color:#1e8e3e; padding:2px 8px; border-radius:10px; border:1px solid #1e8e3e;']);
            }

            echo html_writer::start_tag('div', ['style' => 'display:flex; justify-content:space-between; margin-bottom:5px;']);
            echo html_writer::tag('strong', $name, ['style' => 'color:#185fa5;']);
            echo html_writer::end_tag('div');
            
            echo html_writer::tag('div', '<strong>Fundamentación:</strong> ' . ($s['why'] ?? 'N/A'), ['style' => 'font-size:12px; color:#555; margin-bottom:3px;']);
            echo html_writer::tag('div', '<em>Limitación:</em> ' . ($s['lim'] ?? 'N/A'), ['style' => 'font-size:11px; color:#999;']);
            echo html_writer::end_tag('a');
        }
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
        echo html_writer::link(new moodle_url($PAGE->url, array_merge($s3_params, ['step' => 3])), '← Anterior', ['class' => 'areteia-btn']);
        echo html_writer::tag('span', 'Paso 4 de 7', ['class' => 'areteia-ncnt']);
        
        $effective_sel = $sel_sug ?: $instrument;
        if ($effective_sel) {
            $btn_attrs = ['class' => 'areteia-btn areteia-btn-primary'];
            $btn_label = ($effective_sel === $instrument) ? 'Continuar →' : 'Confirmar Instrumento →';
            // Ultimate warning before data destruction
            if (!empty($SESSION->areteia->inst_content) && $instrument !== '' && $effective_sel !== $instrument) {
                $btn_attrs['data-confirm'] = '¡Atención! Confirmar este instrumento eliminará permanentemente la evaluación y rúbrica que tenías generada. ¿Estás seguro?';
            }
            echo html_writer::link(new moodle_url($PAGE->url, array_merge($s3_params, ['step' => 5, 'instrument' => $effective_sel])), $btn_label, $btn_attrs);
        } else {
            echo html_writer::tag('span', 'Selecciona un instrumento para continuar', ['class' => 'areteia-btn disabled', 'style' => 'opacity:0.5; cursor:not-allowed;']);
        }
        echo html_writer::end_tag('div');
    }
    else if ($step == 5) {
        // use $instrument and $inst_content from session logic at the top
        $do_gen = optional_param('do_gen', 0, PARAM_INT);
        $s5_params = ['id' => $id];

        if ($do_gen && empty($inst_content)) {
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setHeader('Content-Type: application/json');
            $curl->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_CONNECTTIMEOUT' => 20]);
            $gen_payload = json_encode([
                'course_id' => $id,
                'step' => 5,
                'objective' => $d2,
                'chosen_instrument' => $instrument
            ]);
            $response = $curl->post('http://python_rag:8000/generate', $gen_payload);
            $res_data = @json_decode($response);
            if ($res_data && $res_data->status == 'success') {
                $inst_content = $res_data->output;
                $SESSION->areteia->inst_content = $inst_content;
            } else {
                $inst_content = "Error al generar el contenido de la IA. Por favor, reintenta.";
            }
        }

        echo html_writer::tag('span', 'Paso 5 — Diseño del instrumento', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Construcción pedagógica del instrumento', ['class' => 'areteia-stitle']);
        
        echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'background:#f9f9f9; padding:15px; border:1px dashed #ccc; margin-bottom:15px;']);
        echo html_writer::tag('strong', "Instrumento: $instrument", ['style' => 'display:block; color:#185fa5;']);
        echo html_writer::tag('small', "Objetivo: $d2", ['style' => 'color:#666;']);
        echo html_writer::end_tag('div');

        if (empty($inst_content)) {
            echo html_writer::start_tag('div', ['style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;']);
            echo html_writer::tag('p', 'La IA está lista para redactar las consignas e ítems de tu evaluación.', ['style' => 'color:#777; margin-bottom:20px;']);
            $gen_url = new moodle_url($PAGE->url, array_merge($s5_params, ['step' => 5, 'do_gen' => 1]));
            echo html_writer::link($gen_url, '✨ Generar Diseño con IA', ['class' => 'areteia-btn areteia-btn-primary', 'style' => 'padding:12px 25px;']);
            echo html_writer::end_tag('div');
        } else {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'margin-bottom:20px; line-height:1.6; position:relative;']);
            echo html_writer::tag('p', '<strong>Propuesta de la IA:</strong>', ['style' => 'margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;']);
            
            // Format Markdown to HTML for a premium look
            $formatted_inst = format_text($inst_content, FORMAT_MARKDOWN, ['context' => $context]);
            echo html_writer::tag('div', $formatted_inst, ['class' => 'areteia-markdown-content']);
            
            echo html_writer::start_tag('div', ['class' => 'areteia-nav', 'style' => 'margin-top:20px; border-top:1px solid #eee; padding-top:15px;']);
            echo html_writer::link(new moodle_url($PAGE->url, array_merge($s5_params, ['step' => 4])), '← Volver', ['class' => 'areteia-btn']);
            
            // Add Regenerate button (clears session content first)
            $regen_url = new moodle_url($PAGE->url, array_merge($s5_params, ['step' => 5, 'do_gen' => 1, 'inst_content' => ' ']));
            echo html_writer::link($regen_url, 'Regenerar ✨', ['class' => 'areteia-btn', 'style' => 'margin-left:auto; margin-right:10px;']);
            
            $next_url = new moodle_url($PAGE->url, array_merge($s5_params, ['step' => 6]));
            echo html_writer::link($next_url, 'Continuar a Rúbrica →', ['class' => 'areteia-btn areteia-btn-primary']);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        }
    }
    else if ($step == 6) {
        // use $inst_content, $rubric_content, $d2 etc from session logic at the top
        $do_gen = optional_param('do_gen', 0, PARAM_INT);
        $s6_params = ['id' => $id];

        if ($do_gen && empty($rubric_content)) {
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setHeader('Content-Type: application/json');
            $curl->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_CONNECTTIMEOUT' => 20]);
            $gen_payload = json_encode([
                'course_id' => $id,
                'step' => 6,
                'objective' => $d2,
                'instrument_content' => $inst_content
            ]);
            $response = $curl->post('http://python_rag:8000/generate', $gen_payload);
            $res_data = @json_decode($response);
            if ($res_data && $res_data->status == 'success') {
                $rubric_content = $res_data->output;
                $SESSION->areteia->rubric_content = $rubric_content;
            } else {
                $rubric_content = "Error al generar la rúbrica.";
            }
        }

        echo html_writer::tag('span', 'Paso 6 — Instrumento de calificación', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Definición de criterios de evaluación', ['class' => 'areteia-stitle']);
        
        if (empty($rubric_content)) {
            echo html_writer::start_tag('div', ['style' => 'text-align:center; padding:40px; border:2px dashed #eee; border-radius:15px;']);
            echo html_writer::tag('p', 'AretéIA puede crear una rúbrica personalizada para tu instrumento.', ['style' => 'color:#777; margin-bottom:20px;']);
            $gen_url = new moodle_url($PAGE->url, array_merge($s6_params, ['step' => 6, 'do_gen' => 1]));
            echo html_writer::link($gen_url, '✨ Generar Rúbrica con IA', ['class' => 'areteia-btn areteia-btn-primary']);
            echo html_writer::end_tag('div');
        } else {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'margin-bottom:20px;']);
            echo html_writer::tag('p', '<strong>Rúbrica Propuesta:</strong>', ['style' => 'margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;']);
            
            $formatted_rub = format_text($rubric_content, FORMAT_MARKDOWN, ['context' => $context]);
            echo html_writer::tag('div', $formatted_rub, ['class' => 'areteia-markdown-content']);
            
            echo html_writer::start_tag('div', ['class' => 'areteia-nav', 'style' => 'margin-top:20px; border-top:1px solid #eee; padding-top:15px;']);
            echo html_writer::link(new moodle_url($PAGE->url, array_merge($s6_params, ['step' => 5])), '← Volver', ['class' => 'areteia-btn']);
            
            // Add Regenerate button
            $regen_url = new moodle_url($PAGE->url, array_merge($s6_params, ['step' => 6, 'do_gen' => 1, 'rubric_content' => ' ']));
            echo html_writer::link($regen_url, 'Regenerar ✨', ['class' => 'areteia-btn', 'style' => 'margin-left:auto; margin-right:10px;']);
            
            $next_url = new moodle_url($PAGE->url, array_merge($s6_params, ['step' => 7]));
            echo html_writer::link($next_url, 'Finalizar y Revisar →', ['class' => 'areteia-btn areteia-btn-primary']);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        }
    }
    else if ($step == 7) {
        // uses session content for $inst_content, $rubric_content etc.

        echo html_writer::tag('span', 'Paso 7 — Resultado final', ['class' => 'areteia-tag t-ia']);
        echo html_writer::tag('p', 'Instrumento de evaluación finalizado', ['class' => 'areteia-stitle']);
        
        if ($exported == 1) {
            echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'border-left: 5px solid #28a745; background: #f4fff4; margin-bottom:20px;']);
            echo html_writer::tag('strong', '🚀 ¡Actividad publicada en Moodle!', ['style' => 'color:#28a745; display:block; margin-bottom:5px;']);
            echo html_writer::tag('p', 'La tarea ha sido creada exitosamente.', ['style' => 'font-size:12px; margin-bottom:10px;']);
            echo html_writer::link(new moodle_url('/mod/assign/view.php', ['id' => $cmid]), 'Ir a la actividad en Moodle ↗', ['class' => 'areteia-btn areteia-btn-primary external', 'target' => '_blank']);
            echo html_writer::end_tag('div');
        }

        echo html_writer::start_tag('div', ['class' => 'areteia-card', 'style' => 'margin-bottom:20px;']);
        echo html_writer::tag('p', "<strong>Vista previa final: $instrument</strong>", ['style' => 'color:#185fa5; font-size:1.1em; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;']);
        
        echo html_writer::tag('div', '<strong>Consignas:</strong>', ['style' => 'font-size:13px; font-weight:bold; margin-bottom:5px;']);
        $preview_inst = mb_strimwidth($inst_content, 0, 500, "...");
        echo html_writer::tag('div', format_text($preview_inst, FORMAT_MARKDOWN, ['context' => $context]), ['class' => 'areteia-markdown-content', 'style' => 'font-size:12px; background:#fcfcfc; padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid #eee;']);
        
        echo html_writer::tag('div', '<strong>Rúbrica:</strong>', ['style' => 'font-size:13px; font-weight:bold; margin-bottom:5px;']);
        $preview_rub = mb_strimwidth($rubric_content, 0, 500, "...");
        echo html_writer::tag('div', format_text($preview_rub, FORMAT_MARKDOWN, ['context' => $context]), ['class' => 'areteia-markdown-content', 'style' => 'font-size:12px; background:#fcfcfc; padding:10px; border-radius:8px; border:1px solid #eee;']);
        echo html_writer::end_tag('div');

        echo html_writer::start_tag('div', ['class' => 'areteia-nav']);
        echo html_writer::link(new moodle_url($PAGE->url, ['step' => 6]), '← Anterior', ['class' => 'areteia-btn']);
        echo html_writer::tag('span', 'Paso 7 de 7', ['class' => 'areteia-ncnt']);
        
        $export_url = new moodle_url($PAGE->url, ['action' => 'export']);
        echo html_writer::link($export_url, '🚀 Publicar en Moodle', ['class' => 'areteia-btn areteia-btn-primary']);
        echo html_writer::end_tag('div');
    }

    echo html_writer::end_tag('div'); // End Card
    echo html_writer::end_tag('div'); // areteia-inner
    
    if (!$is_ajax) {
        echo html_writer::end_tag('div'); // areteia-wrap
        echo $OUTPUT->footer();
    } else {
        echo ob_get_clean();
        die();
    }

} catch (Exception $e) {
    if ($is_ajax) { 
        ob_end_clean(); 
        echo "Error: " . $e->getMessage(); 
        die(); 
    }
    echo $OUTPUT->notification('Error: ' . $e->getMessage(), 'error');
    echo $OUTPUT->footer();
}
