<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\DisputeStatus;
use App\Enums\NotificationType;
use App\Enums\PhotoType;
use App\Enums\ReportStatus;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
    CarbonImmutable::setTestNow();
});

function makeSignOffJpeg(string $name = 'veto.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(120, 90);
    $bg = imagecolorallocate($img, 180, 70, 60);
    imagefill($img, 0, 0, $bg);
    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('approves Escrow sign-off (TR-08), blocks internal roles with ESCROW_LOCK_VIOLATION (TC-SEC-01), non-owner (403), and expired window (409) (TASK-BE-044, TC-F-13)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $owner = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $otherWarga = User::create([
        'name' => 'Warga Lain M06',
        'email' => 'warga.lain.m06@pastara.id',
        'password' => 'Pastara2027!',
    ]);
    $adminDishub = User::where('email', 'admin.dishub@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();

    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();

    // 1. Unknown ticket_code -> 404
    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-TIDAKADA/sign-off/approve')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // 2. Unauthenticated without token & non-owner WARGA -> 403 FORBIDDEN
    Auth::forgetGuards();
    $this->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve', ['secret_token' => ['array-attack']])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->actingAs($otherWarga, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // 3. TC-SEC-01 (Hukum 1): Internal roles attempting unilateral approve MUST receive 403 ESCROW_LOCK_VIOLATION
    foreach ([$adminDishub, $officerDishub, $superAdmin] as $internalActor) {
        $this->actingAs($internalActor, 'sanctum')
            ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ESCROW_LOCK_VIOLATION');
    }

    // 4. Expired 72h sign-off window -> 409 INVALID_STATE_TRANSITION
    $originalDeadline = $escrow->signoff_deadline_at;
    $escrow->update(['signoff_deadline_at' => CarbonImmutable::now('UTC')->subMinute()]);

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    // Restore valid sign-off deadline
    $escrow->update(['signoff_deadline_at' => $originalDeadline ?? CarbonImmutable::now('UTC')->addHours(48)]);

    // 5. Valid owner Bearer approve -> 200 VERIFIED_CLOSED, SLA stopped, audit + REPORT_CLOSED notification
    $approveRes = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve');

    $approveRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ReportStatus::VERIFIED_CLOSED->value)
        ->assertJsonPath('data.sla.is_sla_overdue', false);

    $escrow->refresh();
    expect($escrow->sla_stopped_at)->not->toBeNull()
        ->and($escrow->auditLogs()->where('action_type', AuditActionType::CITIZEN_APPROVED)->exists())->toBeTrue()
        ->and(Notification::where('report_id', $escrow->id)->where('type', NotificationType::REPORT_CLOSED->value)->exists())->toBeTrue();

    // 6. Re-approving already VERIFIED_CLOSED ticket -> 409
    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
});

