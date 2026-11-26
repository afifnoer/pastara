<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class InvalidStateTransitionException extends RuntimeException
{
    public function __construct(
        string $message = 'Status laporan telah berubah atau transisi tidak diizinkan.',
        int $code = 409,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
