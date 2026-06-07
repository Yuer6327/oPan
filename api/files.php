<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';
require_once __DIR__ . '/../lib/SupabaseClient.php';

try {
    cors();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonError('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }

    $koofr = new KoofrClient();

    // List files in /oPan
    $rawFiles = $koofr->listFiles('/oPan');

    // Read scan status from Supabase
    $statusMap = [];
    try {
        $db = new SupabaseClient();
        $rows = $db->select('scan_status', ['select' => '*']);
        foreach ($rows as $row) {
            $statusMap[$row['file_path']] = $row;
        }
    } catch (Throwable) {
        // Non-fatal: show files without scan status
    }

    $files = [];
    foreach ($rawFiles as $f) {
        $name = $f['name'] ?? '';
        $type = $f['type'] ?? '';

        // Skip directories
        if ($type !== 'file') {
            continue;
        }

        $filePath = '/oPan/' . $name;
        $modified = $f['modified'] ?? 0;
        $size     = $f['size'] ?? 0;

        // Extract original filename (remove timestamp_random_ prefix)
        $originalName = $name;
        if (preg_match('/^\d+_[a-f0-9]+_(.+)$/', $name, $m)) {
            $originalName = $m[1];
        }

        // Get scan status from Supabase
        $scanInfo = $statusMap[$filePath] ?? null;
        $status   = 'unknown';
        $reportUrl = null;
        $malicious = 0;
        $total     = 0;

        if ($scanInfo) {
            $status    = $scanInfo['status'] ?? 'unknown';
            $reportUrl = $scanInfo['report_url'] ?? null;
            $malicious = (int)($scanInfo['malicious'] ?? 0);
            $total     = (int)($scanInfo['total'] ?? 0);
        } else {
            // If file was uploaded recently (<15min) and no status, assume scanning
            $ageSeconds = time() - (int)($modified / 1000);
            $status = ($ageSeconds < 900) ? 'scanning' : 'unknown';
        }

        $files[] = [
            'path'       => $filePath,
            'name'       => $originalName,
            'stored'     => $name,
            'size'       => $size,
            'modified'   => $modified,
            'status'     => $status,
            'report_url' => $reportUrl,
            'malicious'  => $malicious,
            'total'      => $total,
        ];
    }

    // Sort by modified time (newest first)
    usort($files, fn($a, $b) => ($b['modified'] <=> $a['modified']));

    jsonOk(['files' => $files]);

} catch (Throwable $e) {
    ob_end_clean();
    jsonError('Failed to list files: ' . $e->getMessage(), 502, 'LIST_ERROR');
}