it('rejects/vetoes Escrow claim with counter-evidence photos via Bearer and anonymous secret_token without leaking secret reporter (TASK-BE-044, TC-F-13, D-02)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $owner = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $otherWarga = User::create([
        'name' => 'Warga Bukan Pelapor',
        'email' => 'warga.bukan.pelapor@pastara.id',
        'password' => 'Pastara2027!',
    ]);

    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();

    // 1. Non-owner attempting veto -> 403 BEFORE body validation
    $this->actingAs($otherWarga, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/reject', [])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // 2. Owner attempting veto WITHOUT photos -> 422 VALIDATION_ERROR (TC-F-13)
    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/reject', [
            'reason' => 'Lampu jalan masih mati total pada malam hari dan belum menyala.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['photos']]]);

    // 3. Spoofed PHP file in veto photos -> 422
    $fakePhp = UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo "hack"; ?>');
    $this->actingAs($owner, 'sanctum')
        ->post('/api/v1/reports/PST-ESCROW01/sign-off/reject', [
            'reason' => 'Lampu jalan masih mati total pada malam hari dan belum menyala.',
            'photos' => [$fakePhp],
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 4. Secret ticket veto via secret_token ONLY (NO Bearer token — D-02)
    Auth::forgetGuards();
    $secret = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $secret->update([
        'status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
        'signoff_started_at' => CarbonImmutable::now('UTC'),
        'signoff_deadline_at' => CarbonImmutable::now('UTC')->addHours(72),
    ]);

    // Wrong secret_token -> 403
    $this->post('/api/v1/reports/PST-PURW2027/sign-off/reject', [
        'secret_token' => 'TKN-XXXX-0000',
        'reason' => 'Tindak lanjut belum menyentuh akar masalah di lapangan sama sekali.',
        'photos' => [makeSignOffJpeg('wrong.jpg')],
    ], ['Accept' => 'application/json'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // Valid secret_token (TKN-DEMO-2027) without Bearer -> 200 DISPUTED + Dispute created + masked reporter
    $vetoRes = $this->post('/api/v1/reports/PST-PURW2027/sign-off/reject', [
        'secret_token' => 'TKN-DEMO-2027',
        'reason' => 'Kondisi fasilitas masih rusak dan belum diperbaiki. Hubungi 081234567890 bila perlu bukti tambahan.',
        'photos' => [makeSignOffJpeg('veto-secret.jpg')],
    ], ['Accept' => 'application/json']);

    $vetoRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ReportStatus::DISPUTED->value)
        ->assertJsonPath('data.reporter.name', 'Pelapor Rahasia')
        ->assertJsonMissingPath('data.reporter.email')
        ->assertJsonMissingPath('data.reporter.phone');

    $secret->refresh();
    $dispute = $secret->disputes()->latest('id')->firstOrFail();
    $disputePhoto = $secret->photos()->where('photo_type', PhotoType::DISPUTE->value)->latest('id')->firstOrFail();

    expect($dispute->status)->toBe(DisputeStatus::PENDING)
        ->and($dispute->citizen_reason)->toContain('[NO. HP DISENSOR]')
        ->and($secret->auditLogs()->where('action_type', AuditActionType::CITIZEN_REJECTED)->exists())->toBeTrue()
        ->and(Notification::where('report_id', $secret->id)->where('type', NotificationType::DISPUTE_RAISED->value)->exists())->toBeTrue();

    Storage::disk('public')->assertExists($disputePhoto->file_path);
    Storage::disk('public')->assertExists($disputePhoto->thumbnail_path);
});

it('records micro-rating once on VERIFIED_CLOSED, rejects duplicates (409) & non-closed (409), supports secret_token, and refreshes OPD quality index (TASK-BE-045, TC-F-17)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $owner = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $otherWarga = User::create([
        'name' => 'Warga Lain Rating',
        'email' => 'warga.rating.other@pastara.id',
        'password' => 'Pastara2027!',
    ]);

    // 1. Rating a PENDING_CITIZEN_SIGN_OFF report -> 409
    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/rating', [
            'speed_score' => 5,
            'physical_quality_score' => 5,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    // Approve PST-ESCROW01 first -> VERIFIED_CLOSED
    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve')
        ->assertStatus(200);

    // 2. Non-owner attempting rating -> 403
    $this->actingAs($otherWarga, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/rating', [
            'speed_score' => 4,
            'physical_quality_score' => 4,
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // 3. Invalid score range -> 422
    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/rating', [
            'speed_score' => 6,
            'physical_quality_score' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['speed_score', 'physical_quality_score']]]);

    // 4. First valid rating -> 201 Created, comment sanitized, RATED audit logged, OPD cached_quality_index updated
    $rateRes = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/rating', [
            'speed_score' => 5,
            'physical_quality_score' => 4,
            'comment' => 'Lampu PJU sudah menyala terang kembali. Terima kasih, kontak saya 081234567890.',
        ]);

    $rateRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.ticket_code', 'PST-ESCROW01')
        ->assertJsonPath('data.speed_score', 5)
        ->assertJsonPath('data.physical_quality_score', 4);

    expect((string) $rateRes->json('data.comment'))->toContain('[NO. HP DISENSOR]')
        ->and($rateRes->json('data.unit_quality_index'))->toBeNumeric();

    $unit = OpdUnit::findOrFail($escrow->opd_unit_id);
    expect((float) $unit->cached_quality_index)->toBe((float) $rateRes->json('data.unit_quality_index'))
        ->and($escrow->auditLogs()->where('action_type', AuditActionType::RATED)->exists())->toBeTrue();

    // 5. TC-F-17: Duplicate rating on same ticket -> 409 Conflict
    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/rating', [
            'speed_score' => 5,
            'physical_quality_score' => 5,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    // 6. Rating AUTO_VERIFIED_CLOSED ticket -> 409 (BR-023: strictly VERIFIED_CLOSED only)
    $autoClosed = Report::where('status', ReportStatus::AUTO_VERIFIED_CLOSED->value)->first();
    if ($autoClosed !== null) {
        $autoClosed->update(['reporter_id' => $owner->id]);
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/reports/{$autoClosed->ticket_code}/rating", [
                'speed_score' => 4,
                'physical_quality_score' => 4,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    // 7. Rating a VERIFIED_CLOSED secret ticket via secret_token ONLY (without Bearer) -> 201 Created
    Auth::forgetGuards();
    $secret = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $secret->update([
        'status' => ReportStatus::VERIFIED_CLOSED,
        'sla_stopped_at' => CarbonImmutable::now('UTC'),
    ]);

    $this->postJson('/api/v1/reports/PST-PURW2027/rating', [
        'secret_token' => 'TKN-DEMO-2027',
        'speed_score' => 5,
        'physical_quality_score' => 5,
        'comment' => 'Penanganan laporan rahasia cepat dan aman.',
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.ticket_code', 'PST-PURW2027')
        ->assertJsonPath('data.physical_quality_score', 5);
});
