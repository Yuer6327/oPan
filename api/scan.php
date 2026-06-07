<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/KoofrClient.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';
require_once __DIR__ . '/../lib/SupabaseClient.php';

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

            // Update scan status in Supabase (upsert to handle pre-migration files)
            if ($filePath !== '') {
                try {
                    $db = new SupabaseClient();
                    $db->upsert('scan_status', [
                        'file_path'  => $filePath,
                        'status'     => $isClean ? 'clean' : 'danger',
                        'malicious'  => $s['malicious'],
                        'total'      => $s['total'],
                        'report_url' => $result['report_url'],
                        'scan_time'  => date('c'),
                    ]);
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
        try {
            $db = new SupabaseClient();
            $db->upsert('scan_status', [
                'file_path' => $filePath,
                'status'    => 'error',
                'scan_time' => date('c'),
            ]);
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
        try {
            $db = new SupabaseClient();
            $db->upsert('scan_status', [
                'file_path' => $filePath,
                'status'    => 'error',
                'scan_time' => date('c'),
            ]);
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
