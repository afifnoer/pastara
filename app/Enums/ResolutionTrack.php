<?php

declare(strict_types=1);

namespace App\Enums;

enum ResolutionTrack: string
{
    case OPERATIONAL = 'OPERATIONAL';
    case APBD = 'APBD';

    public function label(): string
    {
        return match ($this) {
            self::OPERATIONAL => 'Jalur 1 — Penanganan Operasional',
            self::APBD => 'Jalur 2 — Terjadwal Anggaran APBD',
        };
    }
}
