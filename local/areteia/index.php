<?php
/**
 * AreteIA — Entry point for the pedagogical workflow.
 *
 * This file is intentionally slim (~80 lines). All logic lives in:
 *   classes/session_manager.php  — state persistence + cascading invalidation
 *   classes/lock_manager.php     — reusable step-locking UI pattern
 *   classes/rag_client.php       — HTTP client for the Python RAG service
 *   classes/action_handler.php   — sync / ingest / export actions
 *   classes/step_renderer.php    — progress bar + card wrapper + dispatch
 *   classes/steps/step0..7.php   — individual step renderers
 *   areteia.js                   — client-side AJAX navigation + reactivity
 *
 * @package    local_areteia
 * @copyright  2026 Vicente Astorga
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id     = optional_param('id', 0, PARAM_INT);
$step   = optional_param('step', 0, PARAM_INT);
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
$PAGE->set_title('AreteIA — Flujo docente');
$PAGE->set_heading('AreteIA — Flujo módulo docente');
$PAGE->set_pagelayout('report');

// ------------------------------------------------------------------
// AJAX detection — AJAX requests return only inner HTML, no header/footer
// ------------------------------------------------------------------
$is_ajax = optional_param('ajax', 0, PARAM_INT);

if ($is_ajax) {
    ob_start();
} else {
    echo $OUTPUT->header();
    echo '<style>' . file_get_contents(__DIR__ . '/styles.css') . '</style>';
    echo '<script>' . file_get_contents(__DIR__ . '/areteia.js') . '</script>';
}

// ------------------------------------------------------------------
// Action handling (sync / ingest redirect before any rendering)
// ------------------------------------------------------------------
if ($action === 'sync' || $action === 'ingest') {
    \local_areteia\action_handler::handle($action, $id, $PAGE->url, $is_ajax);
    // ^ never returns (redirect + die)
}

// ------------------------------------------------------------------
// Main rendering inside try/catch for stability
// ------------------------------------------------------------------
try {
    // Session state management
    \local_areteia\session_manager::init();
    \local_areteia\session_manager::sync_from_request();

    // Export action (needs session to be initialized first)
    if ($action === 'export') {
        \local_areteia\action_handler::handle($action, $id, $PAGE->url, $is_ajax);
        // ^ never returns
    }

    // Fetch course data
    $summary = \local_areteia\data_provider::get_course_summary($id);
    $files   = \local_areteia\data_provider::get_course_files($id);

    // Outer wrapper (only for non-AJAX — AJAX replaces inner content)
    if (!$is_ajax) {
        echo html_writer::start_tag('div', ['class' => 'areteia-wrap', 'id' => 'areteia-main']);
    }

    // Inner content
    echo html_writer::start_tag('div', ['class' => 'areteia-inner']);
    \local_areteia\step_renderer::render($step, $id, $summary, $files, $context, $is_ajax);
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
        echo 'Error: ' . $e->getMessage();
        die();
    }
    echo $OUTPUT->notification('Error: ' . $e->getMessage(), 'error');
    echo $OUTPUT->footer();
}
