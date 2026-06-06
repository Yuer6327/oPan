<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/Helpers.php';

cors();

$checks = [
    'koofr_email'       => !empty(getenv('KOOFR_EMAIL')),
    'koofr_app_password'=> !empty(getenv('KOOFR_APP_PASSWORD')),
    'vt_api_key'        => !empty(getenv('VT_API_KEY')),
];

$allOk = !in_array(false, $checks, true);

jsonOk([
    'status'     => $allOk ? 'healthy' : 'degraded',
    'configured' => $checks,
    'timestamp'  => date('c'),
    'version'    => '1.0.0',
], $allOk ? 200 : 200); // Always 200, status field indicates health
