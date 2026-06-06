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

    $koofr = new KoofrClient();

    // List files in /oPan
    $rawFiles = $koofr->listFiles('/oPan');

    // Read scan status
    $statusData = $koofr->getStatusFile();
    $statusMap  = $statusData['files'] ?? [];

    $files = [];
    foreach ($rawFiles as $f) {
        $name = $f['name'] ?? '';
        $type = $f['type'] ?? '';

        // Skip directories and the status file itself
        if ($type !== 'file' || $name === '.scan-status.json') {
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

        // Get scan status from status file
        $scanInfo = $statusMap[$filePath] ?? null;
        $status   = 'unknown';
        $reportUrl = null;
        $malicious = 0;
        $total     = 0;

        if ($scanInfo) {
            $status    = $scanInfo['status'] ?? 'unknown';
            $reportUrl = $scanInfo['report_url'] ?? null;
            $malicious = $scanInfo['malicious'] ?? 0;
            $total     = $scanInfo['total'] ?? 0;
        } else {
            // If file was uploaded recently (<15min) and no status, assume scanning
            $ageSeconds = time() - (int)($modified / 1000);
            $status = ($ageSeconds < 900) ? 'scanning' : 'error';
        }

        $files[] = [
            'path'      => $filePath,
            'name'      => $originalName,
            'stored'    => $name,
            'size'      => $size,
            'modified'  => $modified,
            'status'    => $status,
            'report_url'=> $reportUrl,
            'malicious' => $malicious,
            'total'     => $total,
        ];
    }

    // Sort by modified time (newest first)
    usort($files, fn($a, $b) => ($b['modified'] <=> $a['modified']));

    jsonOk(['files' => $files]);

} catch (Throwable $e) {
    ob_end_clean();
    jsonError('Failed to list files: ' . $e->getMessage(), 502, 'LIST_ERROR');
}
