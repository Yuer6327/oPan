<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';

cors();

// Only accept GET or POST
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    jsonError('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// ── Mode 1: Poll existing analysis ────────────────────────────────────────
$analysisId = $_GET['analysisId'] ?? '';

if ($analysisId !== '') {
    try {
        $vt = new VirusTotalClient();
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
    } catch (RuntimeException $e) {
        $status = $e->getCode() === 429 ? 429 : 502;
        jsonError('Scan status check failed: ' . $e->getMessage(), $status, 'VT_ERROR');
    }
}

// ── Mode 2: Trigger new scan ──────────────────────────────────────────────
if ($method !== 'POST') {
    jsonError('Use POST to trigger a new scan, or GET with ?analysisId=... to poll', 400, 'BAD_REQUEST');
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$filePath = $input['file_path'] ?? '';
$filename = $input['filename'] ?? '';
$size     = (int)($input['size'] ?? 0);

if ($filePath === '') {
    jsonError('Missing required field: file_path', 400, 'MISSING_PARAM');
}

// ── Check file size limit for VT (32 MB) ──────────────────────────────────
const VT_SIZE_LIMIT = 32 * 1024 * 1024; // 32 MB

if ($size > VT_SIZE_LIMIT) {
    jsonOk([
        'scan_skipped' => true,
        'reason'       => 'File exceeds VirusTotal 32 MB limit for URL scanning.',
        'message'      => '文件大小超过 32MB，无法进行 VirusTotal 安全扫描。',
    ]);
}

// ── Get download link and submit to VirusTotal ────────────────────────────
try {
    $koofr = new KoofrClient();

    // Generate a temporary download link for VT to fetch
    $downloadUrl = $koofr->getDownloadLink($filePath);

    // Submit to VirusTotal
    $vt     = new VirusTotalClient();
    $result = $vt->submitUrl($downloadUrl);

    jsonOk([
        'analysis_id' => $result['analysis_id'],
        'status_url'  => $result['status_url'],
        'message'     => 'Scan submitted. Poll with ?analysisId=...',
    ]);

} catch (RuntimeException $e) {
    $status = $e->getCode() === 429 ? 429 : 502;
    jsonError('Scan submission failed: ' . $e->getMessage(), $status, 'SCAN_ERROR');
}
