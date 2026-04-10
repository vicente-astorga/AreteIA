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

        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }

    /**
     * Trigger embedding ingestion and redirect to Step 1 with result status.
     */
    private static function handle_ingest(int $course_id, \moodle_url $base_url, bool $is_ajax): void {
        \core_php_time_limit::raise(600);

        $res_data = rag_client::ingest($course_id);

        // Determine ingestion state: 1=success, 2=empty, -1=error
        if ($res_data && $res_data->status == 'success') {
            $state = ($res_data->chunks > 0) ? 1 : 2;
        } else {
            $state = -1;
        }
        if ($res_data && $res_data->chunks == 0) {
            $state = 2;
        }

        $redir = new \moodle_url($base_url, ['step' => 1, 'use_moodle' => 1, 'ingested' => $state]);
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

        $redir = new \moodle_url($base_url, [
            'step'     => 7,
            'exported' => 1,
            'cmid'     => $moduleinfo->coursemodule,
        ]);
        if ($is_ajax) {
            $redir->param('ajax', 1);
        }
        redirect($redir);
    }
}
