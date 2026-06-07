<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';
require_once __DIR__ . '/../lib/SupabaseClient.php';

try {
    cors();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonError('Method not allowed. Use POST with multipart/form-data.', 405, 'METHOD_NOT_ALLOWED');
    }

    if (empty($_FILES['file'])) {
        jsonError('No file uploaded. Send as multipart/form-data with field name "file".', 400, 'NO_FILE');
    }

    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        ];
        $msg = $errors[$file['error']] ?? 'Unknown upload error';
        jsonError($msg, 400, 'UPLOAD_ERR_' . $file['error']);
    }

    $filename = sanitizeFilename($file['name']);
    $tmpPath  = $file['tmp_name'];
    $size     = $file['size'];

    if ($size <= 0) {
        jsonError('Empty file', 400, 'EMPTY_FILE');
    }

    if ($size > 10 * 1024 * 1024 * 1024) {
        jsonError('File too large (max 10 GB)', 413, 'FILE_TOO_LARGE');
    }

    // ── Upload to Koofr ───────────────────────────────────────────────
    $koofr     = new KoofrClient();
    $folder    = '/oPan';
    $uniqId    = time() . '_' . bin2hex(random_bytes(4));
    $storedName = "{$uniqId}_{$filename}";
    $destPath   = "{$folder}/{$storedName}";

    $koofr->ensureFolder($folder);
    $koofr->uploadFile($destPath, $tmpPath, $storedName);

    // ── Write scanning status to Supabase ─────────────────────────────
    try {
        $db = new SupabaseClient();
        $db->upsert('scan_status', [
            'file_path'   => $destPath,
            'original_name' => $filename,
            'size'        => $size,
            'status'      => 'scanning',
            'malicious'   => 0,
            'total'       => 0,
            'report_url'  => null,
        ]);
    } catch (Throwable) {
        // Non-fatal: upload succeeded even if status write failed
    }

    jsonOk([
        'file_path' => $destPath,
        'filename'  => $filename,
        'size'      => $size,
        'message'   => '文件上传成功',
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 502;
    jsonError('Upload failed: ' . $e->getMessage(), $status, 'UPLOAD_ERROR');
}
