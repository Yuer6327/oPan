<?php

declare(strict_types=1);

/**
 * VirusTotal API v3 Client
 *
 * Submits URLs for scanning and polls for analysis results.
 * Includes an in-memory rate limiter (best-effort for serverless).
 */
final class VirusTotalClient
{
    private const BASE = 'https://www.virustotal.com/api/v3';
    private string $apiKey;

    // Rate limiter: token bucket (in-memory, per cold-start)
    private static array  $minuteTimestamps = [];
    private static array  $dayTimestamps    = [];
    private const MINUTE_LIMIT = 4;
    private const DAY_LIMIT    = 500;

    public function __construct()
    {
        $this->apiKey = env('VT_API_KEY');
    }

    // ── Public API ──────────────────────────────────────────────────────

    /**
     * Submit a URL for VirusTotal scanning.
     * Returns the analysis ID and status endpoint.
     */
    public function submitUrl(string $url): array
    {
        $this->enforceRateLimit();

        $res = $this->post('/urls', http_build_query(['url' => $url]));

        if ($res['status'] === 429) {
            throw new RuntimeException('VirusTotal rate limit exceeded. Please try again later.', 429);
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                "VirusTotal submit failed (HTTP {$res['status']}): " .
                ($res['raw'] ?? json_encode($res['body']))
            );
        }

        $data = $res['body']['data'] ?? null;
        if (!$data || empty($data['id'])) {
            throw new RuntimeException('VirusTotal submit: unexpected response format');
        }

        return [
            'analysis_id' => $data['id'],
            'status_url'  => $data['links']['self'] ?? self::BASE . "/analyses/{$data['id']}",
        ];
    }

    /**
     * Check the status of an analysis.
     * Returns { status, stats?, report_url?, is_complete }
     */
    public function getAnalysisStatus(string $analysisId): array
    {
        $this->enforceRateLimit();

        $res = $this->get("/analyses/{$analysisId}");

        if ($res['status'] === 429) {
            throw new RuntimeException('VirusTotal rate limit exceeded.', 429);
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                "VirusTotal analysis check failed (HTTP {$res['status']}): " .
                ($res['raw'] ?? json_encode($res['body']))
            );
        }

        $attrs = $res['body']['data']['attributes'] ?? [];
        $status = $attrs['status'] ?? 'unknown';
        $isComplete = $status === 'completed';

        $result = [
            'status'      => $status,
            'is_complete' => $isComplete,
            'stats'       => null,
            'report_url'  => null,
        ];

        if ($isComplete) {
            $stats = $attrs['stats'] ?? [];
            $malicious  = ($stats['malicious'] ?? 0) + ($stats['suspicious'] ?? 0);
            $total      = array_sum($stats);
            $undetected = ($stats['undetected'] ?? 0) + ($stats['harmless'] ?? 0);

            $result['stats'] = [
                'malicious'  => $malicious,
                'undetected' => $undetected,
                'total'      => $total,
                'raw'        => $stats,
            ];

            // Build the web UI permalink from the analysis meta
            // The submitted URL is embedded in the analysis meta
            $submittedUrl = $attrs['meta']['url_info']['url']
                ?? $attrs['meta']['url']
                ?? null;

            if ($submittedUrl) {
                $urlId = $this->base64UrlEncode($submittedUrl);
                $result['report_url'] = "https://www.virustotal.com/gui/url/{$urlId}";
            }
        }

        return $result;
    }

    /**
     * Get a cached report for a URL (if previously scanned).
     * Returns null if no cached report exists.
     */
    public function getReport(string $url): ?array
    {
        $urlId = $this->base64UrlEncode($url);
        $this->enforceRateLimit();

        $res = $this->get("/urls/{$urlId}");

        if ($res['status'] === 404) {
            return null;
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return null;
        }

        $attrs = $res['body']['data']['attributes'] ?? [];
        $stats = $attrs['last_analysis_stats'] ?? [];

        if (empty($stats)) return null;

        $malicious  = ($stats['malicious'] ?? 0) + ($stats['suspicious'] ?? 0);
        $total      = array_sum($stats);
        $undetected = ($stats['undetected'] ?? 0) + ($stats['harmless'] ?? 0);

        return [
            'malicious'  => $malicious,
            'undetected' => $undetected,
            'total'      => $total,
            'raw'        => $stats,
            'report_url' => "https://www.virustotal.com/gui/url/{$urlId}",
        ];
    }

    // ── Rate limiter ────────────────────────────────────────────────────

    private function enforceRateLimit(): void
    {
        $now = time();

        // Clean up entries older than 1 minute
        self::$minuteTimestamps = array_filter(
            self::$minuteTimestamps,
            fn($t) => ($now - $t) < 60
        );

        // Clean up entries older than 1 day
        self::$dayTimestamps = array_filter(
            self::$dayTimestamps,
            fn($t) => ($now - $t) < 86400
        );

        if (count(self::$minuteTimestamps) >= self::MINUTE_LIMIT) {
            $wait = 60 - ($now - min(self::$minuteTimestamps));
            throw new RuntimeException(
                "VirusTotal rate limit: too many requests. Wait {$wait}s.",
                429
            );
        }

        if (count(self::$dayTimestamps) >= self::DAY_LIMIT) {
            throw new RuntimeException(
                'VirusTotal daily limit reached (500/day). Try again tomorrow.',
                429
            );
        }

        self::$minuteTimestamps[] = $now;
        self::$dayTimestamps[]    = $now;
    }

    // ── Private helpers ─────────────────────────────────────────────────

    private function get(string $endpoint): array
    {
        return httpRequest('GET', self::BASE . $endpoint, [
            'headers' => [
                "x-apikey: {$this->apiKey}",
                'Accept: application/json',
            ],
            'timeout' => 20,
        ]);
    }

    private function post(string $endpoint, string $formBody): array
    {
        return httpRequest('POST', self::BASE . $endpoint, [
            'headers' => [
                "x-apikey: {$this->apiKey}",
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            'body'    => $formBody,
            'timeout' => 20,
        ]);
    }

    /**
     * Base64url-encode a string (no padding).
     * Used to compute VT URL IDs and build web UI links.
     */
    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
