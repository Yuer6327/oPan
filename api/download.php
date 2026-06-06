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

    // Security: only allow /oPan/ paths
    if (!str_starts_with($path, '/oPan/') || str_contains($path, '..')) {
        jsonError('Invalid path', 400, 'INVALID_PATH');
    }

    $koofr = new KoofrClient();
    $content = $koofr->downloadFile($path);

    if ($content === null) {
        jsonError('File not found or download failed', 404, 'NOT_FOUND');
    }

    // Determine filename for Content-Disposition
    $filename = basename($path);
    if (preg_match('/^\d+_[a-f0-9]+_(.+)$/', $filename, $m)) {
        $filename = $m[1];
    }

    // Determine content type
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
        'webp'=>'image/webp','svg'=>'image/svg+xml','bmp'=>'image/bmp','ico'=>'image/x-icon',
        'pdf'=>'application/pdf','zip'=>'application/zip','json'=>'application/json',
        'xml'=>'application/xml','txt'=>'text/plain','md'=>'text/markdown','csv'=>'text/csv',
        'html'=>'text/html','css'=>'text/css','js'=>'application/javascript',
        'mp3'=>'audio/mpeg','wav'=>'audio/wav','mp4'=>'video/mp4','avi'=>'video/x-msvideo',
        'doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

    // Send file
    if (!headers_sent()) {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($content));
    }
    echo $content;
    exit;

} catch (Throwable $e) {
    ob_end_clean();
    jsonError('Download failed: ' . $e->getMessage(), 502, 'DOWNLOAD_ERROR');
}
