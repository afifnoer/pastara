<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\District;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\ReportUpvote;
use App\Models\User;
use App\Models\Village;
use App\Services\AuditLoggerService;
use App\Services\NotificationDispatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records immutable audit logs with actor metadata and public visibility rules', function (): void {
    $unit = OpdUnit::create([
        'code' => 'DPUPR',
        'name' => 'Dinas Pekerjaan Umum dan Penataan Ruang',
        'level' => UnitLevel::OPD,
    ]);

    $admin = User::create([
        'name' => 'Admin DPUPR',
        'email' => 'admin.audit@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::ADMIN_UNIT,
        'opd_unit_id' => $unit->id,
    ]);

    $reporter = User::create([
        'name' => 'Warga Audit',
        'email' => 'warga.audit@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);

    $district = District::create(['code' => '33.01.99', 'name' => 'Cilacap Tengah']);
    $village = Village::create(['district_id' => $district->id, 'name' => 'Sidanegara', 'type' => 'KELURAHAN']);
    $category = Category::create([
        'code' => 'CAT-01',
        'name' => 'Jalan & Jembatan Rusak',
        'default_opd_unit_id' => $unit->id,
        'default_sla_hours' => 72,
        'issue_type' => 'LINEAR',
        'duplicate_radius_m' => 750,
        'geotag_tolerance_m' => 500,
    ]);

    $report = Report::create([
        'ticket_code' => 'PST-AUDIT001',
        'reporter_id' => $reporter->id,
        'category_id' => $category->id,
        'opd_unit_id' => $unit->id,
        'district_id' => $district->id,
        'village_id' => $village->id,
        'title' => 'Jalan Berlubang Audit Test',
        'description_sanitized' => 'Deskripsi audit test laporan jalan berlubang.',
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'status' => ReportStatus::ROUTED_TO_UNIT,
        'issue_type_snapshot' => 'LINEAR',
        'duplicate_radius_snapshot_m' => 750,
        'geotag_tolerance_snapshot_m' => 500,
        'sla_hours_snapshot' => 72,
    ]);

    $logger = app(AuditLoggerService::class);

    $publicEntry = $logger->log(
        actionType: AuditActionType::ASSIGNED,
        report: $report,
        actor: $admin,
        fromStatus: ReportStatus::ROUTED_TO_UNIT,
        toStatus: ReportStatus::IN_PROGRESS,
        note: 'Petugas ditugaskan.',
        metadata: ['officer_id' => 10]
    );

    $internalEntry = $logger->log(
        actionType: AuditActionType::USER_CREATED,
        actor: null,
        note: 'Sistem membuat akun petugas.',
        actorRoleOverride: 'SUPER_ADMIN'
    );

    expect($publicEntry)->toBeInstanceOf(AuditLog::class)
        ->and($publicEntry->actor_role)->toBe('ADMIN_UNIT')
        ->and($publicEntry->actor_unit_name)->toBe('Dinas Pekerjaan Umum dan Penataan Ruang')
        ->and($publicEntry->is_public_visible)->toBeTrue()
        ->and($publicEntry->metadata)->toBe(['officer_id' => 10])
        ->and($internalEntry->actor_role)->toBe('SUPER_ADMIN')
        ->and($internalEntry->is_public_visible)->toBeFalse();
});

it('dispatches deduplicated in-app notifications to active target recipients', function (): void {
    $unit = OpdUnit::create([
        'code' => 'DISHUB',
        'name' => 'Dinas Perhubungan',
        'level' => UnitLevel::OPD,
    ]);

    $reporter = User::create([
        'name' => 'Pelapor Notif',
        'email' => 'reporter.notif@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);

    $watcher = User::create([
        'name' => 'Watcher Notif',
        'email' => 'watcher.notif@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);

    $inactiveUser = User::create([
        'name' => 'Nonaktif Notif',
        'email' => 'inactive.notif@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
        'is_active' => false,
    ]);

    $unitAdmin = User::create([
        'name' => 'Admin Dishub',
        'email' => 'admin.dishub.notif@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::ADMIN_UNIT,
        'opd_unit_id' => $unit->id,
    ]);

    $inspector = User::create([
        'name' => 'Auditor Inspektorat',
        'email' => 'auditor.notif@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::INSPEKTORAT,
    ]);

    $district = District::create(['code' => '33.01.98', 'name' => 'Cilacap Selatan']);
    $village = Village::create(['district_id' => $district->id, 'name' => 'Tambakreja', 'type' => 'KELURAHAN']);
    $category = Category::create([
        'code' => 'CAT-02',
        'name' => 'Lampu Jalan',
        'default_opd_unit_id' => $unit->id,
        'default_sla_hours' => 24,
        'issue_type' => 'POINT',
        'duplicate_radius_m' => 200,
        'geotag_tolerance_m' => 100,
    ]);

    $report = Report::create([
        'ticket_code' => 'PST-NOTIF01',
        'reporter_id' => $reporter->id,
        'category_id' => $category->id,
        'opd_unit_id' => $unit->id,
        'district_id' => $district->id,
        'village_id' => $village->id,
        'title' => 'Lampu PJU Mati Total',
        'description_sanitized' => 'Lampu PJU padam di jalan protokol.',
        'latitude' => -7.7340,
        'longitude' => 109.0180,
        'status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
        'issue_type_snapshot' => 'POINT',
        'duplicate_radius_snapshot_m' => 200,
        'geotag_tolerance_snapshot_m' => 100,
        'sla_hours_snapshot' => 24,
    ]);

    ReportUpvote::create([
        'report_id' => $report->id,
        'user_id' => $watcher->id,
        'is_watching' => true,
    ]);

    $dispatcher = app(NotificationDispatcherService::class);

    $recipients = $dispatcher->getReporterAndWatcherIds($report);
    $inserted = $dispatcher->notifyUsers(
        [$reporter->id, $watcher->id, $watcher->id, $inactiveUser->id, null],
        NotificationType::SIGN_OFF_REQUESTED,
        $report,
        'Silakan konfirmasi perbaikan tiket PST-NOTIF01.'
    );

    expect($recipients)->toBe([$reporter->id, $watcher->id])
        ->and($inserted)->toBe(2)
        ->and(Notification::where('report_id', $report->id)->count())->toBe(2)
        ->and(Notification::where('user_id', $inactiveUser->id)->count())->toBe(0)
        ->and(Notification::first()->title)->toBe(NotificationType::SIGN_OFF_REQUESTED->defaultTitle())
        ->and($dispatcher->getUnitAdminIds($unit->id))->toBe([$unitAdmin->id])
        ->and($dispatcher->getInspectorateIds())->toBe([$inspector->id]);
});
