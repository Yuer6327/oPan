<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';

try {
    cors();

    $email    = env('KOOFR_EMAIL');
    $password = env('KOOFR_APP_PASSWORD');
    $auth     = 'Basic ' . base64_encode("{$email}:{$password}");
    $baseUrl  = 'https://app.koofr.net';

    // Step 1: List all mounts
    $mountsResp = httpRequest('GET', "{$baseUrl}/api/v2/mounts", [
        'headers' => ["Authorization: {$auth}", 'Accept: application/json'],
        'timeout' => 15,
    ]);

    $mounts = [];
    if (is_array($mountsResp['body']) && isset($mountsResp['body']['mounts'])) {
        foreach ($mountsResp['body']['mounts'] as $m) {
            $mounts[] = [
                'id'   => $m['id'] ?? 'N/A',
                'name' => $m['name'] ?? 'N/A',
                'type' => $m['type'] ?? 'N/A',
                'root' => $m['root'] ?? null,
            ];
        }
    }

    // Step 2: Check current mount ID
    $envMountId = getenv('KOOFR_MOUNT_ID');
    $usedMountId = $envMountId ?: ($mounts[0]['id'] ?? 'NONE');

    // Step 3: Try to list root files with the used mount
    $listResp = httpRequest('GET', "{$baseUrl}/api/v2/mounts/{$usedMountId}/files/list?path=/", [
        'headers' => ["Authorization: {$auth}", 'Accept: application/json'],
        'timeout' => 15,
    ]);

    $rootFiles = [];
    if (is_array($listResp['body']) && isset($listResp['body']['files'])) {
        foreach ($listResp['body']['files'] as $f) {
            $rootFiles[] = [
                'name'     => $f['name'] ?? '?',
                'type'     => $f['type'] ?? '?',
                'modified' => $f['modified'] ?? 0,
            ];
        }
    }

    // Step 4: Try to create /oPan folder
    $folderResp = httpRequest('POST',
        "{$baseUrl}/api/v2/mounts/{$usedMountId}/files/folder?path=/",
        [
            'headers' => [
                "Authorization: {$auth}",
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            'body'    => json_encode(['name' => 'oPan']),
            'timeout' => 15,
        ]
    );

    jsonOk([
        'mounts_http_status' => $mountsResp['status'],
        'mounts'             => $mounts,
        'env_mount_id'       => $envMountId ?: '(not set)',
        'used_mount_id'      => $usedMountId,
        'root_list_status'   => $listResp['status'],
        'root_files'         => $rootFiles,
        'create_folder_status' => $folderResp['status'],
        'create_folder_body'   => $folderResp['body'],
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    jsonError('Debug failed: ' . $e->getMessage(), 500, 'DEBUG_ERROR');
}
