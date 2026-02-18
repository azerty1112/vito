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
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
