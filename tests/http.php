<?php

declare(strict_types=1);

require __DIR__ . '/../src/PortabyteException.php';
require __DIR__ . '/../src/HttpClient.php';
require __DIR__ . '/../src/Files.php';
require __DIR__ . '/../src/Portabyte.php';

use Portabyte\HttpClient;
use Portabyte\Files;
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

$files = new Files(new HttpClient('pbt_sk_live_test', 'http://127.0.0.1:8765'));
$session = $files->create('sample.txt', 'text/plain', 5, ['visibility' => 'public']);
check($session['id'] === 'asset-1', 'Session creation failed.');
$browser = $files->prepareBrowserUpload('sample.txt', 'text/plain', 5);
check(!isset($browser['serverOnly']) && $browser['assetId'] === 'asset-1', 'Browser session exposed server fields.');

$file = tempnam(sys_get_temp_dir(), 'portabyte-');
if ($file === false) {
    throw new RuntimeException('Could not create a test file.');
}
try {
    file_put_contents($file, 'hello');
    $asset = $files->upload($file, ['name' => 'sample.txt', 'contentType' => 'text/plain']);
    check($asset['status'] === 'ready', 'Single upload was not confirmed.');
    check($files->get('asset-1')['id'] === 'asset-1', 'Get failed.');
    check($files->list(limit: 2)['cursor'] === 'next', 'List failed.');
    check($files->url('asset-1')['public'] === true, 'Delivery URL failed.');

    $partSize = 5 * 1024 * 1024;
    file_put_contents($file, str_repeat('a', $partSize) . 'z');
    clearstatcache(true, $file);
    $multipart = [
        'id' => 'asset-1', 'contentType' => 'text/plain', 'sizeBytes' => $partSize + 1,
        'uploadMode' => 'multipart', 'partSize' => $partSize,
        'uploadUrl' => 'http://127.0.0.1:8765/upload',
    ];
    $states = [];
    $asset = $files->resume($multipart, $file, [], static function (array $state) use (&$states): void {
        $states[] = $state;
    });
    check($asset['status'] === 'ready', 'Multipart upload was not confirmed.');
    check(count($states) === 3 && count($states[2]['parts']) === 2, 'Multipart state was not persisted.');
    $files->cancel($multipart, ['uploadId' => 'upload-1']);
    $files->remove('asset-1');
} finally {
    unlink($file);
}

echo "PHP file flow test passed.\n";
