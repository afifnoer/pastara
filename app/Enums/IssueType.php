<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueType: string
{
    case POINT = 'POINT';
    case LINEAR = 'LINEAR';

    public function defaultRadiusM(): int
    {
        return match ($this) {
            self::POINT => 200,
            self::LINEAR => 750,
        };
    }

    public function defaultGeotagToleranceM(): int
    {
        return match ($this) {
            self::POINT => 100,
            self::LINEAR => 500,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::POINT => 'Titik Lokasi',
            self::LINEAR => 'Ruas / Jalan / Saluran',
        };
    }
}
