<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use App\Policies\ReportPolicy;
use App\Services\CitizenReportService;
use App\Services\OfficerTaskService;
use App\Services\ReportStateMachineService;
use App\Services\UnitReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function makeP0ValidJpeg(string $name = 'valid.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(120, 90);
    $bg = imagecolorallocate($img, 50, 110, 170);
    imagefill($img, 0, 0, $bg);
    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function makeP0CorruptJpeg(string $name = 'corrupt.jpg'): UploadedFile
{
    // Valid JFIF/JPEG magic header so finfo detects image/jpeg, followed by corrupted stream
    $corruptBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00NOT_A_VALID_JPEG_STREAM";

    return UploadedFile::fake()->createWithContent($name, $corruptBytes);
}

it('returns HTTP 422 instead of PostgreSQL 500 on non-integer, array, and BIGINT-overflow FK inputs across Fase 5 FormRequests (H-01)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();

    $dpuprRouted = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();

    $dpuprRunning = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::IN_PROGRESS->value)
        ->firstOrFail();

    $sada = Report::where('ticket_code', 'PST-SADA2027')->firstOrFail();

    // 1. M03 #13 GET /reports/nearby
    $this->actingAs($warga, 'sanctum')
        ->getJson('/api/v1/reports/nearby?category_id=abc&latitude=-7.71&longitude=109.01')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $this->actingAs($warga, 'sanctum')
        ->getJson('/api/v1/reports/nearby?category_id=99999999999999999999999999&latitude=-7.71&longitude=109.01')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 2. M03 #14 POST /reports
    $this->actingAs($warga, 'sanctum')
        ->postJson('/api/v1/reports', [
            'category_id' => '99999999999999999999999999',
            'district_id' => 'abc',
            'village_id' => [['nested']],
            'latitude' => -7.7188,
            'longitude' => 109.0154,
            'title' => 'Laporan uji input FK non-integer PostgreSQL',
            'description' => 'Memastikan validasi bail menghentikan query exists ke kolom BIGINT PostgreSQL.',
            'is_secret' => false,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 3. M04 #21 GET /unit/reports
    $this->actingAs($adminDpupr, 'sanctum')
        ->getJson('/api/v1/unit/reports?category_id=abc')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 4. M04 #24 assign, #28 reassign-officer, #30 reroute, #27 start-execution
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/assign", ['officer_id' => 'abc'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $this->actingAs($adminDpupr, 'sanctum')
        ->patchJson("/api/v1/unit/reports/{$dpuprRunning->id}/reassign-officer", [
            'officer_id' => '99999999999999999999999999',
            'reason' => 'Pengujian input BIGINT overflow pada officer_id.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/reroute", [
            'target_opd_unit_id' => 'abc',
            'new_category_id' => '99999999999999999999999999',
            'note' => 'Pengujian input non-integer pada target unit dan kategori baru.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $futureDate = CarbonImmutable::now('Asia/Jakarta')->addDays(5)->toDateString();
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$sada->id}/start-execution", [
            'execution_target_date' => $futureDate,
            'officer_id' => ['not-an-int'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('clamps sanitized address_detail to 255 chars to prevent PostgreSQL VARCHAR(255) overflow (H-02)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $village = Village::query()->firstOrFail();

    // 250 chars total containing a 10-digit phone (0812345678 -> [NO. HP DISENSOR] expands +7 chars = 257 chars)
    $longAddressWithPhone = str_repeat('A', 240).'0812345678';
    expect(mb_strlen($longAddressWithPhone))->toBe(250);

    $response = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat01->id,
            'district_id' => $village->district_id,
            'village_id' => $village->id,
            'latitude' => -7.7188,
            'longitude' => 109.0154,
            'address_detail' => $longAddressWithPhone,
            'title' => 'Pengujian alamat panjang dengan nomor HP di dalamnya',
            'description' => 'Memastikan sanitasi PII pada address_detail tidak memicu overflow VARCHAR(255).',
            'is_secret' => false,
            'photos' => [makeP0ValidJpeg('addr.jpg')],
        ], ['Accept' => 'application/json']);

    $response->assertStatus(201);

    $saved = Report::where('ticket_code', $response->json('data.ticket_code'))->firstOrFail();
    expect(mb_strlen((string) $saved->address_detail))->toBeLessThanOrEqual(255)
        ->and((string) $saved->address_detail)->toContain('[NO. HP DISENS');
});

it('enforces in-transaction ownership, tenant, status, and is_active guards against TOCTOU races across M03/M04/M05 (H-03)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $citizenService = app(CitizenReportService::class);
    $unitService = app(UnitReportService::class);
    $officerService = app(OfficerTaskService::class);
    $stateMachine = app(ReportStateMachineService::class);
    $policy = new ReportPolicy;

    $owner = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $supporter = User::create([
        'name' => 'Warga TOCTOU Uji',
        'email' => 'warga.toctou@pastara.id',
        'password' => 'Pastara2027!',
    ]);
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $adminDlh = User::where('email', 'admin.dlh@pastara.id')->firstOrFail();
    $officerDlh = User::where('email', 'petugas.dlh@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();

    $closedReport = Report::where('status', ReportStatus::VERIFIED_CLOSED->value)->firstOrFail();
    $secretReport = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $escrowReport = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $sada = Report::where('ticket_code', 'PST-SADA2027')->firstOrFail();
    $dupl = Report::where('ticket_code', 'PST-DUPL0001')->firstOrFail();

    // 1. CitizenReportService::upvoteReport rejects final, secret, self-owned, and deactivated actor inside TX
    expect(fn () => $citizenService->upvoteReport($closedReport, $supporter))->toThrow(AuthorizationException::class);
    expect(fn () => $citizenService->upvoteReport($secretReport, $supporter))->toThrow(AuthorizationException::class);
    expect(fn () => $citizenService->upvoteReport($escrowReport, $owner))->toThrow(AuthorizationException::class);

    // Simulate concurrent deactivation while stale $supporter object still has is_active = true
    User::whereKey($supporter->id)->update(['is_active' => false]);
    expect(fn () => $citizenService->upvoteReport($escrowReport, $supporter))->toThrow(AuthorizationException::class);

    // 2. UnitReportService::reassignOfficer & storeApbdMilestone reject cross-unit admin (404) & deactivated admin (403)
    $dpuprRunning = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::IN_PROGRESS->value)
        ->firstOrFail();
    $dpuprOfficer = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();

    expect(fn () => $unitService->reassignOfficer($dpuprRunning, $adminDlh, $dpuprOfficer->id, 'Coba lintas unit langsung'))
        ->toThrow(NotFoundHttpException::class);
    expect(fn () => $unitService->storeApbdMilestone($sada, $adminDlh, '2027-Q1', 'Coba milestone lintas unit langsung ke service.'))
        ->toThrow(NotFoundHttpException::class);

    // 3. OfficerTaskService::storeProgress rejects non-assigned officer (404) simulating concurrent reassignment
    expect(fn () => $officerService->storeProgress($dupl, $officerDishub, 'Coba tulis progres setelah tugas dialihkan ke petugas lain.'))
        ->toThrow(NotFoundHttpException::class);
    expect($dupl->auditLogs()->where('action_type', AuditActionType::PROGRESS_NOTE)->where('actor_user_id', $officerDishub->id)->exists())
        ->toBeFalse();

    // 4. Policy viewUnit & viewOfficerTask reject deactivated users
    $staleAdmin = clone $adminDpupr;
    $adminDpupr->is_active = false;
    expect($policy->viewUnit($adminDpupr, $sada)->denied())->toBeTrue();

    $staleOfficer = clone $officerDlh;
    $officerDlh->is_active = false;
    expect($policy->viewOfficerTask($officerDlh, $dupl)->denied())->toBeTrue();

    // 5. ReportStateMachineService re-fetches user active state from DB inside TX
    User::whereKey($staleAdmin->id)->update(['is_active' => false]);
    $dpuprRouted = Report::where('opd_unit_id', $staleAdmin->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();
    expect(fn () => $stateMachine->assignOperational($dpuprRouted, $staleAdmin, $dpuprOfficer))
        ->toThrow(AuthorizationException::class);

    User::whereKey($staleOfficer->id)->update(['is_active' => false]);
    expect(fn () => $officerService->storeProgress($dupl, $staleOfficer, 'Coba tulis progres memakai akun petugas nonaktif.'))
        ->toThrow(AuthorizationException::class);
});

it('rejects corrupt/polyglot JPEG documents in APBD scheduling and milestones with HTTP 422 instead of 500 (H-04)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $dpuprRouted = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();
    $sada = Report::where('ticket_code', 'PST-SADA2027')->firstOrFail();

    // 1. Corrupt JPEG on #25 schedule-apbd -> 422, status unchanged
    $this->actingAs($adminDpupr, 'sanctum')
        ->post("/api/v1/unit/reports/{$dpuprRouted->id}/schedule-apbd", [
            'fiscal_year' => 2027,
            'proposal_ref_number' => 'RKA-CORRUPT-01',
            'target_quarter' => 'Q3',
            'note' => 'Menguji penolakan berkas JPEG korup pada dokumen usulan APBD.',
            'document_proof' => makeP0CorruptJpeg('corrupt-rka.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['document_proof']]]);

    expect($dpuprRouted->fresh()->status)->toBe(ReportStatus::ROUTED_TO_UNIT);

    // 2. Corrupt JPEG attachment on #26 apbd-milestones -> 422
    $this->actingAs($adminDpupr, 'sanctum')
        ->post("/api/v1/unit/reports/{$sada->id}/apbd-milestones", [
            'quarter_label' => '2027-Q2',
            'progress_note' => 'Menguji penolakan lampiran JPEG korup pada pembaruan milestone APBD.',
            'attachment' => makeP0CorruptJpeg('corrupt-attachment.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['attachment']]]);

    // 3. Valid JPEG document on #25 schedule-apbd -> 200 and saved as .webp
    $okResponse = $this->actingAs($adminDpupr, 'sanctum')
        ->post("/api/v1/unit/reports/{$dpuprRouted->id}/schedule-apbd", [
            'fiscal_year' => 2027,
            'proposal_ref_number' => 'RKA-IMG-2027-02',
            'target_quarter' => 'Q3',
            'note' => 'Dokumen usulan APBD dalam format gambar JPEG sah yang dikonversi ke WebP.',
            'document_proof' => makeP0ValidJpeg('valid-rka.jpg'),
        ], ['Accept' => 'application/json']);

    $okResponse->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::BUDGET_SCHEDULED->value);

    $docPath = (string) $dpuprRouted->fresh()->budgetSchedule->document_proof_path;
    expect($docPath)->toEndWith('.webp');
    Storage::disk('public')->assertExists($docPath);
});

it('enforces WIB calendar date and strict Y-m-d format on APBD start-execution during 00:00-06:59 WIB (H-05)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    // Freeze server clock at 2026-11-22 18:00:00 UTC == 2026-11-23 01:00:00 WIB
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 18:00:00', 'UTC'));

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $sada = Report::where('ticket_code', 'PST-SADA2027')->firstOrFail();

    // 1. Same calendar day in WIB (2026-11-23) MUST be rejected with 422 even though UTC is still 2026-11-22
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$sada->id}/start-execution", [
            'execution_target_date' => '2026-11-23',
            'officer_id' => $officerDpupr->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['execution_target_date']]]);

    // 2. Offset-bearing ISO datetime string MUST be rejected with 422
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$sada->id}/start-execution", [
            'execution_target_date' => '2026-11-25T23:00:00Z',
            'officer_id' => $officerDpupr->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['execution_target_date']]]);

    // 3. Direct service call also rejects today WIB and ISO strings
    $stateMachine = app(ReportStateMachineService::class);
    expect(fn () => $stateMachine->startApbdExecution($sada, $adminDpupr, '2026-11-23', $officerDpupr))
        ->toThrow(ValidationException::class);
    expect(fn () => $stateMachine->startApbdExecution($sada, $adminDpupr, '2026-11-25T23:00:00Z', $officerDpupr))
        ->toThrow(ValidationException::class);

    // 4. Tomorrow in WIB (2026-11-24) succeeds with exact 23:59:59 WIB == 16:59:59 UTC deadline
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$sada->id}/start-execution", [
            'execution_target_date' => '2026-11-24',
            'officer_id' => $officerDpupr->id,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::IN_PROGRESS->value)
        ->assertJsonPath('data.sla.sla_deadline_at', '2026-11-24T16:59:59Z')
        ->assertJsonPath('data.budget_schedule.execution_target_date', '2026-11-24');
});

