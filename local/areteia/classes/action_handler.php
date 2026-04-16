<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles the three server-side actions that redirect: sync, ingest, export.
 *
 * Each action processes data, then issues a Moodle redirect().
 * After calling handle(), the script never continues (redirect dies).
 */
class action_handler {

    /**
     * Dispatch the given action. Returns false if the action is not recognized
     * (so the caller can continue rendering). On recognized actions, this method
     * never returns — it calls redirect() which dies.
     *
     * @param string     $action    One of: 'sync', 'ingest', 'export'
     * @param int        $course_id
     * @param \moodle_url $base_url  The current $PAGE->url
     * @param bool       $is_ajax
     * @return bool  false if action not handled
     */
    public static function handle(string $action, int $course_id, \moodle_url $base_url, bool $is_ajax): bool {
        switch ($action) {
            case 'sync':
                self::handle_sync($course_id, $base_url, $is_ajax);
                return true; // never reached

            case 'ingest':
                self::handle_ingest($course_id, $base_url, $is_ajax);
                return true;

            case 'export':
                self::handle_export($course_id, $base_url, $is_ajax);
                return true;

            case 'delete_rag':
                self::handle_delete_rag($course_id, $base_url, $is_ajax);
                return true;

            case 'preview':
                self::handle_preview($course_id);
                return true;

            default:
                return false;
        }
    }

    // ------------------------------------------------------------------
    // Individual action handlers
    // ------------------------------------------------------------------

    /**
     * Sync course files to the Python service and redirect to Step 1.
     */
    private static function handle_sync(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        // Release session lock so nav/AJAX works while sync runs.
        if (method_exists('\core\session\manager', 'write_close')) {
            \core\session\manager::write_close();
        }

        // Extract files to sync dir + get summary
        data_provider::get_course_files($course_id, true);
        $summary = data_provider::get_course_summary($course_id);

        // POST /sync
        rag_client::sync($summary);

        // Always return to the Library tab (Step 1)
        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'action' => 'lib']);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Trigger embedding ingestion and redirect to Step 1 with result status.
     */
    private static function handle_ingest(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        global $CFG;
        \core_php_time_limit::raise(600);

        // 1. Force a clean physical extraction of ALL allowed course files to disk.
        data_provider::get_course_files($course_id, true);

        // 2. Filter: only keep user-selected files on disk before calling Python.
        $selected_files_raw = optional_param('selected_files', '', PARAM_RAW);
        error_log("[AreteIA] handle_ingest course={$course_id} selected_files_raw=" . substr($selected_files_raw, 0, 300));

        if (!empty($selected_files_raw)) {
            $selected_files = json_decode($selected_files_raw, true);

            if (is_array($selected_files) && count($selected_files) > 0) {
                // Normalize: trim whitespace and unify directory separators
                $selected_files = array_map(function($p) {
                    return str_replace('\\', '/', trim($p));
                }, $selected_files);

                error_log("[AreteIA] Selected files (" . count($selected_files) . "): " . implode(', ', $selected_files));

                $base_sync_dir = rtrim($CFG->dataroot . '/areteia_sync/course_' . $course_id, '/');

                if (file_exists($base_sync_dir)) {
                    $directory = new \RecursiveDirectoryIterator($base_sync_dir, \RecursiveDirectoryIterator::SKIP_DOTS);
                    $iterator  = new \RecursiveIteratorIterator($directory);

                    foreach ($iterator as $file) {
                        if ($file->isDir()) continue;

                        // Relative path from course sync dir, normalized to forward slashes
                        $relative_path = str_replace('\\', '/', substr($file->getPathname(), strlen($base_sync_dir) + 1));

                        if (!in_array($relative_path, $selected_files)) {
                            error_log("[AreteIA] Deleting unselected: {$relative_path}");
                            @unlink($file->getPathname());
                        } else {
                            error_log("[AreteIA] Keeping selected: {$relative_path}");
                        }
                    }
                }
            } else {
                error_log("[AreteIA] WARNING: selected_files JSON decoded to empty/non-array. Raw: " . $selected_files_raw);
            }
        } else {
            error_log("[AreteIA] WARNING: selected_files is empty — ingesting ALL files.");
        }

        $res_data = rag_client::ingest($course_id, $selected_files ?? []);

        // Determine ingestion state: 1=success, 2=empty, 3=processing, -1=error
        if ($res_data && $res_data->status == 'success') {
            $state = ($res_data->chunks > 0) ? 1 : 2;
        } else if ($res_data && $res_data->status == 'started') {
            $state = 3;
        } else {
            $state = -1;
        }
        if ($res_data && isset($res_data->chunks) && $res_data->chunks === 0) {
            $state = 2;
        }

        // Always return to the Library tab (Step 1)
        // Set deleted=1 param so UI can potentially show a small flash message (optional)
        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'ingested' => $state, 'deleted' => 1, 'action' => 'lib']);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Delete the existing RAG embedding for a course.
     */
    private static function handle_delete_rag(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        rag_client::delete($course_id);
        data_provider::delete_sync_dir($course_id);
        
        $redir = new \moodle_url($base_url, ['step' => 0, 'action' => 'lib', 'force_step' => 0]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Export the generated instrument + rubric as a Moodle Assign activity.
     */
    private static function handle_export(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        $inst_name = session_manager::get('instrument', '') . ' - AreteIA';
        $final_desc = session_manager::get('inst_content', '');

        $rubric = session_manager::get('rubric_content', '');
        if (!empty($rubric)) {
            $final_desc .= "\n\n### Rúbrica\n" . $rubric;
        }

        if (!$inst_name) {
            $inst_name = 'Evaluación AreteIA';
        }
        if (!$final_desc) {
            $final_desc = 'Instrumento generado por AreteIA.';
        }

        $moduleinfo = data_provider::create_assign_activity($course_id, $inst_name, $final_desc);

        $action = optional_param('action', 'lib', PARAM_ALPHA);
        $redir = new \moodle_url($base_url, [
            'step'     => 7,
            'exported' => 1,
            'cmid'     => $moduleinfo->coursemodule,
            'action'   => $action,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Fetch LLM prompt preview and return as JSON.
     */
    private static function handle_preview(int $course_id): void {
        header('Content-Type: application/json');
        
        $step = optional_param('p_step', 4, PARAM_INT);
        $feedback = optional_param('feedback', '', PARAM_TEXT);
        
        $summary = data_provider::get_course_summary($course_id);
        
        $data = [
            'course_id'          => $course_id,
            'step'               => $step,
            'objective'          => session_manager::get('d2', ''),
            'objective_json'     => session_manager::get('d2_json', ''),
            'dimensions'         => "Contenido: " . session_manager::get('d1', '') . 
                                   ", Función: " . session_manager::get('d3', '') . 
                                   ", Modalidad: " . session_manager::get('d4', ''),
            'd1_content'         => session_manager::get('d1', ''),
            'd3_function'        => session_manager::get('d3', ''),
            'd4_modality'        => session_manager::get('d4', ''),
            'feedback'           => $feedback,
            'chosen_instrument'  => session_manager::get('instrument', ''),
            'instrument_content' => session_manager::get('inst_content', ''),
        ];

        $res = rag_client::preview_prompt($data);
        echo json_encode($res ?: ['status' => 'error', 'message' => 'Servicio de IA no disponible']);
        die();
    }
}
