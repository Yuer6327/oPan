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
        $vt     = new VirusTotalClient();
        $result = $vt->getAnalysisStatus($analysisId);

        $response = [
            'status'      => $result['status'],
            'is_complete' => $result['is_complete'],
        ];

        if ($result['is_complete'] && $result['stats']) {
            $s = $result['stats'];
            $response['scan_result'] = [
                'malicious'  => $s['malicious'],
                'undetected' => $s['undetected'],
                'total'      => $s['total'],
                'is_clean'   => $s['malicious'] === 0,
                'report_url' => $result['report_url'],
            ];
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

    // VT size limit check (32 MB)
    if ($size > 32 * 1024 * 1024) {
        jsonOk([
            'scan_skipped' => true,
            'reason'       => 'File exceeds VirusTotal 32 MB limit for URL scanning.',
            'message'      => '文件大小超过 32MB，无法进行 VirusTotal 安全扫描。',
        ]);
    }

    $koofr      = new KoofrClient();
    $downloadUrl = $koofr->getDownloadLink($filePath);

    $vt     = new VirusTotalClient();
    $result = $vt->submitUrl($downloadUrl);

    jsonOk([
        'analysis_id' => $result['analysis_id'],
        'status_url'  => $result['status_url'],
        'message'     => 'Scan submitted. Poll with ?analysisId=...',
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    $status = ($e->getCode() === 429) ? 429 : 502;
    jsonError('Scan failed: ' . $e->getMessage(), $status, 'SCAN_ERROR');
}
