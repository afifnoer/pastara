<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\DisputeStatus;
use App\Enums\IssueType;
use App\Enums\NotificationType;
use App\Enums\PhotoType;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Exceptions\EscrowLockViolationException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\AuditLog;
use App\Models\BudgetSchedule;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\District;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\ReportUpvote;
use App\Models\User;
use App\Models\Village;
use App\Services\NotificationDispatcherService;
use App\Services\ReportStateMachineService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function makeFakeImageUpload(string $name = 'photo.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(320, 240);
    $color = imagecolorallocate($img, 50, 120, 160);
    imagefill($img, 0, 0, $color);

    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    $tmp = tempnam(sys_get_temp_dir(), 'pastara_sm_');
    file_put_contents($tmp, $bytes);

    return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
}

function createStateMachineActors(): array
{
    $dpupr = OpdUnit::create(['code' => 'DPUPR', 'name' => 'DPUPR Cilacap', 'level' => UnitLevel::OPD]);
    $dishub = OpdUnit::create(['code' => 'DISHUB', 'name' => 'Dishub Cilacap', 'level' => UnitLevel::OPD]);
    $desaSadabumi = OpdUnit::create(['code' => 'DESA_SADABUMI', 'name' => 'Desa Sadabumi', 'level' => UnitLevel::DESA]);
    $desaUmum = OpdUnit::create(['code' => 'DESA_UMUM', 'name' => 'Desa Terpadu Fallback', 'level' => UnitLevel::DESA]);
    $inspektoratUnit = OpdUnit::create(['code' => 'INSPEKTORAT', 'name' => 'Inspektorat', 'level' => UnitLevel::INSPEKTORAT]);

    $district = District::create(['code' => '33.01.14', 'name' => 'Majenang']);
    $villageIndependent = Village::create([
        'district_id' => $district->id,
        'opd_unit_id' => $desaSadabumi->id,
        'name' => 'Sadabumi',
        'type' => 'DESA',
    ]);
    $villageFallback = Village::create([
        'district_id' => $district->id,
        'opd_unit_id' => null,
        'name' => 'Ujungbarang',
        'type' => 'DESA',
    ]);

    $cat01 = Category::create([
        'code' => 'CAT-01',
        'name' => 'Jalan & Jembatan Rusak',
        'default_opd_unit_id' => $dpupr->id,
        'default_sla_hours' => 72,
        'issue_type' => IssueType::LINEAR,
        'duplicate_radius_m' => 750,
        'geotag_tolerance_m' => 500,
    ]);
    $cat05 = Category::create([
        'code' => 'CAT-05',
        'name' => 'Fasilitas Lingkungan RT/RW',
        'default_opd_unit_id' => null,
        'route_by_village' => true,
        'default_sla_hours' => 72,
        'issue_type' => IssueType::POINT,
        'duplicate_radius_m' => 200,
        'geotag_tolerance_m' => 100,
    ]);
    $cat06 = Category::create([
        'code' => 'CAT-06',
        'name' => 'Sekolah & Pendidikan',
        'default_opd_unit_id' => $dishub->id,
        'default_sla_hours' => 48,
        'issue_type' => IssueType::POINT,
        'duplicate_radius_m' => 200,
        'geotag_tolerance_m' => 100,
        'recommend_secret' => true,
    ]);

    $warga = User::create([
        'name' => 'Warga Pelapor',
        'email' => 'warga.sm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);
    $otherWarga = User::create([
        'name' => 'Warga Lain',
        'email' => 'warga2.sm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);
    $adminDpupr = User::create([
        'name' => 'Admin DPUPR',
        'email' => 'admin.dpupr.sm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::ADMIN_UNIT,
        'opd_unit_id' => $dpupr->id,
    ]);
    $adminDesa = User::create([
        'name' => 'Admin Desa Umum',
        'email' => 'admin.desa.sm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::ADMIN_UNIT,
        'opd_unit_id' => $desaUmum->id,
    ]);
    $officerDpupr = User::create([
        'name' => 'Petugas DPUPR',
        'email' => 'petugas.dpupr.sm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $dpupr->id,
    ]);
    $officerDishub = User::create([
        'name' => 'Petugas Dishub',
        'email' => 'petugas.dishub.sm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $dishub->id,
    ]);
    $auditor = User::create([
        'name' => 'Auditor Inspektorat',
        'email' => 'auditor.sm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::INSPEKTORAT,
        'opd_unit_id' => $inspektoratUnit->id,
    ]);
    $superAdmin = User::create([
        'name' => 'Super Admin',
        'email' => 'superadmin.sm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::SUPER_ADMIN,
    ]);

    return compact(
        'dpupr', 'dishub', 'desaSadabumi', 'desaUmum', 'district',
        'villageIndependent', 'villageFallback', 'cat01', 'cat05', 'cat06',
        'warga', 'otherWarga', 'adminDpupr', 'adminDesa', 'officerDpupr',
        'officerDishub', 'auditor', 'superAdmin'
    );
}

it('executes TR-01 auto-routing (including DESA_UMUM fallback and secret token), TR-02 reroute, and TR-03 reject', function (): void {
    Storage::fake('public');
    $fx = createStateMachineActors();
    $sm = app(ReportStateMachineService::class);

    // 1. TR-01 with DESA_UMUM fallback for CAT-05 + PII sanitization
    $fallbackResult = $sm->createAndRoute($fx['warga'], [
        'category_id' => $fx['cat05']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['villageFallback']->id,
        'latitude' => -7.3001,
        'longitude' => 108.7501,
        'title' => 'Got tersumbat hubungi 081234567890',
        'description' => 'NIK saya 3301123456789012, got mampet di RT 02.',
        'is_secret' => false,
    ], [makeFakeImageUpload('before1.jpg')]);

    $fallbackReport = $fallbackResult['report'];
    expect($fallbackResult['secret_token'])->toBeNull()
        ->and($fallbackReport->ticket_code)->toMatch('/^PST-[A-Z0-9]{8}$/')
        ->and($fallbackReport->status)->toBe(ReportStatus::ROUTED_TO_UNIT)
        ->and($fallbackReport->opd_unit_id)->toBe($fx['desaUmum']->id)
        ->and($fallbackReport->title)->toContain('[NO. HP DISENSOR]')
        ->and($fallbackReport->description_sanitized)->toContain('[NIK DISENSOR]')
        ->and($fallbackReport->photos)->toHaveCount(1)
        ->and($fallbackReport->photos->first()->photo_type)->toBe(PhotoType::BEFORE)
        ->and(AuditLog::where('report_id', $fallbackReport->id)->pluck('action_type')->all())
        ->toBe([AuditActionType::SUBMITTED, AuditActionType::ROUTED]);

    // 2. TR-02 reroute from DESA_UMUM to DPUPR with new category CAT-01 (recalculates snapshots)
    $rerouted = $sm->reroute(
        report: $fallbackReport,
        admin: $fx['adminDesa'],
        targetUnitId: $fx['dpupr']->id,
        newCategoryId: $fx['cat01']->id,
        note: 'Termasuk kewenangan jalan kabupaten DPUPR.'
    );
    expect($rerouted->opd_unit_id)->toBe($fx['dpupr']->id)
        ->and($rerouted->category_id)->toBe($fx['cat01']->id)
        ->and($rerouted->duplicate_radius_snapshot_m)->toBe(750)
        ->and($rerouted->geotag_tolerance_snapshot_m)->toBe(500);

    // 3. TR-03 reject by DPUPR admin
    $rejected = $sm->reject($rerouted, $fx['adminDpupr'], RejectionReasonCode::OUT_OF_JURISDICTION, 'Masuk kewenangan jalan nasional.');
    expect($rejected->status)->toBe(ReportStatus::REJECTED)
        ->and($rejected->rejection_reason_code)->toBe(RejectionReasonCode::OUT_OF_JURISDICTION)
        ->and($rejected->sla_stopped_at)->not->toBeNull();

    // 4. TR-01 with CAT-05 independent village (DESA_SADABUMI) & secret mode
    $secretResult = $sm->createAndRoute($fx['warga'], [
        'category_id' => $fx['cat05']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['villageIndependent']->id,
        'latitude' => -7.2915,
        'longitude' => 108.7420,
        'title' => 'Laporan rahasia fasilitas desa',
        'description' => 'Laporan kondisi fasilitas umum yang memerlukan perlindungan identitas.',
        'is_secret' => true,
    ], [makeFakeImageUpload('secret-before.jpg')]);

    expect($secretResult['secret_token'])->toMatch('/^TKN-[A-Z0-9]{4}-[A-Z0-9]{4}$/')
        ->and($secretResult['report']->opd_unit_id)->toBe($fx['desaSadabumi']->id)
        ->and($secretResult['report']->verifySecretToken($secretResult['secret_token']))->toBeTrue();
});

it('executes full Track 1 lifecycle (TR-04 -> TR-07 -> TR-09 -> TR-11 -> TR-13 -> TR-08) and verifies secret-token sign-off (TC-F-09)', function (): void {
    Storage::fake('public');
    $now = CarbonImmutable::parse('2026-11-22 02:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);

    $fx = createStateMachineActors();
    $sm = app(ReportStateMachineService::class);

    $created = $sm->createAndRoute($fx['warga'], [
        'category_id' => $fx['cat01']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['villageIndependent']->id,
        'latitude' => -7.2915,
        'longitude' => 108.7420,
        'title' => 'Jalan rusak parah butuh penanganan',
        'description' => 'Aspal berlubang sepanjang 20 meter di jalan utama.',
        'is_secret' => true,
    ], [makeFakeImageUpload('before.jpg')]);

    $report = $created['report'];
    $token = $created['secret_token'];

    // TR-04 assign operational (cross-unit officer rejected 422)
    expect(fn (): Report => $sm->assignOperational($report, $fx['adminDpupr'], $fx['officerDishub']))
        ->toThrow(ValidationException::class);

    $inProgress = $sm->assignOperational($report, $fx['adminDpupr'], $fx['officerDpupr'], 'Segera tambal.');
    expect($inProgress->status)->toBe(ReportStatus::IN_PROGRESS)
        ->and($inProgress->resolution_track)->toBe(ResolutionTrack::OPERATIONAL)
        ->and($inProgress->sla_deadline_at?->equalTo($now->addHours(72)))->toBeTrue();

    // TR-07 officer uploads AFTER evidence -> PENDING_CITIZEN_SIGN_OFF
    CarbonImmutable::setTestNow($now->addHours(12));
    $pendingSignOff = $sm->submitOfficerEvidence(
        report: $inProgress,
        officer: $fx['officerDpupr'],
        actionNote: 'Penambalan aspal telah dilaksanakan di lokasi.',
        afterPhotos: [makeFakeImageUpload('after1.jpg')],
        deviceLat: -7.2915,
        deviceLng: 108.7420,
    );
    expect($pendingSignOff->status)->toBe(ReportStatus::PENDING_CITIZEN_SIGN_OFF)
        ->and($pendingSignOff->sla_paused_at)->not->toBeNull()
        ->and($pendingSignOff->signoff_deadline_at?->equalTo($now->addHours(84)))->toBeTrue();

    // TR-09 citizen rejects (veto) using valid secret_token -> DISPUTED
    $disputed = $sm->citizenReject(
        report: $pendingSignOff,
        actor: null,
        secretToken: $token,
        reason: 'Tambalan masih berlubang dan tidak sesuai titik laporan.',
        disputePhotos: [makeFakeImageUpload('veto1.jpg')],
    );
    expect($disputed->status)->toBe(ReportStatus::DISPUTED);
    $dispute = Dispute::where('report_id', $disputed->id)->firstOrFail();
    expect($dispute->status)->toBe(DisputeStatus::PENDING)
        ->and(Notification::where('user_id', $fx['auditor']->id)->where('type', NotificationType::DISPUTE_RAISED)->exists())->toBeTrue();

    // TR-11 inspectorate reopens -> REOPENED_BY_AUDIT (current_cycle = 2, is_late = true)
    CarbonImmutable::setTestNow($now->addHours(20));
    $reopened = $sm->resolveDispute(
        dispute: $dispute,
        auditor: $fx['auditor'],
        decision: 'REOPENED',
        auditNotes: 'Klaim belum memenuhi mutu fisik, wajib diperbaiki ulang dalam 48 jam.',
        reopenSlaHours: 48,
    );
    expect($reopened->status)->toBe(ReportStatus::REOPENED_BY_AUDIT)
        ->and($reopened->current_cycle)->toBe(2)
        ->and($reopened->is_late)->toBeTrue()
        ->and($reopened->sla_deadline_at?->equalTo($now->addHours(68)))->toBeTrue();

    // TR-13 officer submits cycle 2 AFTER evidence
    CarbonImmutable::setTestNow($now->addHours(30));
    $signOffCycle2 = $sm->submitOfficerEvidence(
        report: $reopened,
        officer: $fx['officerDpupr'],
        actionNote: 'Perbaikan ulang siklus 2 telah selesai dan dipadatkan.',
        afterPhotos: [makeFakeImageUpload('after2.jpg')],
        deviceLat: -7.2915,
        deviceLng: 108.7420,
    );
    expect($signOffCycle2->status)->toBe(ReportStatus::PENDING_CITIZEN_SIGN_OFF)
        ->and(ReportPhoto::where('report_id', $report->id)->where('cycle', 2)->where('photo_type', PhotoType::AFTER)->count())->toBe(1)
        ->and(ReportPhoto::where('report_id', $report->id)->where('cycle', 1)->where('photo_type', PhotoType::AFTER)->count())->toBe(1);

    // TR-08 citizen approves via secret token -> VERIFIED_CLOSED
    $closed = $sm->citizenApprove($signOffCycle2, null, $token);
    expect($closed->status)->toBe(ReportStatus::VERIFIED_CLOSED)
        ->and($closed->sla_stopped_at)->not->toBeNull();

    CarbonImmutable::setTestNow();
});

it('executes Track 2 APBD (TR-05 -> TR-06), TR-10 auto-verify 72h, and TR-12 audit close', function (): void {
    Storage::fake('public');
    $now = CarbonImmutable::parse('2026-11-23 03:00:00', 'UTC'); // Mon 10:00 WIB
    CarbonImmutable::setTestNow($now);

    $fx = createStateMachineActors();
    $sm = app(ReportStateMachineService::class);

    // Track 2: TR-05 -> TR-06 -> TR-07 -> TR-10 (auto-verify 72h)
    $created = $sm->createAndRoute($fx['warga'], [
        'category_id' => $fx['cat01']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['villageIndependent']->id,
        'latitude' => -7.2915,
        'longitude' => 108.7420,
        'title' => 'Rekonstruksi ruas jalan desa',
        'description' => 'Memerlukan rekonstruksi beton bertulang pada APBD tahun depan.',
        'is_secret' => false,
    ], [makeFakeImageUpload('before-apbd.jpg')]);

    $apbd = $sm->scheduleApbd($created['report'], $fx['adminDpupr'], [
        'fiscal_year' => 2027,
        'proposal_ref_number' => 'RKA-DPUPR-2027-099',
        'target_quarter' => 'Q2',
        'estimated_budget' => 500000000,
        'note' => 'Dimasukkan ke usulan anggaran APBD 2027 Triwulan 2.',
    ]);

    expect($apbd->status)->toBe(ReportStatus::BUDGET_SCHEDULED)
        ->and($apbd->resolution_track)->toBe(ResolutionTrack::APBD)
        ->and($apbd->budgetSchedule)->toBeInstanceOf(BudgetSchedule::class);

    $executing = $sm->startApbdExecution($apbd, $fx['adminDpupr'], '2026-12-10', $fx['officerDpupr'], 'Kontraktor mulai bekerja.');
    expect($executing->status)->toBe(ReportStatus::IN_PROGRESS)
        ->and($executing->sla_deadline_at?->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'))->toBe('2026-12-10 23:59:59')
        ->and($executing->budgetSchedule->execution_target_date?->format('Y-m-d'))->toBe('2026-12-10');

    $pending = $sm->submitOfficerEvidence($executing, $fx['officerDpupr'], 'Pekerjaan selesai tuntas.', [makeFakeImageUpload('after-apbd.jpg')]);

    // Auto-verify before 72h fails 409
    CarbonImmutable::setTestNow($pending->signoff_deadline_at->subMinute());
    expect(fn (): Report => $sm->autoVerifyExpired($pending))->toThrow(InvalidStateTransitionException::class);

    // Auto-verify at 72h succeeds -> AUTO_VERIFIED_CLOSED
    CarbonImmutable::setTestNow($pending->signoff_deadline_at);
    $autoClosed = $sm->autoVerifyExpired($pending);
    expect($autoClosed->status)->toBe(ReportStatus::AUTO_VERIFIED_CLOSED)
        ->and(AuditLog::where('report_id', $autoClosed->id)->where('action_type', AuditActionType::AUTO_VERIFIED)->first()?->actor_role)->toBe('SYSTEM');

    // TR-12: Dispute closed by audit
    $created2 = $sm->createAndRoute($fx['warga'], [
        'category_id' => $fx['cat01']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['villageIndependent']->id,
        'latitude' => -7.2915,
        'longitude' => 108.7420,
        'title' => 'Aduan jalan lainnya untuk TR-12',
        'description' => 'Jalan rusak ringan di dekat balai desa.',
        'is_secret' => false,
    ], [makeFakeImageUpload('before2.jpg')]);
    $r2 = $sm->assignOperational($created2['report'], $fx['adminDpupr'], $fx['officerDpupr']);
    $r2 = $sm->submitOfficerEvidence($r2, $fx['officerDpupr'], 'Sudah ditambal rapi.', [makeFakeImageUpload('after-r2.jpg')]);
    $r2 = $sm->citizenReject($r2, $fx['warga'], null, 'Masih kurang rata menurut warga.', [makeFakeImageUpload('dispute-r2.jpg')]);
    $dispute2 = Dispute::where('report_id', $r2->id)->firstOrFail();

    $auditClosed = $sm->resolveDispute($dispute2, $fx['auditor'], 'CLOSED', 'Hasil pemeriksaan fisik di lapangan menyatakan perbaikan sah.');
    expect($auditClosed->status)->toBe(ReportStatus::CLOSED_BY_AUDIT)
        ->and($dispute2->fresh()->status)->toBe(DisputeStatus::CLOSED);

    CarbonImmutable::setTestNow();
});

it('blocks unilateral close via EscrowLockViolationException (TC-SEC-01) and rejects invalid transitions (TC-F-10)', function (): void {
    Storage::fake('public');
    $fx = createStateMachineActors();
    $sm = app(ReportStateMachineService::class);

    $created = $sm->createAndRoute($fx['warga'], [
        'category_id' => $fx['cat01']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['villageIndependent']->id,
        'latitude' => -7.2915,
        'longitude' => 108.7420,
        'title' => 'Uji Escrow Guard dan Transisi Tidak Sah',
        'description' => 'Deskripsi lengkap uji escrow guard dan state machine.',
        'is_secret' => false,
    ], [makeFakeImageUpload('before-sec.jpg')]);
    $report = $created['report'];

    // TC-SEC-01: Internal roles cannot close directly
    foreach ([$fx['adminDpupr'], $fx['officerDpupr'], $fx['superAdmin']] as $internalActor) {
        expect(fn () => $sm->guardUnilateralClose($internalActor, ReportStatus::VERIFIED_CLOSED))
            ->toThrow(EscrowLockViolationException::class);

        expect(fn (): Report => $sm->citizenApprove($report, $internalActor))
            ->toThrow(EscrowLockViolationException::class);
    }

    expect(file_exists(storage_path('logs/security.log')))->toBeTrue();

    // TC-F-10: Invalid transitions throw 409; unauthorized actor throws 403
    expect(fn (): Report => $sm->submitOfficerEvidence($report, $fx['officerDpupr'], 'Belum ditugaskan.', [makeFakeImageUpload('x.jpg')]))
        ->toThrow(AuthorizationException::class);

    $inProgress = $sm->assignOperational($report, $fx['adminDpupr'], $fx['officerDpupr']);

    expect(fn (): Report => $sm->reject($inProgress, $fx['adminDpupr'], RejectionReasonCode::HOAX, 'Mencoba menolak saat sudah berjalan.'))
        ->toThrow(InvalidStateTransitionException::class);

    $pending = $sm->submitOfficerEvidence($inProgress, $fx['officerDpupr'], 'Selesai dikerjakan.', [makeFakeImageUpload('after-sec.jpg')]);

    expect(fn (): Report => $sm->citizenApprove($pending, $fx['otherWarga']))
        ->toThrow(AuthorizationException::class);

    // Watching citizen still cannot approve
    ReportUpvote::create(['report_id' => $pending->id, 'user_id' => $fx['otherWarga']->id, 'is_watching' => true]);
    expect(fn (): Report => $sm->citizenApprove($pending, $fx['otherWarga']))
        ->toThrow(AuthorizationException::class);
});

it('rolls back entire transaction when notification or downstream step fails mid-transition (TC-I-02, NFR-007)', function (): void {
    Storage::fake('public');
    $fx = createStateMachineActors();
    $sm = app(ReportStateMachineService::class);

    $created = $sm->createAndRoute($fx['warga'], [
        'category_id' => $fx['cat01']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['villageIndependent']->id,
        'latitude' => -7.2915,
        'longitude' => 108.7420,
        'title' => 'Uji Rollback Transaksi Atomik',
        'description' => 'Memastikan status, budget_schedules, dan audit_logs rollback utuh saat gagal.',
        'is_secret' => false,
    ], [makeFakeImageUpload('before-rb.jpg')]);
    $report = $created['report'];
    $initialAuditCount = AuditLog::where('report_id', $report->id)->count();

    // Simulate failure inside NotificationDispatcherService during scheduleApbd (TR-05)
    $mockNotifier = Mockery::mock(NotificationDispatcherService::class)->makePartial();
    $mockNotifier->shouldReceive('notifyUsers')
        ->once()
        ->andThrow(new RuntimeException('Simulated notification failure mid-transaction'));

    app()->instance(NotificationDispatcherService::class, $mockNotifier);
    $failingSm = app(ReportStateMachineService::class);

    expect(fn (): Report => $failingSm->scheduleApbd($report, $fx['adminDpupr'], [
        'fiscal_year' => 2027,
        'proposal_ref_number' => 'RKA-ROLLBACK-001',
        'target_quarter' => 'Q1',
        'note' => 'Jadwal APBD yang harus ter-rollback ketika notifikasi gagal.',
    ]))->toThrow(RuntimeException::class, 'Simulated notification failure mid-transaction');

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::ROUTED_TO_UNIT)
        ->and($report->resolution_track)->toBeNull()
        ->and(BudgetSchedule::where('report_id', $report->id)->exists())->toBeFalse()
        ->and(AuditLog::where('report_id', $report->id)->count())->toBe($initialAuditCount);
});
