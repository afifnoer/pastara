<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeStatus: string
{
    case PENDING = 'PENDING';
    case REOPENED = 'REOPENED';
    case CLOSED = 'CLOSED';
}
