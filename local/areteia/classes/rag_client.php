<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP client for the Python RAG micro-service (python_rag:8000).
 *
 * Wraps all cURL calls so that individual steps never instantiate \curl directly.
 * Every method returns the decoded JSON object or null on failure.
 */
class rag_client {

    /** @var string Base URL of the Python service. */
    private const BASE = 'http://python_rag:8000';

    // ------------------------------------------------------------------
    // Public API (one method per endpoint)
    // ------------------------------------------------------------------

    /**
     * POST /sync — send course metadata to the Python service.
     *
     * @param array $summary  Course summary from data_provider::get_course_summary()
     */
    public static function sync(array $summary): void {
        $payload = json_encode(['course' => $summary, 'files' => []]);
        self::post('/sync', $payload);
    }

    /**
     * POST /ingest — trigger chunking + embedding build.
     * Returns the decoded response or null on network failure.
     *
     * @param int $course_id
     * @param array $selected_files (Optional) list of selected file paths
     * @return object|null  { status, chunks, ... }
     */
    public static function ingest(int $course_id, array $selected_files = []): ?object {
        $response = self::post('/ingest', json_encode([
            'course_id' => $course_id,
            'selected_files' => $selected_files
        ]), 600, 30);
        return @json_decode($response);
    }
    
    /**
     * DELETE /ingest/{id} — delete existing embeddings for a course.
     *
     * @param int $course_id
     */
    public static function delete(int $course_id): void {
        $ch = curl_init(self::BASE . '/ingest/' . $course_id);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * GET /status/{course_id} — check embedding existence.
     *
     * @param int $course_id
     * @return array  ['data' => ?object, 'raw' => string|false]
     */
    public static function status(int $course_id): array {
        $ch = curl_init(self::BASE . '/status/' . $course_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($ch);
        curl_close($ch);
        
        return [
            'data' => @json_decode($raw),
            'raw'  => $raw ?: false,
        ];
    }

    /**
     * POST /search — semantic search in course embeddings.
     *
     * @param int    $course_id
     * @param string $query
     * @return object|null  { status, results[] }
     */
    public static function search(int $course_id, string $query): ?object {
        $payload  = json_encode(['course_id' => $course_id, 'query' => $query]);
        $response = self::post('/search', $payload);
        return @json_decode($response);
    }

    /**
     * POST /generate — LLM generation (steps 4, 5, 6).
     *
     * @param array $data
     * @return object|null
     */
    public static function generate(array $data): ?object {
        $response = self::post('/generate', json_encode($data), 600, 30);
        return @json_decode($response);
    }

    /**
     * POST /preview — preview LLM prompts.
     *
     * @param array $data
     * @return object|null  { status, system_prompt, user_prompt }
     */
    public static function preview_prompt(array $data): ?object {
        $response = self::post('/preview', json_encode($data), 60, 20);
        return @json_decode($response);
    }

    /**
     * GET /instruments — get the full list of instruments from the master document.
     *
     * @return object|null  { status, instruments: [{name, definition}] }
     */
    public static function get_instruments(): ?object {
        $ch = curl_init(self::BASE . '/instruments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($ch);
        curl_close($ch);
        
        return @json_decode($raw);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Execute a POST request against the Python service.
     *
     * @param string $endpoint         e.g. "/ingest"
     * @param string $payload          JSON body
     * @param int    $timeout          CURLOPT_TIMEOUT
     * @param int    $connect_timeout  CURLOPT_CONNECTTIMEOUT
     * @return string  Raw response body
     */
    private static function post(
        string $endpoint,
        string $payload,
        int $timeout = 60,
        int $connect_timeout = 20
    ): string {
        $ch = curl_init(self::BASE . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            // Log native curl error for debugging
            error_log("AreteIA RAG CURL Error ($endpoint): " . $error);
            return '';
        }
        
        return $response;
    }
}
