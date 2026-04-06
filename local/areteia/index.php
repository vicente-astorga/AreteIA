<?php
/**
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);

require_login();

$context = context_system::instance();
if ($id) {
    $context = context_course::instance($id);
}

$PAGE->set_url(new moodle_url('/local/areteia/index.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_areteia'));
$PAGE->set_heading(get_string('pluginname', 'local_areteia'));

echo $OUTPUT->header();

if (!$id) {
    echo $OUTPUT->notification('Please provide a Course ID (?id=XX)', 'error');
    echo $OUTPUT->footer();
    die();
}

try {
    $summary = \local_areteia\data_provider::get_course_summary($id);
    $files = \local_areteia\data_provider::get_course_files($id);

    echo $OUTPUT->heading(get_string('coursereport', 'local_areteia') . ': ' . $summary['fullname']);
    
    // Sync Button
    echo html_writer::start_tag('div', ['class' => 'areteia-actions mb-4']);
    $syncurl = new moodle_url($PAGE->url, ['action' => 'sync']);
    echo html_writer::link($syncurl, 'Consumir Data (Sincronizar con Python/IA)', ['class' => 'btn btn-primary']);
    $ingesturl = new moodle_url($PAGE->url, ['action' => 'ingest']);
    echo html_writer::link($ingesturl, 'Construir Embeddings', ['class' => 'btn btn-secondary ml-2']);
    echo html_writer::end_tag('div');

    if (optional_param('action', '', PARAM_ALPHA) === 'sync') {
        echo $OUTPUT->notification('Extrayendo archivos y sincronizando con el servicio de IA...', 'info');
        
        // El segundo parámetro en true fuerza la extracción de los archivos al volumen compartido
        $files_for_ai = \local_areteia\data_provider::get_course_files($id, true);
        
        $payload = json_encode([
            'course' => $summary,
            'files' => $files_for_ai
        ]); 
        
        // En Moodle, curl::ignoresecurity debe pasarse en el constructor como array de opciones
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader('Content-Type: application/json');
        
        $options = [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ];
        
        $response = $curl->post('http://python_rag:8000/sync', $payload, $options);
        
        if ($curl->get_errno()) {
            echo $OUTPUT->notification('Error de conexión con el servicio de IA (Python): ' . $curl->error, 'error');
        } else {
            $info = $curl->get_info();
            $res = json_decode($response);
            if (isset($res->status) && $res->status === 'success') {
                echo $OUTPUT->notification('¡Éxito! El servicio en Python respondió: ' . $res->message, 'success');
            } else {
                echo $OUTPUT->notification('Respuesta inesperada de Python (HTTP ' . $info['http_code'] . ').', 'warning');
                echo html_writer::tag('pre', 'Response: ' . s($response));
                if (empty($response)) {
                    echo $OUTPUT->notification('La respuesta está vacía. Verifica los logs del contenedor areteia_ai.', 'error');
                }
            }
        }
    }
    if (optional_param('action', '', PARAM_ALPHA) === 'ingest') {
        echo $OUTPUT->notification('Construyendo embeddings del curso...', 'info');

        // IMPORTANT: we assume files are already synced to disk
        $files_for_ai = \local_areteia\data_provider::get_course_files($id, false);

        $payload = json_encode([
            'course_id' => $id
        ]);

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader('Content-Type: application/json');

        $options = [
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ];

        $response = $curl->post('http://python_rag:8000/ingest', $payload, $options);

        if ($curl->get_errno()) {
            echo $OUTPUT->notification('Error conectando con Python: ' . $curl->error, 'error');
        } else {
            $res = json_decode($response);

            if (isset($res->status) && $res->status === 'success') {
                echo $OUTPUT->notification('Embeddings creados correctamente: ' . $res->message, 'success');
            } else {
                echo $OUTPUT->notification('Error en la creación de embeddings', 'error');
                echo html_writer::tag('pre', s($response));
            }
        }
    }
    echo html_writer::start_tag('div', ['class' => 'areteia-summary card p-3 mb-4']);
    echo html_writer::tag('p', '<strong>Summary:</strong> ' . ($summary['summary'] ?: 'No summary available'));
    echo html_writer::tag('p', '<strong>Files detected:</strong> ' . count($files));
    echo html_writer::end_tag('div');

    // Sections and files
    echo html_writer::start_tag('div', ['class' => 'row']);
    echo html_writer::start_tag('div', ['class' => 'col-md-8']);
    foreach ($summary['sections'] as $section) {
        echo html_writer::start_tag('div', ['class' => 'section mb-3 border-bottom pb-2']);
        echo html_writer::tag('h5', $section['name']);
        if (!empty($section['activities'])) {
            echo html_writer::start_tag('ul', ['class' => 'list-unstyled ml-3']);
            foreach ($section['activities'] as $activity) {
                echo html_writer::tag('li', '• [' . $activity['type'] . '] ' . $activity['name']);
            }
            echo html_writer::end_tag('ul');
        }
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'col-md-4']);
    echo html_writer::tag('h5', 'Archivos para RAG');
    echo html_writer::start_tag('ul', ['class' => 'list-group']);
    foreach ($files as $file) {
        echo html_writer::tag('li', $file['filename'] . ' (' . display_size($file['size']) . ')', ['class' => 'list-group-item d-flex justify-content-between align-items-center']);
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    echo html_writer::tag('pre', json_encode(['course' => $summary, 'files' => $files], JSON_PRETTY_PRINT), ['class' => 'bg-light p-3 border mt-4', 'style' => 'max-height: 300px; overflow: auto;']);

} catch (Exception $e) {
    echo $OUTPUT->notification('Error: ' . $e->getMessage(), 'error');
}

echo $OUTPUT->footer();
