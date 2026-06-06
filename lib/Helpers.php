<?php

declare(strict_types=1);

// ── CORS ────────────────────────────────────────────────────────────────────
function cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── JSON response helpers ───────────────────────────────────────────────────
function jsonOk(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + (array)$data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $status = 400, ?string $code = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body = ['ok' => false, 'error' => $message];
    if ($code !== null) $body['code'] = $code;
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Environment variable helper ─────────────────────────────────────────────
function env(string $key, ?string $default = null): string
{
    $val = getenv($key);
    if ($val === false) {
        if ($default !== null) return $default;
        jsonError("Missing environment variable: {$key}", 500, 'CONFIG_ERROR');
    }
    return $val;
}

// ── Filename sanitisation ───────────────────────────────────────────────────
function sanitizeFilename(string $name): string
{
    // Strip path separators and null bytes
    $name = basename($name);
    $name = str_replace(["\0", "\r", "\n"], '', $name);
    // Keep only safe characters
    $name = preg_replace('/[^\w\-. ()\[\]]/u', '_', $name);
    // Collapse multiple underscores/spaces
    $name = preg_replace('/[_ ]{2,}/', '_', trim($name));
    return $name ?: 'unnamed';
}

// ── Format bytes for human display ──────────────────────────────────────────
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $b = (float)$bytes;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    return round($b, 2) . ' ' . $units[$i];
}

// ── HTTP client helper (cURL) ───────────────────────────────────────────────
function httpRequest(string $method, string $url, array $options = []): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => $options['timeout'] ?? 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    // Headers
    $headers = $options['headers'] ?? [];
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    // Body
    if (isset($options['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
    }

    // Form fields (for multipart/form-data POST)
    if (isset($options['form_fields'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['form_fields']);
    }

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $errno    = curl_errno($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSz = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    curl_close($ch);

    if ($response === false) {
        return [
            'status'  => 0,
            'headers' => [],
            'body'    => null,
            'error'   => "cURL error ({$errno}): {$error}",
        ];
    }

    $headerStr = substr($response, 0, $headerSz);
    $bodyStr   = substr($response, $headerSz);

    // Parse response headers
    $respHeaders = [];
    foreach (explode("\r\n", $headerStr) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))] = trim($v);
        }
    }

    // Try JSON decode
    $body = $bodyStr;
    $json = json_decode($bodyStr, true);
    if ($json !== null) $body = $json;

    return [
        'status'  => $status,
        'headers' => $respHeaders,
        'body'    => $body,
        'raw'     => $bodyStr,
        'error'   => null,
    ];
}
