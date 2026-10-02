<?php

declare(strict_types=1);

namespace Portabyte;

use RuntimeException;

final class PortabyteException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = 'request_failed',
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status);
    }
}
