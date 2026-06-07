<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../lib/Helpers.php';

try {
    cors();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonError('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }

    $filename = sanitizeFilename($_GET['filename'] ?? '');
    if ($filename === '') {
        jsonError('Missing filename', 400, 'MISSING_PARAM');
    }

    $email    = env('KOOFR_EMAIL');
    $password = env('KOOFR_APP_PASSWORD');
    $auth     = 'Basic ' . base64_encode("{$email}:{$password}");

    // Resolve mount ID
    $mountId = getenv('KOOFR_MOUNT_ID');
    if (!$mountId) {
        $mountsResp = httpRequest('GET', 'https://app.koofr.net/api/v2/mounts', [
            'headers' => ["Authorization: {$auth}", 'Accept: application/json'],
            'timeout' => 10,
        ]);
        $mounts = is_array($mountsResp['body']) ? ($mountsResp['body']['mounts'] ?? []) : [];
        if (empty($mounts)) jsonError('No Koofr mounts found', 500, 'NO_MOUNTS');
        $mountId = $mounts[0]['id'];
    }

    // Generate unique path
    $uniqId    = time() . '_' . bin2hex(random_bytes(4));
    $storedName = "{$uniqId}_{$filename}";
    $destPath   = "/oPan/{$storedName}";

    $uploadUrl = "https://app.koofr.net/content/api/v2/mounts/{$mountId}/files/put"
               . '?path=' . rawurlencode('/oPan')
               . '&filename=' . rawurlencode($storedName)
               . '&info=true';

    jsonOk([
        'upload_url'  => $uploadUrl,
        'auth_header' => $auth,
        'file_path'   => $destPath,
        'filename'    => $filename,
        'stored_name' => $storedName,
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    jsonError('Failed: ' . $e->getMessage(), 500, 'TOKEN_ERROR');
}
