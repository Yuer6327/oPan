<?php

declare(strict_types=1);

/**
 * VirusTotal API v3 Client
 *
 * Supports both URL scanning and direct file upload.
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
     * Submit a file directly for VirusTotal scanning.
     * Uses POST /files with multipart/form-data.
     * Returns the analysis ID and status endpoint.
     */
    public function submitFile(string $fileContent, string $filename): array
    {
        $this->enforceRateLimit();

        // Write content to a temp file for CURLFile
        $tmpFile = tempnam(sys_get_temp_dir(), 'vt_');
        file_put_contents($tmpFile, $fileContent);

        $res = httpRequest('POST', self::BASE . '/files', [
            'headers' => [
                "x-apikey: {$this->apiKey}",
            ],
            'form_fields' => [
                'file' => new CURLFile($tmpFile, 'application/octet-stream', $filename),
            ],
            'timeout' => 60,
        ]);

        @unlink($tmpFile);

        if ($res['status'] === 429) {
            throw new RuntimeException('VirusTotal rate limit exceeded. Please try again later.', 429);
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                "VirusTotal file submit failed (HTTP {$res['status']}): " .
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
     * Submit a URL for VirusTotal scanning (fallback method).
     * Uses POST /urls with form-encoded body.
     */
    public function submitUrl(string $url): array
    {
        $this->enforceRateLimit();

        $res = httpRequest('POST', self::BASE . '/urls', [
            'headers' => [
                "x-apikey: {$this->apiKey}",
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            'body'    => http_build_query(['url' => $url]),
            'timeout' => 20,
        ]);

        if ($res['status'] === 429) {
            throw new RuntimeException('VirusTotal rate limit exceeded.', 429);
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                "VirusTotal URL submit failed (HTTP {$res['status']}): " .
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
     */
    public function getAnalysisStatus(string $analysisId): array
    {
        $this->enforceRateLimit();

        $res = httpRequest('GET', self::BASE . "/analyses/{$analysisId}", [
            'headers' => [
                "x-apikey: {$this->apiKey}",
                'Accept: application/json',
            ],
            'timeout' => 20,
        ]);

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

            // Build permalink based on scan type
            $itemLink   = $res['body']['data']['links']['item'] ?? null;
            $submittedUrl = $attrs['meta']['url_info']['url'] ?? null;

            if ($submittedUrl) {
                // URL scan → /gui/url/{base64}
                $result['report_url'] = 'https://www.virustotal.com/gui/url/' . $this->base64UrlEncode($submittedUrl);
            } elseif ($itemLink && str_contains($itemLink, '/files/')) {
                // File scan → extract SHA256 from links.item URL
                $sha256 = basename($itemLink);
                $result['report_url'] = 'https://www.virustotal.com/gui/file/' . $sha256;
            } else {
                // Fallback: try meta.file_info
                $sha256 = $attrs['meta']['file_info']['sha256'] ?? null;
                if ($sha256) {
                    $result['report_url'] = 'https://www.virustotal.com/gui/file/' . $sha256;
                }
            }
        }

        return $result;
    }

    // ── Rate limiter ────────────────────────────────────────────────────

    private function enforceRateLimit(): void
    {
        $now = time();

        self::$minuteTimestamps = array_filter(self::$minuteTimestamps, fn($t) => ($now - $t) < 60);
        self::$dayTimestamps    = array_filter(self::$dayTimestamps, fn($t) => ($now - $t) < 86400);

        if (count(self::$minuteTimestamps) >= self::MINUTE_LIMIT) {
            $wait = 60 - ($now - min(self::$minuteTimestamps));
            throw new RuntimeException("VirusTotal rate limit: too many requests. Wait {$wait}s.", 429);
        }

        if (count(self::$dayTimestamps) >= self::DAY_LIMIT) {
            throw new RuntimeException('VirusTotal daily limit reached (500/day).', 429);
        }

        self::$minuteTimestamps[] = $now;
        self::$dayTimestamps[]    = $now;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
