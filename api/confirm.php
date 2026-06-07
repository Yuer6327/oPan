<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/SupabaseClient.php';

try {
    cors();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonError('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $filePath = $input['file_path'] ?? '';
    $filename = $input['filename'] ?? '';
    $size     = (int)($input['size'] ?? 0);

    if ($filePath === '') {
        jsonError('Missing file_path', 400, 'MISSING_PARAM');
    }

    // Write scanning status to Supabase
    try {
        $db = new SupabaseClient();
        $db->upsert('scan_status', [
            'file_path'     => $filePath,
            'original_name' => $filename,
            'size'          => $size,
            'status'        => 'scanning',
            'malicious'     => 0,
            'total'         => 0,
            'report_url'    => null,
        ]);
    } catch (Throwable) {
        // Non-fatal
    }

    jsonOk(['message' => 'confirmed']);

} catch (Throwable $e) {
    ob_end_clean();
    jsonError('Confirm failed: ' . $e->getMessage(), 500, 'CONFIRM_ERROR');
}
