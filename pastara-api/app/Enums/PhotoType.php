<?php

declare(strict_types=1);

namespace App\Enums;

enum PhotoType: string
{
    case BEFORE = 'BEFORE';
    case AFTER = 'AFTER';
    case PROGRESS = 'PROGRESS';
    case DISPUTE = 'DISPUTE';
}
