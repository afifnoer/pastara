<?php

declare(strict_types=1);

namespace App\Enums;

enum SlaZone: string
{
    case GREEN = 'GREEN';
    case YELLOW = 'YELLOW';
    case RED = 'RED';
    case PAUSED = 'PAUSED';
    case APBD_SCHEDULED = 'APBD_SCHEDULED';
    case STOPPED = 'STOPPED';

    public function label(): string
    {
        return match ($this) {
            self::GREEN => 'Zona Hijau',
            self::YELLOW => 'Zona Kuning',
            self::RED => 'Zona Merah (Overdue)',
            self::PAUSED => 'Dijeda (Escrow)',
            self::APBD_SCHEDULED => 'Terjadwal APBD',
            self::STOPPED => 'Berhenti',
        };
    }
}
