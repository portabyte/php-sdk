<picture>
  <source media="(prefers-color-scheme: dark)" srcset="assets/logo-horizontal-white.svg">
  <img src="assets/logo-horizontal-color.svg" alt="Portabyte" width="260">
</picture>

# Portabyte PHP SDK

Upload, deliver, and manage files from a PHP server with `portabyte/php`.

[![Packagist version](https://img.shields.io/packagist/v/portabyte/php)](https://packagist.org/packages/portabyte/php)
[![CI](https://github.com/portabyte/php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/portabyte/php-sdk/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE)

## Requirements

- PHP 8.1 or later
- cURL, Fileinfo, and JSON PHP extensions
- A Portabyte project API key (`pbt_sk_live_...`)

Keep the key on your server. This SDK is not for browser code.

## Install

```sh
composer require portabyte/php
```

## Quick start

Create a [project API key](https://portabyte.dev/docs/getting-started/api-keys), then place a PDF named `summary.pdf` beside your script:

```sh
export PORTABYTE_API_KEY="pbt_sk_live_your_key_here"
```

Save this as `quickstart.php`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Portabyte\Portabyte;

$apiKey = getenv('PORTABYTE_API_KEY');
if ($apiKey === false) {
    throw new RuntimeException('PORTABYTE_API_KEY is required.');
}

$portabyte = new Portabyte($apiKey);
$asset = $portabyte->files->upload(__DIR__ . '/summary.pdf', [
    'visibility' => 'public',
]);

echo $asset['id'] . ' ' . $asset['publicUrl'] . PHP_EOL;
```

Run `php quickstart.php`. The SDK creates an upload session, sends the bytes to its signed upload URL, and confirms the file. The API endpoint is built in; you only configure the project key.

## Common tasks

```php
// Use an asset ID returned by upload() or list().
$asset = $portabyte->files->get($assetId);
$delivery = $portabyte->files->url($assetId);
$page = $portabyte->files->list(limit: 20);
$nextPage = isset($page['cursor'])
    ? $portabyte->files->list(cursor: $page['cursor'], limit: 20)
    : null;
$portabyte->files->remove($assetId);
```

Public files have stable delivery URLs. Private files receive short-lived signed URLs; request a new one when needed. Set `path` during upload to replace the current file at an application-owned path.

Large files use multipart upload automatically. To recover from an interrupted upload, persist the session returned by `files->create()` and the state passed to the callback in `files->resume()`:

```php
$session = $portabyte->files->create('video.mp4', 'video/mp4', filesize($path));
$asset = $portabyte->files->resume(
    $session,
    $path,
    $savedState,
    function (array $state): void { saveUploadState($state); },
);
```

For uploads directly from a browser, call `files->prepareBrowserUpload()` and `files->confirm()` on your server. Send only the browser-safe session to the browser; never send the API key. See [Browser uploads](https://portabyte.dev/docs/upload-delivery/browser-uploads).

To abandon a multipart session, call `$portabyte->files->cancel($session, $savedState)` to abort its transfer and remove the pending asset.

## Errors

Failed requests throw `PortabyteException`:

```php
use Portabyte\PortabyteException;

try {
    $asset = $portabyte->files->get($assetId);
} catch (PortabyteException $error) {
    error_log("Portabyte error {$error->status}: {$error->errorCode} ({$error->requestId})");
    throw $error;
}
```

The exception exposes `status`, `errorCode`, and `requestId` when the API supplies one.

## When to use this SDK

Use it on a trusted server to upload and manage files, prepare direct browser uploads, and request delivery URLs. A browser can send file bytes to a signed upload URL prepared by your server; it must never receive your project API key.

## Documentation

- [PHP SDK guide](https://portabyte.dev/docs/getting-started/php-sdk)
- [REST API reference](https://portabyte.dev/docs/api-reference)
- [Public and private files](https://portabyte.dev/docs/upload-delivery/public-and-private-files)
- [Changelog](./CHANGELOG.md)

## Development

Run the local HTTP smoke test from this directory:

```sh
php -S 127.0.0.1:8765 tests/server.php
```

In a second terminal, run `php tests/http.php`.

## License

[MIT](./LICENSE)
