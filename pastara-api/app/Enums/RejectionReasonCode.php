<?php

declare(strict_types=1);

namespace App\Enums;

enum RejectionReasonCode: string
{
    case HOAX = 'HOAX';
    case OUT_OF_JURISDICTION = 'OUT_OF_JURISDICTION';
    case INCOMPLETE = 'INCOMPLETE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::HOAX => 'Laporan Palsu / Hoaks',
            self::OUT_OF_JURISDICTION => 'Di Luar Kewenangan',
            self::INCOMPLETE => 'Data Tidak Lengkap',
            self::OTHER => 'Lainnya',
        };
    }
}
