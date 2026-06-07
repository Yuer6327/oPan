<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';
require_once __DIR__ . '/../lib/SupabaseClient.php';

try {
    cors();

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];

    // ── Verify admin password ─────────────────────────────────────────
    $password = $input['password'] ?? $_GET['password'] ?? '';
    $adminKey = getenv('ADMIN_KEY');

    if (!$adminKey) {
        jsonError('Admin not configured', 500, 'NO_ADMIN_KEY');
    }
    if ($password === '' || !hash_equals($adminKey, $password)) {
        jsonError('密码错误', 401, 'UNAUTHORIZED');
    }

    $action = $input['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        // ── Verify only ───────────────────────────────────────────────
        case 'verify':
            jsonOk(['message' => '认证成功']);

        // ── Delete files ──────────────────────────────────────────────
        case 'delete':
            $paths = $input['paths'] ?? [];
            if (empty($paths)) {
                jsonError('No paths provided', 400, 'MISSING_PATHS');
            }

            $koofr = new KoofrClient();
            $db    = new SupabaseClient();
            $deleted = 0;
            $errors  = [];

            foreach ($paths as $path) {
                // Validate path
                if (!str_starts_with($path, '/oPan/')) {
                    $errors[] = "{$path}: invalid path";
                    continue;
                }
                try {
                    $ok = $koofr->deleteFile($path);
                    if ($ok) {
                        // Also delete from Supabase
                        try {
                            httpRequest('DELETE',
                                getenv('PUBLIC_SUPABASE_URL') . '/rest/v1/scan_status?file_path=eq.' . rawurlencode($path),
                                [
                                    'headers' => [
                                        'apikey: ' . getenv('PUBLIC_SUPABASE_ANON_KEY'),
                                        'Authorization: Bearer ' . getenv('PUBLIC_SUPABASE_ANON_KEY'),
                                    ],
                                    'timeout' => 10,
                                ]
                            );
                        } catch (Throwable) {}
                        $deleted++;
                    } else {
                        $errors[] = "{$path}: delete failed";
                    }
                } catch (Throwable $e) {
                    $errors[] = "{$path}: " . $e->getMessage();
                }
            }

            jsonOk([
                'deleted' => $deleted,
                'errors'  => $errors,
                'message' => "已删除 {$deleted} 个文件",
            ]);

        // ── Rename file ───────────────────────────────────────────────
        case 'rename':
            $path    = $input['path'] ?? '';
            $newName = $input['new_name'] ?? '';

            if ($path === '' || $newName === '') {
                jsonError('Missing path or new_name', 400, 'MISSING_PARAM');
            }
            if (!str_starts_with($path, '/oPan/')) {
                jsonError('Invalid path', 400, 'INVALID_PATH');
            }

            $newName = sanitizeFilename($newName);
            $dir     = dirname($path);
            $newPath = $dir . '/' . $newName;

            $koofr = new KoofrClient();
            $ok = $koofr->renameFile($path, $newName);

            if (!$ok) {
                jsonError('Rename failed', 502, 'RENAME_FAILED');
            }

            // Migrate Supabase record to new path (preserve status & report_url)
            try {
                $supabaseUrl = getenv('PUBLIC_SUPABASE_URL');
                $supabaseKey = getenv('PUBLIC_SUPABASE_ANON_KEY');

                // Read old record
                $oldRes = httpRequest('GET',
                    "{$supabaseUrl}/rest/v1/scan_status?file_path=eq." . rawurlencode($path),
                    [
                        'headers' => [
                            "apikey: {$supabaseKey}",
                            "Authorization: Bearer {$supabaseKey}",
                            'Accept: application/json',
                        ],
                        'timeout' => 10,
                    ]
                );
                $oldRows = is_array($oldRes['body']) ? $oldRes['body'] : [];
                $oldRow = $oldRows[0] ?? null;

                if ($oldRow) {
                    // Insert new record with updated path
                    $newRow = $oldRow;
                    $newRow['file_path'] = $newPath;
                    unset($newRow['created_at']);
                    httpRequest('POST', "{$supabaseUrl}/rest/v1/scan_status", [
                        'headers' => [
                            "apikey: {$supabaseKey}",
                            "Authorization: Bearer {$supabaseKey}",
                            'Content-Type: application/json',
                            'Prefer: resolution=merge-duplicates',
                        ],
                        'body'    => json_encode($newRow),
                        'timeout' => 10,
                    ]);
                }

                // Delete old record
                httpRequest('DELETE',
                    "{$supabaseUrl}/rest/v1/scan_status?file_path=eq." . rawurlencode($path),
                    [
                        'headers' => [
                            "apikey: {$supabaseKey}",
                            "Authorization: Bearer {$supabaseKey}",
                        ],
                        'timeout' => 10,
                    ]
                );
            } catch (Throwable) {}

            jsonOk([
                'old_path' => $path,
                'new_path' => $newPath,
                'new_name' => $newName,
                'message'  => '重命名成功',
            ]);

        // ── Update scan status ────────────────────────────────────────
        case 'update_status':
            $path   = $input['path'] ?? '';
            $status = $input['status'] ?? '';

            if ($path === '' || !in_array($status, ['scanning', 'clean', 'danger', 'error'], true)) {
                jsonError('Missing path or invalid status', 400, 'INVALID_PARAM');
            }

            // Use Supabase PATCH to only update status field (preserve report_url etc.)
            $supabaseUrl = getenv('PUBLIC_SUPABASE_URL');
            $supabaseKey = getenv('PUBLIC_SUPABASE_ANON_KEY');
            $res = httpRequest('PATCH',
                "{$supabaseUrl}/rest/v1/scan_status?file_path=eq." . rawurlencode($path),
                [
                    'headers' => [
                        "apikey: {$supabaseKey}",
                        "Authorization: Bearer {$supabaseKey}",
                        'Content-Type: application/json',
                        'Prefer: resolution=merge-duplicates,return=representation',
                    ],
                    'body'    => json_encode([
                        'status'    => $status,
                        'scan_time' => date('c'),
                    ]),
                    'timeout' => 10,
                ]
            );

            // If no row exists, upsert one
            $body = is_array($res['body']) ? $res['body'] : [];
            if (empty($body) || ($res['status'] < 200 || $res['status'] >= 300)) {
                $db = new SupabaseClient();
                $db->upsert('scan_status', [
                    'file_path' => $path,
                    'status'    => $status,
                    'scan_time' => date('c'),
                ]);
            }

            jsonOk(['message' => "状态已更新为 {$status}"]);

        default:
            jsonError('Unknown action: ' . $action, 400, 'UNKNOWN_ACTION');
    }

} catch (Throwable $e) {
    ob_end_clean();
    jsonError('Admin error: ' . $e->getMessage(), 500, 'ADMIN_ERROR');
}
