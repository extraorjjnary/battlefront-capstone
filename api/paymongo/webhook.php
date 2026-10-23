<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');

if (!$rawBody) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

// Connectivity test only.
// Do not update orders or payments here.
// Real processing requires signature verification.
error_log('PayMongo webhook request received');

http_response_code(200);
echo json_encode(['received' => true]);
