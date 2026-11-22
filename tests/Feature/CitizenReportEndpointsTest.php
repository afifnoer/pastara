<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

function makeCitizenJpegUpload(string $name = 'evidence.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(120, 90);
    $bg = imagecolorallocate($img, 100, 120, 140);
    imagefill($img, 0, 0, $bg);
    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('detects nearby duplicate PST-DUPL0001 within 135m and enforces WARGA role & Cilacap BBox (TASK-BE-034, AC-016..AC-018)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $admin = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $cat03 = Category::where('code', 'CAT-03')->firstOrFail();

    // Unauthenticated -> 401
    $this->getJson('/api/v1/reports/nearby')->assertStatus(401);

    // Non-WARGA role -> 403
    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/reports/nearby?'.http_build_query([
            'category_id' => $cat03->id,
            'latitude' => -7.6362,
            'longitude' => 109.0180,
        ]))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // Outside Cilacap BBox -> 422
    $this->actingAs($warga, 'sanctum')
        ->getJson('/api/v1/reports/nearby?'.http_build_query([
            'category_id' => $cat03->id,
            'latitude' => -6.2000,
            'longitude' => 106.8167,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // ~133 meters from PST-DUPL0001 (-7.6350, 109.0180)
    $response = $this->actingAs($warga, 'sanctum')
        ->getJson('/api/v1/reports/nearby?'.http_build_query([
            'category_id' => $cat03->id,
            'latitude' => -7.6362,
            'longitude' => 109.0180,
        ]));

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.radius_m', 200);

    $matchCodes = collect($response->json('data.matches'))->pluck('ticket_code')->all();
    expect($matchCodes)->toContain('PST-DUPL0001');

    $duplMatch = collect($response->json('data.matches'))->firstWhere('ticket_code', 'PST-DUPL0001');
    expect($duplMatch['distance_m'])->toBeBetween(125, 145);
});

it('creates standard, village-routed, and secret reports with PII sanitization and validation guards (TASK-BE-035, TC-F-03, TC-F-04, TC-F-08)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $cat05 = Category::where('code', 'CAT-05')->firstOrFail();
    $cat06 = Category::where('code', 'CAT-06')->firstOrFail();
    $villageWithUnit = Village::query()->whereNotNull('opd_unit_id')->firstOrFail();
    $villageWithoutUnit = Village::query()->whereNull('opd_unit_id')->firstOrFail();
    $otherDistrictVillage = Village::query()
        ->where('district_id', '!=', $villageWithUnit->district_id)
        ->firstOrFail();

    // 1. TC-F-03: Standard report auto-routed to DPUPR + PII sanitized + WebP saved
    $standardResponse = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat01->id,
            'district_id' => $villageWithUnit->district_id,
            'village_id' => $villageWithUnit->id,
            'latitude' => -7.7188,
            'longitude' => 109.0154,
            'address_detail' => 'Depan rumah NIK 3301120101900001',
            'title' => 'Jalan berlubang parah di pusat kecamatan',
            'description' => 'Lubang jalan sangat dalam dan membahayakan pengendara motor. Hubungi 081234567890 untuk verifikasi.',
            'is_secret' => 'false',
            'photos' => [makeCitizenJpegUpload('before1.jpg')],
        ], ['Accept' => 'application/json']);

    $standardResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ReportStatus::ROUTED_TO_UNIT->value)
        ->assertJsonPath('data.opd_unit.code', 'DPUPR')
        ->assertJsonPath('data.is_secret', false)
        ->assertJsonMissingPath('data.secret_token');

    $ticketCode = (string) $standardResponse->json('data.ticket_code');
    $savedReport = Report::with('photos', 'auditLogs')->where('ticket_code', $ticketCode)->firstOrFail();

    expect($savedReport->description_sanitized)->toContain('[NO. HP DISENSOR]')
        ->and($savedReport->address_detail)->toContain('[NIK DISENSOR]')
        ->and($savedReport->photos)->toHaveCount(1)
        ->and($savedReport->auditLogs)->toHaveCount(2);
    Storage::disk('public')->assertExists($savedReport->photos[0]->file_path);
    Storage::disk('public')->assertExists($savedReport->photos[0]->thumbnail_path);

    // 2. TC-F-04: Village-routed category falls back to DESA_UMUM when village has no dedicated unit
    $fallbackResponse = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat05->id,
            'district_id' => $villageWithoutUnit->district_id,
            'village_id' => $villageWithoutUnit->id,
            'latitude' => -7.6500,
            'longitude' => 109.0500,
            'title' => 'Saluran air desa tersumbat lumpur tebal',
            'description' => 'Drainase jalan desa mampet total sehingga menyebabkan genangan di rumah warga setiap hujan.',
            'is_secret' => false,
            'photos' => [makeCitizenJpegUpload('desa.jpg')],
        ], ['Accept' => 'application/json']);

    $fallbackResponse->assertStatus(201)
        ->assertJsonPath('data.opd_unit.code', 'DESA_UMUM');

    // 3. TC-F-08: Secret report returns one-time secret_token, hides from public detail, unlocks via /public/track
    $secretResponse = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat06->id,
            'district_id' => $villageWithUnit->district_id,
            'village_id' => $villageWithUnit->id,
            'latitude' => -7.7100,
            'longitude' => 109.0200,
            'title' => 'Laporan dugaan pungutan liar di fasilitas publik',
            'description' => 'Mohon penelusuran tertutup terkait indikasi pungutan tidak resmi oleh oknum petugas layanan.',
            'is_secret' => 'true',
            'photos' => [makeCitizenJpegUpload('secret.jpg')],
        ], ['Accept' => 'application/json']);

    $secretResponse->assertStatus(201)
        ->assertJsonPath('data.is_secret', true)
        ->assertJsonPath('data.opd_unit.code', 'DISDIKBUD');

    $secretCode = (string) $secretResponse->json('data.ticket_code');
    $secretToken = (string) $secretResponse->json('data.secret_token');

    expect($secretToken)->toMatch('/^TKN-[A-Z0-9]{4}-[A-Z0-9]{4}$/');

    // Hidden from public detail (404), unlocked via /public/track with returned token
    $this->getJson("/api/v1/public/reports/{$secretCode}")->assertStatus(404);
    $this->postJson('/api/v1/public/track', [
        'ticket_code' => $secretCode,
        'secret_token' => $secretToken,
    ])->assertStatus(200)
        ->assertJsonPath('data.requires_secret_token', false)
        ->assertJsonPath('data.report.reporter.name', 'Pelapor Rahasia');

    // 4. Negative validations (422): mismatched village/district & spoofed PHP file
    $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat01->id,
            'district_id' => $villageWithUnit->district_id,
            'village_id' => $otherDistrictVillage->id,
            'latitude' => -7.7188,
            'longitude' => 109.0154,
            'title' => 'Laporan dengan desa tidak cocok kecamatan',
            'description' => 'Menguji validasi kesesuaian village_id dengan district_id di StoreReportRequest.',
            'is_secret' => false,
            'photos' => [makeCitizenJpegUpload('mismatch.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['village_id']]]);

    $fakePhp = UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo "pwn"; ?>');
    $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat01->id,
            'district_id' => $villageWithUnit->district_id,
            'village_id' => $villageWithUnit->id,
            'latitude' => -7.7188,
            'longitude' => 109.0154,
            'title' => 'Laporan dengan berkas PHP palsu',
            'description' => 'Menguji penolakan berkas PHP yang disamarkan dengan ekstensi gambar.',
            'is_secret' => false,
            'photos' => [$fakePhp],
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('supports atomic +1 upvote, duplicate 409, self/secret/final 403, watch toggle, and citizen reports tabs without PII leakage (TASK-BE-036, TC-F-06)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $owner = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $supporter = User::create([
        'name' => 'Warga Pendukung Uji',
        'email' => 'warga.pendukung@pastara.id',
        'phone' => '081299998888',
        'password' => 'Pastara2027!',
    ]);

    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $secret = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $closed = Report::where('status', ReportStatus::VERIFIED_CLOSED->value)->firstOrFail();
    $initialUpvotes = $escrow->upvote_count;

    // 1. Self-upvote, secret upvote, and final-status upvote rejected with 403
    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/reports/{$escrow->id}/upvote")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->actingAs($supporter, 'sanctum')
        ->postJson("/api/v1/reports/{$secret->id}/upvote")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->actingAs($supporter, 'sanctum')
        ->postJson("/api/v1/reports/{$closed->id}/upvote")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // 2. Watch before upvoting returns 404
    $this->actingAs($supporter, 'sanctum')
        ->patchJson("/api/v1/reports/{$escrow->id}/watch", ['is_watching' => false])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // 3. Valid +1 upvote increments count atomically, sets watching=true, and writes UPVOTED audit
    $this->actingAs($supporter, 'sanctum')
        ->postJson("/api/v1/reports/{$escrow->id}/upvote")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.upvote_count', $initialUpvotes + 1)
        ->assertJsonPath('data.is_watching', true);

    expect($escrow->auditLogs()->where('action_type', AuditActionType::UPVOTED)->where('actor_user_id', $supporter->id)->exists())
        ->toBeTrue();

    // 4. Duplicate upvote rejected with 409 Conflict (TC-F-06)
    $this->actingAs($supporter, 'sanctum')
        ->postJson("/api/v1/reports/{$escrow->id}/upvote")
        ->assertStatus(409);

    // 5. GET /citizen/reports?tab=watching shows PST-ESCROW01 with masked reporter (NO email/phone PII leak)
    $watchingResponse = $this->actingAs($supporter, 'sanctum')
        ->getJson('/api/v1/citizen/reports?tab=watching');

    $watchingResponse->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ticket_code', 'PST-ESCROW01')
        ->assertJsonPath('data.0.has_upvoted', true)
        ->assertJsonPath('data.0.reporter.name', 'W***a P. U.')
        ->assertJsonMissingPath('data.0.reporter.email')
        ->assertJsonMissingPath('data.0.reporter.phone');

    // 6. Toggle watch off removes ticket from tab=watching
    $this->actingAs($supporter, 'sanctum')
        ->patchJson("/api/v1/reports/{$escrow->id}/watch", ['is_watching' => false])
        ->assertStatus(200)
        ->assertJsonPath('data.is_watching', false);

    $this->actingAs($supporter, 'sanctum')
        ->getJson('/api/v1/citizen/reports?tab=watching')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 0);

    // 7. GET /citizen/reports?tab=mine shows owner's own tickets (including secret) with full own identity
    $mineResponse = $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/citizen/reports?tab=mine');

    $mineResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $mineItems = collect($mineResponse->json('data'));
    $mineCodes = $mineItems->pluck('ticket_code')->all();
    expect($mineCodes)->toContain('PST-ESCROW01', 'PST-PURW2027');

    $ownSecret = $mineItems->firstWhere('ticket_code', 'PST-PURW2027');
    expect($ownSecret['reporter']['email'])->toBe('warga.demo@pastara.id');
});