it('redacts internal staffing reason from public/citizen audit timeline on OFFICER_CHANGED while retaining it in metadata (H-06)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $secondOfficer = User::create([
        'name' => 'Petugas Pengganti Uji Privasi',
        'email' => 'petugas.privasi@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $adminDpupr->opd_unit_id,
        'is_active' => true,
    ]);

    $publicRunning = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('is_secret', false)
        ->where('status', ReportStatus::IN_PROGRESS->value)
        ->where('reporter_id', $warga->id)
        ->first();

    if ($publicRunning === null) {
        $publicRunning = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
            ->where('is_secret', false)
            ->where('status', ReportStatus::IN_PROGRESS->value)
            ->firstOrFail();
        $publicRunning->update(['reporter_id' => $warga->id]);
    }

    $sensitiveReason = 'Petugas Budi Santoso sedang menjalani evaluasi disiplin SDM internal.';

    $this->actingAs($adminDpupr, 'sanctum')
        ->patchJson("/api/v1/unit/reports/{$publicRunning->id}/reassign-officer", [
            'officer_id' => $secondOfficer->id,
            'reason' => $sensitiveReason,
        ])
        ->assertStatus(200);

    // Public detail shows generic safe note and never leaks internal HR reason
    $publicDetail = $this->getJson("/api/v1/public/reports/{$publicRunning->ticket_code}");
    $publicDetail->assertStatus(200);
    expect($publicDetail->getContent())
        ->not->toContain('Budi Santoso')
        ->not->toContain('evaluasi disiplin');

    $publicAudit = collect($publicDetail->json('data.audit_timeline'))
        ->firstWhere('action_type', AuditActionType::OFFICER_CHANGED->value);
    expect($publicAudit)->not->toBeNull()
        ->and($publicAudit['note'])->toBe('Penugasan lapangan dialihkan ke petugas lain oleh Admin Unit.')
        ->and(array_key_exists('metadata', $publicAudit))->toBeFalse();

    // Citizen reports list also never leaks internal HR reason
    $citizenList = $this->actingAs($warga, 'sanctum')->getJson('/api/v1/citizen/reports?tab=mine');
    $citizenList->assertStatus(200);
    expect($citizenList->getContent())
        ->not->toContain('Budi Santoso')
        ->not->toContain('evaluasi disiplin');

    // Internal immutable audit_logs.metadata preserves the sanitized reason for Super Admin / Inspektorat audit
    $auditRow = $publicRunning->auditLogs()
        ->where('action_type', AuditActionType::OFFICER_CHANGED->value)
        ->latest('id')
        ->firstOrFail();
    expect($auditRow->metadata['internal_reason'] ?? null)->toBe($sensitiveReason);
});
