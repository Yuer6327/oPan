<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';

cors();

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// ── Parse & validate input ────────────────────────────────────────────────
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

// 10 GB hard limit (Koofr supports large files, but let's be reasonable)
if ($size > 10 * 1024 * 1024 * 1024) {
    jsonError('File too large (max 10 GB)', 413, 'FILE_TOO_LARGE');
}

// ── Generate unique path and presigned URL ─────────────────────────────────
try {
    $koofr = new KoofrClient();

    // Build a unique destination path: /oPan/{timestamp}_{random}_{filename}
    $folder  = '/oPan';
    $uniqId  = time() . '_' . bin2hex(random_bytes(4));
    $destPath = "{$folder}/{$uniqId}_{$filename}";

    // Ensure the oPan folder exists (idempotent)
    $koofr->ensureFolder($folder);

    // Get the presigned upload URL
    $uploadUrl = $koofr->getUploadLink($destPath);

    jsonOk([
        'upload_url' => $uploadUrl,
        'file_path'  => $destPath,
        'filename'   => $filename,
        'size'       => $size,
    ]);

} catch (RuntimeException $e) {
    $status = $e->getCode() >= 400 ? $e->getCode() : 502;
    jsonError('Upload preparation failed: ' . $e->getMessage(), $status, 'KOORF_ERROR');
}
