<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case ROUTED_TO_UNIT = 'ROUTED_TO_UNIT';
    case REJECTED = 'REJECTED';
    case BUDGET_SCHEDULED = 'BUDGET_SCHEDULED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case PENDING_CITIZEN_SIGN_OFF = 'PENDING_CITIZEN_SIGN_OFF';
    case DISPUTED = 'DISPUTED';
    case REOPENED_BY_AUDIT = 'REOPENED_BY_AUDIT';
    case VERIFIED_CLOSED = 'VERIFIED_CLOSED';
    case AUTO_VERIFIED_CLOSED = 'AUTO_VERIFIED_CLOSED';
    case CLOSED_BY_AUDIT = 'CLOSED_BY_AUDIT';

    public function isFinal(): bool
    {
        return match ($this) {
            self::REJECTED,
            self::VERIFIED_CLOSED,
            self::AUTO_VERIFIED_CLOSED,
            self::CLOSED_BY_AUDIT => true,
            default => false,
        };
    }

    public function isSlaRunning(): bool
    {
        return match ($this) {
            self::IN_PROGRESS,
            self::REOPENED_BY_AUDIT => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Dikirim',
            self::ROUTED_TO_UNIT => 'Menunggu Verifikasi Unit',
            self::REJECTED => 'Ditolak',
            self::BUDGET_SCHEDULED => 'Terjadwal Anggaran APBD',
            self::IN_PROGRESS => 'Sedang Dikerjakan',
            self::PENDING_CITIZEN_SIGN_OFF => 'Menunggu Konfirmasi Warga',
            self::DISPUTED => 'Dalam Sengketa Audit',
            self::REOPENED_BY_AUDIT => 'Dibuka Kembali oleh Audit',
            self::VERIFIED_CLOSED => 'Selesai Terverifikasi Warga',
            self::AUTO_VERIFIED_CLOSED => 'Selesai Otomatis (72 Jam)',
            self::CLOSED_BY_AUDIT => 'Ditutup oleh Inspektorat',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::SUBMITTED => 'slate',
            self::ROUTED_TO_UNIT => 'amber',
            self::REJECTED => 'red',
            self::BUDGET_SCHEDULED => 'blue',
            self::IN_PROGRESS => 'indigo',
            self::PENDING_CITIZEN_SIGN_OFF => 'orange',
            self::DISPUTED => 'purple',
            self::REOPENED_BY_AUDIT => 'rose',
            self::VERIFIED_CLOSED,
            self::AUTO_VERIFIED_CLOSED,
            self::CLOSED_BY_AUDIT => 'emerald',
        };
    }
}
