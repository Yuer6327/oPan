<?php

declare(strict_types=1);

/**
 * Koofr API Client
 *
 * Uses App Password + HTTP Basic Auth.
 * Provides methods for mount listing, upload/download link generation.
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

        // Allow override via env, otherwise auto-detect the default mount
        $this->mountId = getenv('KOOFR_MOUNT_ID') ?: $this->resolveDefaultMount();
    }

    // ── Public API ──────────────────────────────────────────────────────

    /**
     * Get a presigned upload link for a file path.
     * Returns the temporary URL the frontend can PUT to directly.
     */
    public function getUploadLink(string $path): string
    {
        $res = $this->get("/api/v2/mounts/{$this->mountId}/files/link/upload", [
            'path' => $path,
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                "Koofr upload-link failed (HTTP {$res['status']}): " .
                ($res['raw'] ?? json_encode($res['body']))
            );
        }

        $body = $res['body'];
        if (!is_array($body) || empty($body['link'])) {
            throw new RuntimeException('Koofr upload-link: unexpected response format');
        }

        return $body['link'];
    }

    /**
     * Get a temporary download link for a file path.
     * Used to submit to VirusTotal for scanning.
     */
    public function getDownloadLink(string $path): string
    {
        $res = $this->get("/api/v2/mounts/{$this->mountId}/files/link/download", [
            'path' => $path,
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                "Koofr download-link failed (HTTP {$res['status']}): " .
                ($res['raw'] ?? json_encode($res['body']))
            );
        }

        $body = $res['body'];
        if (!is_array($body) || empty($body['link'])) {
            throw new RuntimeException('Koofr download-link: unexpected response format');
        }

        return $body['link'];
    }

    /**
     * Ensure a folder exists at the given path (mkdir -p).
     */
    public function ensureFolder(string $path): void
    {
        // Split the path and create each level
        $parts = array_filter(explode('/', $path), fn($p) => $p !== '');
        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            $this->post("/api/v2/mounts/{$this->mountId}/files/folder", [
                'path' => dirname($current) === '/' ? '/' : dirname($current),
            ], json_encode(['name' => $part]));
            // Ignore "already exists" errors
        }
    }

    /**
     * List available mounts (used to auto-detect default mount).
     */
    public function listMounts(): array
    {
        $res = $this->get('/api/v2/mounts');

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
        // Return the first mount (usually the user's primary "Koofr" mount)
        return $mounts[0]['id'] ?? throw new RuntimeException('Koofr: mount has no id field');
    }

    private function get(string $path, array $query = []): array
    {
        $url = self::BASE . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return httpRequest('GET', $url, [
            'headers' => [
                "Authorization: {$this->auth}",
                'Accept: application/json',
            ],
            'timeout' => 15,
        ]);
    }

    private function post(string $path, array $query = [], ?string $jsonBody = null): array
    {
        $url = self::BASE . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $options = [
            'headers' => [
                "Authorization: {$this->auth}",
                'Accept: application/json',
            ],
            'timeout' => 15,
        ];

        if ($jsonBody !== null) {
            $options['headers'][] = 'Content-Type: application/json';
            $options['body'] = $jsonBody;
        }

        return httpRequest('POST', $url, $options);
    }
}
