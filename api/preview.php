<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';

try {
    cors();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonError('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }

    $path = $_GET['path'] ?? '';

    if (!str_starts_with($path, '/oPan/') || str_contains($path, '..')) {
        jsonError('Invalid path', 400, 'INVALID_PATH');
    }

    // Only allow preview for supported types
    $filename = basename($path);
    if (preg_match('/^\d+_[a-f0-9]+_(.+)$/', $filename, $m)) {
        $filename = $m[1];
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $previewableImages = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
    $previewableText   = ['txt', 'md', 'json', 'csv', 'xml', 'yaml', 'yml', 'html', 'css', 'js', 'php', 'py', 'sh', 'ini', 'conf', 'log'];

    if (!in_array($ext, $previewableImages) && !in_array($ext, $previewableText)) {
        jsonError('File type not previewable', 400, 'NOT_PREVIEWABLE');
    }

    $koofr = new KoofrClient();
    $content = $koofr->downloadFile($path);

    if ($content === null) {
        jsonError('File not found', 404, 'NOT_FOUND');
    }

    // Determine content type for inline display
    $mimeTypes = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
        'webp'=>'image/webp','svg'=>'image/svg+xml','bmp'=>'image/bmp',
        'txt'=>'text/plain; charset=utf-8','md'=>'text/plain; charset=utf-8',
        'json'=>'application/json; charset=utf-8','csv'=>'text/csv; charset=utf-8',
        'xml'=>'application/xml; charset=utf-8','yaml'=>'text/plain; charset=utf-8',
        'yml'=>'text/plain; charset=utf-8','html'=>'text/html; charset=utf-8',
        'css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8',
        'php'=>'text/plain; charset=utf-8','py'=>'text/plain; charset=utf-8',
        'sh'=>'text/plain; charset=utf-8','ini'=>'text/plain; charset=utf-8',
        'conf'=>'text/plain; charset=utf-8','log'=>'text/plain; charset=utf-8',
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

    if (!headers_sent()) {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: public, max-age=300');
    }
    echo $content;
    exit;

} catch (Throwable $e) {
    ob_end_clean();
    jsonError('Preview failed: ' . $e->getMessage(), 502, 'PREVIEW_ERROR');
}
