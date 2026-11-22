<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class EscrowLockViolationException extends RuntimeException
{
    public function __construct(
        string $message = 'Penutupan tiket sepihak dilarang oleh aturan Escrow.',
        int $code = 403,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
