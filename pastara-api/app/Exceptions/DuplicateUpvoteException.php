<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class DuplicateUpvoteException extends RuntimeException
{
    public function __construct(string $message = 'Anda sudah memberikan dukungan pada laporan ini.')
    {
        parent::__construct($message);
    }
}
