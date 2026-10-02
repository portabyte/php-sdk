<?php

declare(strict_types=1);

require __DIR__ . '/../src/PortabyteException.php';
require __DIR__ . '/../src/HttpClient.php';
require __DIR__ . '/../src/Files.php';
require __DIR__ . '/../src/Portabyte.php';

use Portabyte\HttpClient;
use Portabyte\Portabyte;
use Portabyte\PortabyteException;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    new Portabyte('bad-key');
    throw new RuntimeException('Invalid key was accepted.');
} catch (PortabyteException $error) {
    check($error->errorCode === 'invalid_argument', 'Wrong invalid-key error.');
}

$client = new HttpClient('pbt_sk_live_test');
$created = $client->signedJson('POST', 'http://127.0.0.1:8765/echo', []);
check($created['method'] === 'POST', 'JSON request used the wrong method.');
check($created['body'] === '{}', 'Empty JSON body was not an object.');
check($created['authorization'] === null, 'Secret leaked to the signed endpoint.');

$stream = fopen('php://temp', 'w+b');
fwrite($stream, 'file bytes');
rewind($stream);
$uploaded = $client->upload('http://127.0.0.1:8765/echo', 'text/plain', $stream, 10);
fclose($stream);
check($uploaded['method'] === 'PUT', 'Upload used the wrong method.');
check($uploaded['body'] === 'file bytes', 'Upload bytes changed.');
check($uploaded['contentType'] === 'text/plain', 'Upload content type changed.');
check($uploaded['authorization'] === null, 'Secret leaked to the upload gateway.');

try {
    $client->signedJson('POST', 'http://127.0.0.1:8765/error', []);
    throw new RuntimeException('HTTP error was accepted.');
} catch (PortabyteException $error) {
    check($error->status === 429 && $error->errorCode === 'rate_limited' && $error->requestId === 'req-test', 'HTTP error details were lost.');
}

echo "PHP HTTP smoke test passed.\n";
