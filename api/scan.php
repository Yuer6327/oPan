<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';

try {
    cors();

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        jsonError('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }

    // ── Mode 1: Poll existing analysis ────────────────────────────────
    $analysisId = $_GET['analysisId'] ?? '';

    if ($analysisId !== '') {
        $filePath = $_GET['file_path'] ?? '';
        $vt       = new VirusTotalClient();
        $result   = $vt->getAnalysisStatus($analysisId);

        $response = [
            'status'      => $result['status'],
            'is_complete' => $result['is_complete'],
        ];

        if ($result['is_complete'] && $result['stats']) {
            $s = $result['stats'];
            $isClean = $s['malicious'] === 0;
            $response['scan_result'] = [
                'malicious'  => $s['malicious'],
                'undetected' => $s['undetected'],
                'total'      => $s['total'],
                'is_clean'   => $isClean,
                'report_url' => $result['report_url'],
            ];

            // Update status file if file_path provided
            if ($filePath !== '') {
                try {
                    $koofr = new KoofrClient();
                    $statusData = $koofr->getStatusFile();
                    $statusData['files'][$filePath] = [
                        'status'     => $isClean ? 'clean' : 'danger',
                        'malicious'  => $s['malicious'],
                        'total'      => $s['total'],
                        'report_url' => $result['report_url'],
                        'scan_time'  => date('c'),
                    ];
                    $koofr->updateStatusFile($statusData);
                } catch (Throwable) {
                    // Non-fatal
                }
            }
        }

        jsonOk($response);
    }

    // ── Mode 2: Trigger new scan ──────────────────────────────────────
    if ($method !== 'POST') {
        jsonError('Use POST to trigger scan, or GET with ?analysisId= to poll', 400, 'BAD_REQUEST');
    }

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $filePath = $input['file_path'] ?? '';
    $filename = $input['filename'] ?? '';
    $size     = (int)($input['size'] ?? 0);

    if ($filePath === '') {
        jsonError('Missing required field: file_path', 400, 'MISSING_PARAM');
    }

    // VT file upload size limit (32 MB for free accounts)
    if ($size > 32 * 1024 * 1024) {
        // Update status: skipped (no scan possible)
        try {
            $koofr = new KoofrClient();
            $statusData = $koofr->getStatusFile();
            $statusData['files'][$filePath] = [
                'status'     => 'error',
                'malicious'  => 0,
                'total'      => 0,
                'report_url' => null,
                'scan_time'  => date('c'),
                'error'      => 'File exceeds 32MB VT limit',
            ];
            $koofr->updateStatusFile($statusData);
        } catch (Throwable) {}

        jsonOk([
            'scan_skipped' => true,
            'reason'       => 'File exceeds VirusTotal 32 MB limit.',
            'message'      => '文件大小超过 32MB，无法进行 VirusTotal 安全扫描。',
        ]);
    }

    // ── Download file from Koofr and submit to VT ─────────────────────
    $koofr       = new KoofrClient();
    $fileContent = $koofr->downloadFile($filePath);

    if ($fileContent === null) {
        // Mark as error
        try {
            $statusData = $koofr->getStatusFile();
            $statusData['files'][$filePath] = [
                'status'     => 'error',
                'malicious'  => 0,
                'total'      => 0,
                'report_url' => null,
                'scan_time'  => date('c'),
                'error'      => 'Failed to download for scanning',
            ];
            $koofr->updateStatusFile($statusData);
        } catch (Throwable) {}

        jsonError('Failed to download file from storage for scanning', 502, 'DOWNLOAD_FAILED');
    }

    $vt     = new VirusTotalClient();
    $result = $vt->submitFile($fileContent, $filename ?: 'unknown');

    jsonOk([
        'analysis_id' => $result['analysis_id'],
        'status_url'  => $result['status_url'],
        'file_path'   => $filePath,
        'message'     => 'Scan submitted. Poll with ?analysisId=...',
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    $status = ($e->getCode() === 429) ? 429 : 502;
    jsonError('Scan failed: ' . $e->getMessage(), $status, 'SCAN_ERROR');
}
