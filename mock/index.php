<?php

header('Content-Type: application/json');
$body = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($body) || !isset($body['to'], $body['channel'], $body['content'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid payload']);
    return;
}
if ($body['content'] === 'mock:reject') {
    http_response_code(400);
    echo json_encode(['error' => 'rejected']);
    return;
}
if ($body['content'] === 'mock:unknown') {
    http_response_code(503);
    echo json_encode(['error' => 'unavailable']);
    return;
}
if ($body['content'] === 'mock:retry') {
    http_response_code(429);
    header('Retry-After: 1');
    echo json_encode(['error' => 'rate limited']);
    return;
}
http_response_code(202);
echo json_encode(['messageId' => bin2hex(random_bytes(16)), 'status' => 'accepted', 'timestamp' => gmdate('c')]);
