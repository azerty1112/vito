<?php
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

if (PHP_SAPI !== 'cli') {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        echo json_encode([
            'ok' => false,
            'error' => 'method_not_allowed',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cronKey = trim((string)getSetting('cron_access_key', ''));
    if ($cronKey !== '') {
        $requestKey = trim((string)($_GET['key'] ?? $_POST['key'] ?? ''));
        if (!hash_equals($cronKey, $requestKey)) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'error' => 'invalid_cron_key',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

$contentWorkflow = runSelectedContentWorkflow();
$selectedWorkflow = getSelectedContentWorkflow();

$schedulerResult = publishAutoArticleBySchedule();
$meta = getAutoPublishSchedulerMeta();

echo json_encode([
    'ok' => true,
    'timestamp' => date('c'),
    'selected_content_workflow' => $selectedWorkflow,
    'content_workflow_result' => $contentWorkflow,
    'scheduler' => $schedulerResult,
    'meta' => $meta,
    'namecheap_cron_hint' => [
        'recommended_interval_minutes' => 10,
        'url' => getCronEndpointUrl(),
        'with_key' => trim((string)getSetting('cron_access_key', '')) !== '' ? getCronEndpointUrl() . '?key=***' : null,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
