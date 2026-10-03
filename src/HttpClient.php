<?php

declare(strict_types=1);

namespace Portabyte;

use JsonException;

/** @internal */
final class HttpClient
{
    private const API_URL = 'https://api.portabyte.dev';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiUrl = self::API_URL,
    ) {
    }

    /** @return array<string, mixed> */
    public function api(string $method, string $path, ?array $body = null): array
    {
        return $this->request(
            $method,
            $this->apiUrl . '/v1/' . $path,
            $body === null ? null : $this->encode($body),
            array_filter([
                'Authorization: Bearer ' . $this->apiKey,
                'X-Portabyte-SDK: php/' . Portabyte::VERSION,
                $method === 'GET' ? null : 'Content-Type: application/json',
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function signedJson(string $method, string $url, ?array $body = null): array
    {
        return $this->request(
            $method,
            $url,
            $body === null ? null : $this->encode($body),
            ['Content-Type: application/json'],
        );
    }

    /** @param resource $stream
     *  @return array<string, mixed>
     */
    public function upload(string $url, string $contentType, $stream, int $size): array
    {
        if (preg_match('/[\r\n]/', $contentType)) {
            throw new PortabyteException('Invalid upload content type.', 0, 'invalid_argument');
        }
        return $this->request('PUT', $url, null, ['Content-Type: ' . $contentType], $stream, $size);
    }

    /** @param list<string> $headers
     *  @param resource|null $stream
     *  @return array<string, mixed>
     */
    private function request(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        $stream = null,
        int $size = 0,
    ): array {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new PortabyteException('Could not initialize HTTP request.', 0, 'network_error');
        }

        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($stream !== null) {
            curl_setopt_array($curl, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $stream,
                CURLOPT_INFILESIZE => $size,
            ]);
        } elseif ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $networkError = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new PortabyteException($networkError ?: 'Network request failed.', 0, 'network_error');
        }
        $decoded = [];
        $validJson = $raw === '';
        if ($raw !== '') {
            try {
                $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($value)) {
                    $decoded = $value;
                    $validJson = true;
                }
            } catch (JsonException) {
                // An HTTP error may have a non-JSON body.
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new PortabyteException(
                is_string($decoded['message'] ?? null) ? $decoded['message'] : "Request failed with status $status.",
                $status,
                is_string($decoded['code'] ?? null) ? $decoded['code'] : 'request_failed',
                is_string($decoded['requestId'] ?? null) ? $decoded['requestId'] : ($responseHeaders['x-request-id'] ?? null),
            );
        }
        if (!$validJson) {
            throw new PortabyteException('Portabyte returned an invalid JSON response.', $status, 'invalid_response');
        }
        return $decoded;
    }

    private function encode(array $body): string
    {
        try {
            return json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new PortabyteException('Request body could not be encoded as JSON.', 0, 'invalid_argument');
        }
    }
}
