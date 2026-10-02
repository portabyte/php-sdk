<?php

declare(strict_types=1);

namespace Portabyte;

final class Portabyte
{
    public const VERSION = '0.1.0';

    public readonly Files $files;

    /** The key is scoped to a project. Keep this client on a trusted server. */
    public function __construct(string $apiKey)
    {
        if (!str_starts_with($apiKey, 'pbt_sk_live_') || preg_match('/[\r\n]/', $apiKey)) {
            throw new PortabyteException('apiKey must be a server API key starting with "pbt_sk_live_".', 0, 'invalid_argument');
        }

        $this->files = new Files(new HttpClient($apiKey));
    }
}
