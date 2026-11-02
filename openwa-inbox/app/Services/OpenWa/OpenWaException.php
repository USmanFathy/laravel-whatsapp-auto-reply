<?php

namespace App\Services\OpenWa;

use RuntimeException;

class OpenWaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
