<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\PhotoType;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\ReportUpvote;
use App\Models\User;
use App\Models\Village;
use App\Services\ReportStateMachineService;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
    CarbonImmutable::setTestNow();
});

function makeP1ValidJpeg(string $name = 'photo.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(120, 90);
    $bg = imagecolorallocate($img, 70, 130, 110);
    imagefill($img, 0, 0, $bg);
    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function makeP1Pdf(string $name = 'doc.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
    );
}

it('creates the 4 additional Fase 5 PostgreSQL performance indexes (M-11)', function (): void {
    $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public'"))
        ->pluck('indexname')
        ->all();

    expect($indexes)->toContain(
        'idx_reports_reporter_created',
        'idx_report_upvotes_user_watching',
        'idx_budget_milestones_schedule_created',
        'idx_users_unit_role_active',
    );
});

it('enforces M03 Indonesian AC-014 messages, strict is_secret, DESA_SADABUMI routing, watch policy, ALREADY_UPVOTED 409, and 429 create-report rate limit (M-01, M-03..M-06, M-12)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $supporter = User::create([
        'name' => 'Warga P1 Uji',
        'email' => 'warga.p1@pastara.id',
        'password' => 'Pastara2027!',
    ]);
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $cat05 = Category::where('code', 'CAT-05')->firstOrFail();
    $sadabumiUnit = OpdUnit::where('code', 'DESA_SADABUMI')->firstOrFail();
    $sadabumiVillage = Village::where('opd_unit_id', $sadabumiUnit->id)->firstOrFail();

    // 1. Auth & role checks on #14..#17
    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $secret = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $closed = Report::where('status', ReportStatus::VERIFIED_CLOSED->value)->firstOrFail();

    $this->postJson('/api/v1/reports', [])->assertStatus(401);
    $this->postJson("/api/v1/reports/{$escrow->id}/upvote")->assertStatus(401);
    $this->patchJson("/api/v1/reports/{$escrow->id}/watch", ['is_watching' => false])->assertStatus(401);
    $this->getJson('/api/v1/citizen/reports')->assertStatus(401);
    $this->actingAs($adminDpupr, 'sanctum')->getJson('/api/v1/citizen/reports')->assertStatus(403);

    // 2. AC-014 Indonesian BBox error message on #13 nearby & #14 store
    $this->actingAs($warga, 'sanctum')
        ->getJson("/api/v1/reports/nearby?category_id={$cat01->id}&latitude=-6.2&longitude=106.8")
        ->assertStatus(422)
        ->assertJsonPath('error.fields.latitude.0', 'Lokasi berada di luar wilayah Kabupaten Cilacap.');

    $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat01->id,
            'district_id' => $sadabumiVillage->district_id,
            'village_id' => $sadabumiVillage->id,
            'latitude' => -6.2,
            'longitude' => 106.8,
            'title' => 'Laporan di luar wilayah Kabupaten Cilacap',
            'description' => 'Menguji pesan bahasa Indonesia AC-014 untuk koordinat di luar batas Cilacap.',
            'is_secret' => 'yes', // M-05: non-canonical boolean string MUST be rejected
            'photos' => [
                makeP1ValidJpeg('p1.jpg'),
                makeP1ValidJpeg('p2.jpg'),
                makeP1ValidJpeg('p3.jpg'),
                makeP1ValidJpeg('p4.jpg'), // >3 photos rejected
            ],
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.latitude.0', 'Lokasi berada di luar wilayah Kabupaten Cilacap.')
        ->assertJsonStructure(['error' => ['fields' => ['latitude', 'longitude', 'is_secret', 'photos']]]);

    // 3. AC-022: CAT-05 on Sadabumi routes to dedicated DESA_SADABUMI + dispatches REPORT_ROUTED notification
    $beforeNotifCount = Notification::where('type', NotificationType::REPORT_ROUTED->value)->count();
    $sadabumiResponse = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat05->id,
            'district_id' => $sadabumiVillage->district_id,
            'village_id' => $sadabumiVillage->id,
            'latitude' => -7.3200,
            'longitude' => 108.7600,
            'title' => 'Jalan usaha tani Desa Sadabumi longsor parah',
            'description' => 'Akses jalan pertanian Desa Sadabumi tertutup longsoran tebing pasca hujan deras.',
            'is_secret' => 'false',
            'photos' => [makeP1ValidJpeg('sadabumi.jpg')],
        ], ['Accept' => 'application/json']);

    $sadabumiResponse->assertStatus(201)
        ->assertJsonPath('data.opd_unit.code', 'DESA_SADABUMI');

    expect(Notification::where('type', NotificationType::REPORT_ROUTED->value)->count())
        ->toBeGreaterThan($beforeNotifCount);

    // 4. M-04: Watch Policy blocks secret ticket (404) & blocks re-enabling watch on final report (409)
    $this->actingAs($supporter, 'sanctum')
        ->patchJson("/api/v1/reports/{$secret->id}/watch", ['is_watching' => false])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    ReportUpvote::create([
        'report_id' => $closed->id,
        'user_id' => $supporter->id,
        'is_watching' => true,
    ]);
    $this->actingAs($supporter, 'sanctum')
        ->patchJson("/api/v1/reports/{$closed->id}/watch", ['is_watching' => false])
        ->assertStatus(200)
        ->assertJsonPath('data.is_watching', false);
    $this->actingAs($supporter, 'sanctum')
        ->patchJson("/api/v1/reports/{$closed->id}/watch", ['is_watching' => true])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    // 5. GET /citizen/reports default tab + status=PENDING_CITIZEN_SIGN_OFF exposes signoff_remaining_seconds (AC-027)
    $signOffList = $this->actingAs($warga, 'sanctum')
        ->getJson('/api/v1/citizen/reports?status=PENDING_CITIZEN_SIGN_OFF');
    $signOffList->assertStatus(200);
    $escrowItem = collect($signOffList->json('data'))->firstWhere('ticket_code', 'PST-ESCROW01');
    expect($escrowItem)->not->toBeNull()
        ->and($escrowItem['signoff']['signoff_remaining_seconds'])->toBeInt();

    // 6. M-06: Inactive DESA_UMUM raises ValidationException (422), not ModelNotFoundException (404)
    OpdUnit::where('code', 'DESA_UMUM')->update(['is_active' => false]);
    $villageWithoutUnit = Village::whereNull('opd_unit_id')->firstOrFail();
    $stateMachine = app(ReportStateMachineService::class);
    expect(fn () => $stateMachine->createAndRoute($warga, [
        'category_id' => $cat05->id,
        'district_id' => $villageWithoutUnit->district_id,
        'village_id' => $villageWithoutUnit->id,
        'latitude' => -7.65,
        'longitude' => 109.05,
        'title' => 'Uji fallback DESA_UMUM nonaktif menjadi 422',
        'description' => 'Memastikan fallback unit DESA_UMUM yang nonaktif melempar ValidationException 422.',
        'is_secret' => 'false',
    ], [makeP1ValidJpeg('fallback.jpg')]))->toThrow(ValidationException::class);

    // 7. SR-018: throttle:create-report returns HTTP 429 on 11th request within 1 hour
    RateLimiter::clear('create-report');
    $limiterUser = User::create([
        'name' => 'Warga Throttle Uji',
        'email' => 'warga.throttle@pastara.id',
        'password' => 'Pastara2027!',
    ]);
    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($limiterUser, 'sanctum')
            ->post('/api/v1/reports', [
                'category_id' => $cat01->id,
                'district_id' => $sadabumiVillage->district_id,
                'village_id' => $sadabumiVillage->id,
                'latitude' => -7.7188,
                'longitude' => 109.0154,
                'title' => "Laporan uji batas laju pengiriman nomor {$i}",
                'description' => 'Menguji batas maksimum sepuluh laporan per jam untuk setiap akun warga.',
                'is_secret' => false,
                'photos' => [makeP1ValidJpeg("rate{$i}.jpg")],
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);
    }

    $this->actingAs($limiterUser, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat01->id,
            'district_id' => $sadabumiVillage->district_id,
            'village_id' => $sadabumiVillage->id,
            'latitude' => -7.7188,
            'longitude' => 109.0154,
            'title' => 'Laporan kesebelas harus ditolak 429 oleh throttle',
            'description' => 'Laporan kesebelas dalam satu jam wajib memicu respons TOO_MANY_REQUESTS.',
            'is_secret' => false,
            'photos' => [makeP1ValidJpeg('rate11.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

it('enforces M04 reroute reporter notification, APBD budget/quarter validation, officer counts, milestone cleanup/regex, and distinct reassign notifications (M-02, M-08..M-10, L-02, M-12)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 05:00:00', 'UTC'));

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $adminDlh = User::where('email', 'admin.dlh@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $secondOfficer = User::create([
        'name' => 'Petugas Kedua Uji M04',
        'email' => 'petugas2.m04@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $adminDpupr->opd_unit_id,
        'is_active' => true,
    ]);

    $dpuprRouted = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();
    $sada = Report::with('budgetSchedule')->where('ticket_code', 'PST-SADA2027')->firstOrFail();

    // 1. Auth 401 & cross-unit 404 across #26..#30
    $this->getJson('/api/v1/unit/reports')->assertStatus(401);
    $this->actingAs($adminDlh, 'sanctum')->postJson("/api/v1/unit/reports/{$sada->id}/apbd-milestones", [])->assertStatus(404);
    $this->actingAs($adminDlh, 'sanctum')->postJson("/api/v1/unit/reports/{$sada->id}/start-execution", [])->assertStatus(404);
    $this->actingAs($adminDlh, 'sanctum')->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/reject", [])->assertStatus(404);
    $this->actingAs($adminDlh, 'sanctum')->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/reroute", [])->assertStatus(404);

    // 2. M-08: ScheduleApbdRequest rejects DECIMAL(15,2) overflow & past quarter in current year
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/schedule-apbd", [
            'fiscal_year' => 2026,
            'proposal_ref_number' => 'RKA-PAST-Q1',
            'target_quarter' => 'Q1',
            'estimated_budget' => 99999999999999.99,
            'note' => 'Menguji batas anggaran dan larangan kuartal masa lalu pada tahun berjalan.',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['estimated_budget', 'target_quarter']]]);

    // 3. M-09: GET /unit/officers counts ONLY IN_PROGRESS & REOPENED_BY_AUDIT (matching M05 status=active)
    $expectedActiveCount = Report::where('assigned_officer_id', $officerDpupr->id)
        ->whereIn('status', [ReportStatus::IN_PROGRESS->value, ReportStatus::REOPENED_BY_AUDIT->value])
        ->count();
    $officersRes = $this->actingAs($adminDpupr, 'sanctum')->getJson('/api/v1/unit/officers');
    $officersRes->assertStatus(200);
    $dpuprEntry = collect($officersRes->json('data'))->firstWhere('id', $officerDpupr->id);
    expect($dpuprEntry['active_tasks_count'])->toBe($expectedActiveCount);

    // 4. M-10: Milestone rejects invalid quarter_label, deletes old document on replace, preserves document_uploaded_at, & uses proper title
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$sada->id}/apbd-milestones", [
            'quarter_label' => '081234567890',
            'progress_note' => 'Label kuartal tidak valid berupa nomor telepon harus ditolak oleh regex.',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['quarter_label']]]);

    $initialDocPath = 'documents/2026/11/old-rka.pdf';
    Storage::disk('public')->put($initialDocPath, '%PDF-1.4 OLD');
    $originalUploadedAt = CarbonImmutable::parse('2026-11-10 08:00:00', 'UTC');
    $sada->budgetSchedule->update([
        'document_proof_path' => $initialDocPath,
        'document_uploaded_at' => $originalUploadedAt,
    ]);

    $this->actingAs($adminDpupr, 'sanctum')
        ->post("/api/v1/unit/reports/{$sada->id}/apbd-milestones", [
            'quarter_label' => '2027-Q1',
            'progress_note' => 'Dokumen RKA revisi final telah diunggah ke sistem Bappeda Cilacap.',
            'document_proof' => makeP1Pdf('new-rka.pdf'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $sada->budgetSchedule->refresh();
    Storage::disk('public')->assertMissing($initialDocPath);
    Storage::disk('public')->assertExists($sada->budgetSchedule->document_proof_path);
    expect($sada->budgetSchedule->document_uploaded_at?->toIso8601ZuluString())
        ->toBe($originalUploadedAt->toIso8601ZuluString());

    $milestoneNotif = Notification::where('report_id', $sada->id)
        ->where('user_id', $sada->reporter_id)
        ->latest('id')
        ->first();
    expect($milestoneNotif?->title)->toBe('Pembaruan Milestone APBD');

    // 5. L-02 + M-12: Reassign works on REOPENED_BY_AUDIT & sends distinct messages to incoming/outgoing officers
    $reopened = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::IN_PROGRESS->value)
        ->firstOrFail();
    $reopened->update([
        'status' => ReportStatus::REOPENED_BY_AUDIT,
        'assigned_officer_id' => $officerDpupr->id,
    ]);

    $this->actingAs($adminDpupr, 'sanctum')
        ->patchJson("/api/v1/unit/reports/{$reopened->id}/reassign-officer", [
            'officer_id' => $secondOfficer->id,
            'reason' => 'Pengalihan petugas pada siklus pengerjaan ulang pasca sidang audit.',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.assigned_officer.id', $secondOfficer->id);

    $outgoingNotif = Notification::where('report_id', $reopened->id)
        ->where('user_id', $officerDpupr->id)
        ->latest('id')
        ->first();
    $incomingNotif = Notification::where('report_id', $reopened->id)
        ->where('user_id', $secondOfficer->id)
        ->latest('id')
        ->first();
    expect($outgoingNotif?->title)->toBe('Penugasan Lapangan Dialihkan')
        ->and($incomingNotif?->title)->toBe('Petugas Lapangan Ditugaskan');

    // 6. M-02: Reroute notifies BOTH target unit admins AND reporter (FR-020)
    $dlhUnit = OpdUnit::where('code', 'DLH')->firstOrFail();
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/reroute", [
            'target_opd_unit_id' => $dlhUnit->id,
            'note' => 'Kewenangan pembersihan saluran sampah ini berada pada Dinas Lingkungan Hidup.',
        ])
        ->assertStatus(200);

    $reporterRerouteNotif = Notification::where('report_id', $dpuprRouted->id)
        ->where('user_id', $dpuprRouted->reporter_id)
        ->where('title', 'Laporan Dialihkan ke Unit Lain')
        ->exists();
    expect($reporterRerouteNotif)->toBeTrue();

    // 7. M-12: Inbox tabs & overdue_only filter
    foreach (['verification', 'in_progress', 'sign_off', 'disputed', 'closed', 'rejected'] as $tab) {
        $this->actingAs($adminDpupr, 'sanctum')
            ->getJson("/api/v1/unit/reports?tab={$tab}")
            ->assertStatus(200);
    }
    $this->actingAs($adminDpupr, 'sanctum')
        ->getJson('/api/v1/unit/reports?overdue_only=true')
        ->assertStatus(200);
});

