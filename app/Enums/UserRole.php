<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case WARGA = 'WARGA';
    case ADMIN_UNIT = 'ADMIN_UNIT';
    case PETUGAS = 'PETUGAS';
    case INSPEKTORAT = 'INSPEKTORAT';
    case SUPER_ADMIN = 'SUPER_ADMIN';

    public function requiresUnit(): bool
    {
        return match ($this) {
            self::ADMIN_UNIT, self::PETUGAS => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WARGA => 'Warga',
            self::ADMIN_UNIT => 'Admin Unit',
            self::PETUGAS => 'Petugas Lapangan',
            self::INSPEKTORAT => 'Inspektorat',
            self::SUPER_ADMIN => 'Super Admin',
        };
    }
}
