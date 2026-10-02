<?php

declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_URI'] === '/error') {
    http_response_code(429);
    echo json_encode(['code' => 'rate_limited', 'message' => 'Slow down.', 'requestId' => 'req-test']);
    return;
}

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'body' => file_get_contents('php://input'),
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
]);
