<?php

declare(strict_types=1);

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/v1/assets' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? null) !== 'Bearer pbt_sk_live_test') {
        http_response_code(401);
        echo json_encode(['code' => 'unauthorized']);
        return;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    echo json_encode([
        'id' => 'asset-1', 'name' => $body['name'],
        'contentType' => $body['contentType'], 'sizeBytes' => $body['sizeBytes'],
        'uploadMode' => 'single', 'uploadUrl' => 'http://127.0.0.1:8765/upload',
        'uploadExpiresAt' => '2026-10-03T12:00:00Z',
        'serverOnly' => 'must-not-reach-browser',
    ]);
    return;
}

if ($path === '/v1/assets' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['records' => [['id' => 'asset-1']], 'cursor' => 'next']);
    return;
}

if ($path === '/v1/assets/asset-1/uploaded') {
    echo json_encode(['id' => 'asset-1', 'status' => 'ready']);
    return;
}

if ($path === '/v1/assets/asset-1/url') {
    echo json_encode(['url' => 'https://cdn.test/asset-1', 'public' => true]);
    return;
}

if ($path === '/v1/assets/asset-1' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    http_response_code(204);
    return;
}

if ($path === '/v1/assets/asset-1') {
    echo json_encode(['id' => 'asset-1']);
    return;
}

if ($path === '/upload' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        http_response_code(400);
        echo json_encode(['code' => 'secret_leaked']);
        return;
    }
    file_get_contents('php://input');
    http_response_code(201);
    return;
}

if ($path === '/upload/multipart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['uploadId' => 'upload-1']);
    return;
}

if (preg_match('~^/upload/multipart/upload-1/parts/(\d+)$~', $path, $matches)) {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        http_response_code(400);
        echo json_encode(['code' => 'secret_leaked']);
        return;
    }
    file_get_contents('php://input');
    echo json_encode(['partNumber' => (int) $matches[1], 'etag' => 'etag-' . $matches[1]]);
    return;
}

if ($path === '/upload/multipart/upload-1/complete') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (count($body['parts'] ?? []) !== 2) {
        http_response_code(400);
        echo json_encode(['code' => 'invalid_parts']);
        return;
    }
    echo '{}';
    return;
}

if ($path === '/upload/multipart/upload-1' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    http_response_code(204);
    return;
}

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
