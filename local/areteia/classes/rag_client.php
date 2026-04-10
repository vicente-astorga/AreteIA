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
     * @return object|null  { status, chunks, ... }
     */
    public static function ingest(int $course_id): ?object {
        $response = self::post('/ingest', json_encode(['course_id' => $course_id]), 600, 30);
        return @json_decode($response);
    }

    /**
     * GET /status/{course_id} — check embedding existence.
     *
     * @param int $course_id
     * @return array  ['data' => ?object, 'raw' => string|false]
     */
    public static function status(int $course_id): array {
        $curl = new \curl(['ignoresecurity' => true]);
        $raw  = $curl->get(self::BASE . '/status/' . $course_id);
        return [
            'data' => @json_decode($raw),
            'raw'  => $raw,
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
     * @param array $data  Arbitrary payload forwarded to the Python service
     * @return object|null  { status, output }
     */
    public static function generate(array $data): ?object {
        $response = self::post('/generate', json_encode($data), 120, 20);
        return @json_decode($response);
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
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader('Content-Type: application/json');
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => $connect_timeout,
        ]);
        return $curl->post(self::BASE . $endpoint, $payload);
    }
}
