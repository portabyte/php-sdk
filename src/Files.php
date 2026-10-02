<?php

declare(strict_types=1);

namespace Portabyte;

use InvalidArgumentException;

final class Files
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Create a signed upload session. Options: path, visibility, corsOrigin.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function create(string $name, string $contentType, int $sizeBytes, array $options = []): array
    {
        if ($name === '' || $contentType === '' || $sizeBytes < 0) {
            throw new InvalidArgumentException('A name, content type, and nonnegative file size are required.');
        }
        return $this->http->api('POST', 'assets', array_merge([
            'name' => $name,
            'contentType' => $contentType,
            'sizeBytes' => $sizeBytes,
        ], array_intersect_key($options, array_flip(['path', 'visibility', 'corsOrigin']))));
    }

    /**
     * Upload a local file, then confirm it. Files 32 MiB or larger use multipart automatically.
     * @param array<string, mixed> $options path, visibility, corsOrigin, name, contentType
     * @return array<string, mixed>
     */
    public function upload(string $filePath, array $options = []): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('Upload file must be a readable local file.');
        }
        $size = filesize($filePath);
        if ($size === false) {
            throw new InvalidArgumentException('Could not determine upload file size.');
        }
        $name = $options['name'] ?? basename($filePath);
        $type = $options['contentType'] ?? (new \finfo(FILEINFO_MIME_TYPE))->file($filePath);
        if (!is_string($name) || !is_string($type) || $type === '') {
            throw new InvalidArgumentException('Upload name and contentType must be strings.');
        }
        $session = $this->create($name, $type, $size, $options);
        $this->transfer($session, $filePath);
        return $this->confirm($this->requiredString($session, 'id'));
    }

    /**
     * Return only the fields safe to send to a browser. Confirm from your server afterward.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function prepareBrowserUpload(string $name, string $contentType, int $sizeBytes, array $options = []): array
    {
        $session = $this->create($name, $contentType, $sizeBytes, $options);
        $browser = [
            'assetId' => $this->requiredString($session, 'id'),
            'uploadUrl' => $this->requiredString($session, 'uploadUrl'),
            'uploadExpiresAt' => $this->requiredString($session, 'uploadExpiresAt'),
            'uploadMode' => $this->requiredString($session, 'uploadMode'),
        ];
        foreach (['partSize', 'maxConcurrency'] as $key) {
            if (isset($session[$key])) {
                $browser[$key] = $session[$key];
            }
        }
        return $browser;
    }

    /** @return array<string, mixed> */
    public function confirm(string $assetId): array
    {
        return $this->http->api('POST', 'assets/' . rawurlencode($assetId) . '/uploaded');
    }

    /** @return array<string, mixed> */
    public function get(string $assetId): array
    {
        return $this->http->api('GET', 'assets/' . rawurlencode($assetId));
    }

    /** @return array<string, mixed> */
    public function list(?string $cursor = null, ?int $limit = null): array
    {
        $query = [];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
        }
        return $this->http->api('GET', 'assets' . ($query ? '?' . http_build_query($query) : ''));
    }

    public function remove(string $assetId): void
    {
        $this->http->api('DELETE', 'assets/' . rawurlencode($assetId));
    }

    /**
     * Abort a multipart transfer if started, then remove its pending asset.
     * The signed gateway abort is best effort; removing the asset prevents confirmation.
     * @param array<string, mixed> $session
     * @param array<string, mixed> $state
     */
    public function cancel(array $session, array $state = []): void
    {
        $uploadId = $state['uploadId'] ?? null;
        if (($session['uploadMode'] ?? null) === 'multipart' && is_string($uploadId) && $uploadId !== '') {
            try {
                $this->http->signedJson('DELETE', $this->requiredString($session, 'uploadUrl') . '/multipart/' . rawurlencode($uploadId));
            } catch (PortabyteException) {
                // Removing the pending asset is still required if the signed URL expired.
            }
        }
        $this->remove($this->requiredString($session, 'id'));
    }

    /** @return array<string, mixed> */
    public function url(string $assetId): array
    {
        return $this->http->api('GET', 'assets/' . rawurlencode($assetId) . '/url');
    }

    /**
     * Continue an existing session with the same local file. Persist $state after each part
     * through $onStateChange for process restart recovery.
     * @param array<string, mixed> $session
     * @param array<string, mixed> $state
     * @param callable(array<string, mixed>): void|null $onStateChange
     * @return array<string, mixed>
     */
    public function resume(array $session, string $filePath, array $state = [], ?callable $onStateChange = null): array
    {
        if (!is_file($filePath) || !is_readable($filePath) || filesize($filePath) !== ($session['sizeBytes'] ?? null)) {
            throw new InvalidArgumentException('The selected file does not match the upload session size.');
        }
        $this->transfer($session, $filePath, $state, $onStateChange);
        return $this->confirm($this->requiredString($session, 'id'));
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $state
     * @param callable(array<string, mixed>): void|null $onStateChange
     */
    private function transfer(array $session, string $filePath, array $state = [], ?callable $onStateChange = null): void
    {
        $url = $this->requiredString($session, 'uploadUrl');
        $type = $this->requiredString($session, 'contentType');
        $size = $session['sizeBytes'] ?? null;
        if (!is_int($size) || $size < 0) {
            throw new PortabyteException('Upload session has an invalid size.', 0, 'invalid_upload');
        }
        $stream = fopen($filePath, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('Could not open upload file.');
        }
        try {
            if (($session['uploadMode'] ?? null) === 'single') {
                $this->http->upload($url, $type, $stream, $size);
            } elseif (($session['uploadMode'] ?? null) === 'multipart') {
                $this->multipart($session, $stream, $state, $onStateChange);
            } else {
                throw new PortabyteException('Upload session has an invalid mode.', 0, 'invalid_upload');
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, mixed> $session
     * @param resource $stream
     * @param array<string, mixed> $state
     * @param callable(array<string, mixed>): void|null $onStateChange
     */
    private function multipart(array $session, $stream, array $state, ?callable $onStateChange): void
    {
        $url = $this->requiredString($session, 'uploadUrl');
        $type = $this->requiredString($session, 'contentType');
        $size = $session['sizeBytes'];
        $partSize = $session['partSize'] ?? null;
        if (!is_int($partSize) || $partSize < 5 * 1024 * 1024) {
            throw new PortabyteException('Multipart session has an invalid part size.', 0, 'invalid_upload');
        }
        $uploadId = $state['uploadId'] ?? null;
        if (!is_string($uploadId) || $uploadId === '') {
            $started = $this->http->signedJson('POST', $url . '/multipart', []);
            $uploadId = $this->requiredString($started, 'uploadId');
            $state = ['uploadId' => $uploadId, 'parts' => []];
            $onStateChange && $onStateChange($state);
        }
        $parts = [];
        $count = (int) ceil($size / $partSize);
        foreach ($state['parts'] ?? [] as $part) {
            if (!is_array($part) || !is_int($part['partNumber'] ?? null) ||
                $part['partNumber'] < 1 || $part['partNumber'] > $count ||
                !is_string($part['etag'] ?? null) || $part['etag'] === '') {
                throw new PortabyteException('Multipart state contains an invalid part.', 0, 'invalid_argument');
            }
            $parts[$part['partNumber']] = $part;
        }
        for ($number = 1; $number <= $count; $number++) {
            if (isset($parts[$number])) {
                continue;
            }
            $length = min($partSize, $size - ($number - 1) * $partSize);
            if (fseek($stream, ($number - 1) * $partSize) !== 0) {
                throw new PortabyteException('Could not seek upload file.', 0, 'invalid_upload');
            }
            $chunk = fopen('php://temp', 'w+b');
            if ($chunk === false) {
                throw new PortabyteException('Could not prepare upload part.', 0, 'invalid_upload');
            }
            try {
                if (stream_copy_to_stream($stream, $chunk, $length) !== $length) {
                    throw new PortabyteException('Could not read upload part.', 0, 'invalid_upload');
                }
                rewind($chunk);
                $part = $this->http->upload($url . '/multipart/' . rawurlencode($uploadId) . '/parts/' . $number, $type, $chunk, $length);
            } finally {
                fclose($chunk);
            }
            if (($part['partNumber'] ?? null) !== $number || !is_string($part['etag'] ?? null)) {
                throw new PortabyteException('Upload gateway returned an invalid part.', 0, 'invalid_response');
            }
            $parts[$number] = $part;
            ksort($parts);
            $state = ['uploadId' => $uploadId, 'parts' => array_values($parts)];
            $onStateChange && $onStateChange($state);
        }
        ksort($parts);
        $this->http->signedJson('POST', $url . '/multipart/' . rawurlencode($uploadId) . '/complete', ['parts' => array_values($parts)]);
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new PortabyteException("Response is missing $key.", 0, 'invalid_response');
        }
        return $data[$key];
    }
}