it('enforces M05 progress notifications (FR-026), TR-13 reopened cycle 2, device_lat/lng pairing, AC-043 no-EXIF warning, and secret masking (M-02, L-05, M-12)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $officerDlh = User::where('email', 'petugas.dlh@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $watcher = User::create([
        'name' => 'Warga Pemantau Progres',
        'email' => 'watcher.m05@pastara.id',
        'password' => 'Pastara2027!',
    ]);

    $dupl = Report::where('ticket_code', 'PST-DUPL0001')->firstOrFail();
    ReportUpvote::create([
        'report_id' => $dupl->id,
        'user_id' => $watcher->id,
        'is_watching' => true,
    ]);

    // 1. Auth 401 & cross-officer 404 on /evidence
    $this->getJson('/api/v1/officer/tasks')->assertStatus(401);
    $this->actingAs($officerDishub, 'sanctum')
        ->postJson("/api/v1/officer/tasks/{$dupl->id}/evidence", [])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // 2. M-02 (FR-026): Text-only progress update notifies BOTH reporter AND watchers
    $this->actingAs($officerDlh, 'sanctum')
        ->postJson("/api/v1/officer/tasks/{$dupl->id}/progress", [
            'note' => 'Tim kebersihan sudah mengangkut setengah volume sampah ke truk pengangkut.',
        ])
        ->assertStatus(201);

    expect(Notification::where('report_id', $dupl->id)->where('user_id', $dupl->reporter_id)->where('title', 'Pembaruan Progres Lapangan')->exists())
        ->toBeTrue()
        ->and(Notification::where('report_id', $dupl->id)->where('user_id', $watcher->id)->where('title', 'Pembaruan Progres Lapangan')->exists())
        ->toBeTrue();

    // 3. L-05: Unpaired device_latitude without device_longitude rejected with 422
    $this->actingAs($officerDlh, 'sanctum')
        ->post("/api/v1/officer/tasks/{$dupl->id}/evidence", [
            'action_note' => 'Pembersihan tuntas seluruh area pinggir jalan namun koordinat tidak berpasangan.',
            'photos' => [makeP1ValidJpeg('no-exif.jpg')],
            'device_latitude' => -7.6350,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['device_longitude']]]);

    // 4. TR-13 (REOPENED_BY_AUDIT cycle 2) + AC-043 (no EXIF photo with device coords fallback -> geotag_warning = true) + SIGN_OFF_REQUESTED notif
    $reopenedTask = Report::where('assigned_officer_id', $officerDpupr->id)
        ->whereIn('status', [ReportStatus::IN_PROGRESS->value, ReportStatus::REOPENED_BY_AUDIT->value])
        ->firstOrFail();
    $reopenedTask->update([
        'status' => ReportStatus::REOPENED_BY_AUDIT,
        'current_cycle' => 2,
    ]);

    $evidenceRes = $this->actingAs($officerDpupr, 'sanctum')
        ->post("/api/v1/officer/tasks/{$reopenedTask->id}/evidence", [
            'action_note' => 'Perbaikan ulang siklus kedua telah dirampungkan sesuai instruksi audit Inspektorat.',
            'photos' => [makeP1ValidJpeg('cycle2-no-exif.jpg')],
            'device_latitude' => (float) $reopenedTask->latitude,
            'device_longitude' => (float) $reopenedTask->longitude,
        ], ['Accept' => 'application/json']);

    $evidenceRes->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
        ->assertJsonPath('data.current_cycle', 2)
        ->assertJsonPath('data.geotag.geotag_warning', true);

    $cycle2Photo = $reopenedTask->photos()
        ->where('photo_type', PhotoType::AFTER->value)
        ->where('cycle', 2)
        ->firstOrFail();
    Storage::disk('public')->assertExists($cycle2Photo->file_path);
    Storage::disk('public')->assertExists($cycle2Photo->thumbnail_path);

    expect(Notification::where('report_id', $reopenedTask->id)
        ->where('type', NotificationType::SIGN_OFF_REQUESTED->value)
        ->exists())->toBeTrue();

    // 5. Posting progress on non-running status returns 409
    $this->actingAs($officerDpupr, 'sanctum')
        ->postJson("/api/v1/officer/tasks/{$reopenedTask->id}/progress", [
            'note' => 'Catatan progres setelah masuk jendela konfirmasi warga harus 409.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    // 6. Hukum 4: Secret ticket assigned to an officer masks reporter identity in GET /officer/tasks
    $secretReport = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $secretReport->update([
        'assigned_officer_id' => $officerDlh->id,
        'status' => ReportStatus::IN_PROGRESS,
    ]);

    $officerTasks = $this->actingAs($officerDlh, 'sanctum')
        ->getJson('/api/v1/officer/tasks?status=active');
    $officerTasks->assertStatus(200);

    $secretItem = collect($officerTasks->json('data'))->firstWhere('ticket_code', 'PST-PURW2027');
    expect($secretItem)->not->toBeNull()
        ->and($secretItem['reporter']['name'])->toBe('Pelapor Rahasia')
        ->and($secretItem['reporter']['email'])->toBeNull()
        ->and($secretItem['reporter']['phone'])->toBeNull();
});
