<?php

declare(strict_types=1);

// Start output buffering to catch any accidental output
ob_start();

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';

try {
    cors();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonError('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }

    $filename = $_GET['filename'] ?? '';
    $sizeStr  = $_GET['size'] ?? '';

    if ($filename === '') {
        jsonError('Missing required parameter: filename', 400, 'MISSING_PARAM');
    }

    $filename = sanitizeFilename($filename);

    if ($sizeStr === '') {
        jsonError('Missing required parameter: size', 400, 'MISSING_PARAM');
    }

    $size = (int)$sizeStr;
    if ($size <= 0) {
        jsonError('Invalid file size', 400, 'INVALID_SIZE');
    }

    if ($size > 10 * 1024 * 1024 * 1024) {
        jsonError('File too large (max 10 GB)', 413, 'FILE_TOO_LARGE');
    }

    $koofr = new KoofrClient();

    $folder   = '/oPan';
    $uniqId   = time() . '_' . bin2hex(random_bytes(4));
    $destPath = "{$folder}/{$uniqId}_{$filename}";

    $koofr->ensureFolder($folder);
    $uploadUrl = $koofr->getUploadLink($destPath);

    jsonOk([
        'upload_url' => $uploadUrl,
        'file_path'  => $destPath,
        'filename'   => $filename,
        'size'       => $size,
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 502;
    jsonError('Upload preparation failed: ' . $e->getMessage(), $status, 'KOORF_ERROR');
}
