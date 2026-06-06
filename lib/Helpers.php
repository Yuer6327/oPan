<?php

declare(strict_types=1);

// ── Global error handling (must be FIRST, before any other code) ─────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => "PHP Error: {$errstr} in {$errfile}:{$errline}",
        'code'  => 'PHP_ERROR',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'code'  => 'EXCEPTION',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Catch fatal errors
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => "Fatal: {$error['message']} in {$error['file']}:{$error['line']}",
            'code'  => 'FATAL_ERROR',
        ], JSON_UNESCAPED_UNICODE);
    }
});

// ── CORS ────────────────────────────────────────────────────────────────────
function cors(): void
{
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        header('Content-Type: application/json; charset=utf-8');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── JSON response helpers ───────────────────────────────────────────────────
function jsonOk(mixed $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array_merge(['ok' => true], (array)$data), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $status = 400, ?string $code = null): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
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
    $name = basename($name);
    $name = str_replace(["\0", "\r", "\n"], '', $name);
    $name = preg_replace('/[^\w\-. ()\[\]]/u', '_', $name);
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
    if ($ch === false) {
        return ['status' => 0, 'headers' => [], 'body' => null, 'error' => 'Failed to init cURL'];
    }

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

    $headers = $options['headers'] ?? [];
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if (isset($options['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
    }

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

    // $response is always a string here (not false)
    $headerStr = substr((string)$response, 0, $headerSz);
    $bodyStr   = substr((string)$response, $headerSz);

    $respHeaders = [];
    foreach (explode("\r\n", $headerStr) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))] = trim($v);
        }
    }

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
