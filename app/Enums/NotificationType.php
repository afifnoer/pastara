<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationType: string
{
    case REPORT_ROUTED = 'REPORT_ROUTED';
    case REPORT_ASSIGNED = 'REPORT_ASSIGNED';
    case REPORT_APBD_SCHEDULED = 'REPORT_APBD_SCHEDULED';
    case REPORT_EXECUTION_STARTED = 'REPORT_EXECUTION_STARTED';
    case REPORT_REJECTED = 'REPORT_REJECTED';
    case SLA_WARNING_YELLOW = 'SLA_WARNING_YELLOW';
    case SLA_OVERDUE_RED = 'SLA_OVERDUE_RED';
    case APBD_MILESTONE_OVERDUE = 'APBD_MILESTONE_OVERDUE';
    case SIGN_OFF_REQUESTED = 'SIGN_OFF_REQUESTED';
    case SIGN_OFF_REMINDER_24H = 'SIGN_OFF_REMINDER_24H';
    case DISPUTE_RAISED = 'DISPUTE_RAISED';
    case AUDIT_DECISION_MADE = 'AUDIT_DECISION_MADE';
    case REPORT_CLOSED = 'REPORT_CLOSED';

    public function defaultTitle(): string
    {
        return match ($this) {
            self::REPORT_ROUTED => 'Laporan Masuk ke Unit Kerja',
            self::REPORT_ASSIGNED => 'Petugas Lapangan Ditugaskan',
            self::REPORT_APBD_SCHEDULED => 'Laporan Dijadwalkan ke Anggaran APBD',
            self::REPORT_EXECUTION_STARTED => 'Eksekusi Lapangan Dimulai',
            self::REPORT_REJECTED => 'Laporan Ditolak oleh Unit Kerja',
            self::SLA_WARNING_YELLOW => 'Peringatan SLA (Zona Kuning)',
            self::SLA_OVERDUE_RED => 'Batas Waktu SLA Terlewati',
            self::APBD_MILESTONE_OVERDUE => 'Kewajiban Milestone APBD Terlewati',
            self::SIGN_OFF_REQUESTED => 'Menunggu Konfirmasi Penyelesaian Warga',
            self::SIGN_OFF_REMINDER_24H => 'Pengingat 24 Jam Konfirmasi Warga',
            self::DISPUTE_RAISED => 'Sengketa / Veto Diajukan Pelapor',
            self::AUDIT_DECISION_MADE => 'Putusan Sidang Audit Inspektorat',
            self::REPORT_CLOSED => 'Tiket Pengaduan Ditutup',
        };
    }
}
