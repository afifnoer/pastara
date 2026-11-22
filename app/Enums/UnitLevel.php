<?php

declare(strict_types=1);

namespace App\Enums;

enum UnitLevel: string
{
    case OPD = 'OPD';
    case DESA = 'DESA';
    case KELURAHAN = 'KELURAHAN';
    case INSPEKTORAT = 'INSPEKTORAT';

    public function label(): string
    {
        return match ($this) {
            self::OPD => 'OPD Kabupaten',
            self::DESA => 'Pemerintah Desa',
            self::KELURAHAN => 'Kelurahan',
            self::INSPEKTORAT => 'Inspektorat Daerah',
        };
    }
}
