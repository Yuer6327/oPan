<?php

declare(strict_types=1);

/**
 * Koofr API Client
 *
 * Uses App Password + HTTP Basic Auth.
 * Provides upload, download, folder management via the content API.
 */
final class KoofrClient
{
    private const BASE = 'https://app.koofr.net';
    private string $auth;
    private string $mountId;

    public function __construct()
    {
        $email    = env('KOOFR_EMAIL');
        $password = env('KOOFR_APP_PASSWORD');
        $this->auth = 'Basic ' . base64_encode("{$email}:{$password}");
        $this->mountId = getenv('KOOFR_MOUNT_ID') ?: $this->resolveDefaultMount();
    }

    // ── Public API ──────────────────────────────────────────────────────

    /**
     * Upload a file to Koofr via the content API (multipart/form-data).
     */
    public function uploadFile(string $path, string $tmpPath, string $filename): array
    {
        $url = self::BASE . "/content/api/v2/mounts/{$this->mountId}/files/put"
             . '?path=' . rawurlencode(dirname($path) === '/' ? '/' : dirname($path))
             . '&filename=' . rawurlencode($filename)
             . '&info=true';

        $res = httpRequest('POST', $url, [
            'headers' => [
                "Authorization: {$this->auth}",
            ],
            'form_fields' => [
                'file' => new CURLFile($tmpPath, mime_content_type($tmpPath) ?: 'application/octet-stream', $filename),
            ],
            'timeout' => 300,
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                "Koofr upload failed (HTTP {$res['status']}): " .
                ($res['raw'] ?? json_encode($res['body']))
            );
        }

        return is_array($res['body']) ? $res['body'] : ['ok' => true];
    }

    /**
     * Download a file from Koofr via the content API.
     * Returns the raw file content as a string, or null on failure.
     */
    public function downloadFile(string $path): ?string
    {
        $url = self::BASE . "/content/api/v2/mounts/{$this->mountId}/files/get"
             . '?path=' . rawurlencode($path);

        $res = httpRequest('GET', $url, [
            'headers' => [
                "Authorization: {$this->auth}",
            ],
            'timeout' => 120,
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            return null;
        }

        return is_string($res['raw']) ? $res['raw'] : null;
    }

    /**
     * Ensure a folder exists at the given path (mkdir -p).
     */
    public function ensureFolder(string $path): void
    {
        $parts = array_filter(explode('/', $path), fn($p) => $p !== '');
        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            $this->postJson(
                "/api/v2/mounts/{$this->mountId}/files/folder",
                ['path' => dirname($current) === '/' ? '/' : dirname($current)],
                ['name' => $part]
            );
            // Ignore "already exists" errors
        }
    }

    /**
     * List available mounts.
     */
    public function listMounts(): array
    {
        $res = $this->getJson('/api/v2/mounts');
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException("Koofr list-mounts failed (HTTP {$res['status']})");
        }
        $body = $res['body'];
        return is_array($body) && isset($body['mounts']) ? $body['mounts'] : [];
    }

    public function getMountId(): string
    {
        return $this->mountId;
    }

    // ── Private helpers ─────────────────────────────────────────────────

    private function resolveDefaultMount(): string
    {
        $mounts = $this->listMounts();
        if (empty($mounts)) {
            throw new RuntimeException('Koofr: no mounts found. Set KOOFR_MOUNT_ID explicitly.');
        }
        return $mounts[0]['id'] ?? throw new RuntimeException('Koofr: mount has no id field');
    }

    private function getJson(string $path, array $query = []): array
    {
        $url = self::BASE . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return httpRequest('GET', $url, [
            'headers' => ["Authorization: {$this->auth}", 'Accept: application/json'],
            'timeout' => 15,
        ]);
    }

    private function postJson(string $path, array $query = [], array $body = []): array
    {
        $url = self::BASE . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return httpRequest('POST', $url, [
            'headers' => [
                "Authorization: {$this->auth}",
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 15,
        ]);
    }
}
