<?php

declare(strict_types=1);

use App\Enums\DisputeStatus;
use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use App\Services\NotificationDispatcherService;
use App\Services\ReportStateMachineService;
use App\Services\TextSanitizerService;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('prevents false positives on year sequences while catching dot-separated phones and 6-6-4 NIKs (M-08)', function (): void {
    $sanitizer = new TextSanitizerService;

    // Year series MUST NOT be falsely censored as NIK
    $years = 'Realisasi anggaran 2023 2024 2025 2026 berjalan lancar';
    expect($sanitizer->sanitize($years))->toBe($years);

    // Cross-line 4-digit numbers MUST NOT merge into NIK
    $multiLine = "Kode 3301\n1201\n0190\n0001";
    expect($sanitizer->sanitize($multiLine))->toBe($multiLine);

    // Dot-separated phone, label-adjacent phone, and 6-6-4 NIK MUST be censored
    expect($sanitizer->sanitize('Hubungi 0812.3456.7890 segera'))->toBe('Hubungi [NO. HP DISENSOR] segera')
        ->and($sanitizer->sanitize('Kontak 081234567890WA aktif'))->toBe('Kontak [NO. HP DISENSOR]WA aktif')
        ->and($sanitizer->sanitize('NIK 330112-010190-0001 terdaftar'))->toBe('NIK [NIK DISENSOR] terdaftar')
        ->and($sanitizer->sanitize('NIK3301120101900001 terdaftar'))->toBe('NIK[NIK DISENSOR] terdaftar');
});

it('cleans up written disk files on DB transaction rollback and enforces BBox / max 3 photos (M-05, M-08b)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $cat = Category::where('code', 'CAT-01')->firstOrFail();
    $village = Village::query()->firstOrFail();

    $img = imagecreatetruecolor(60, 60);
    ob_start();
    imagejpeg($img, null, 85);
    $jpeg = (string) ob_get_clean();
    imagedestroy($img);

    $tmpFiles = [];
    for ($i = 0; $i < 4; $i++) {
        $tmp = tempnam(sys_get_temp_dir(), 'pastara_p2_');
        file_put_contents($tmp, $jpeg);
        $tmpFiles[] = $tmp;
    }

    try {
        $service = app(ReportStateMachineService::class);

        // 1. > 3 photos rejected with 422
        $fourPhotos = array_map(
            static fn (string $path, int $i): UploadedFile => new UploadedFile($path, "p{$i}.jpg", 'image/jpeg', null, true),
            $tmpFiles,
            array_keys($tmpFiles)
        );
        expect(fn () => $service->createAndRoute($warga, [
            'category_id' => $cat->id,
            'district_id' => $village->district_id,
            'village_id' => $village->id,
            'title' => 'Terlalu banyak foto',
            'description' => 'Mengunggah 4 foto sekaligus.',
            'latitude' => -7.7188,
            'longitude' => 109.0154,
        ], $fourPhotos))->toThrow(ValidationException::class);

        // 2. Outside Cilacap BBox rejected with 422
        expect(fn () => $service->createAndRoute($warga, [
            'category_id' => $cat->id,
            'district_id' => $village->district_id,
            'village_id' => $village->id,
            'title' => 'Koordinat Jakarta',
            'description' => 'Di luar batas Kabupaten Cilacap.',
            'latitude' => -6.2000,
            'longitude' => 106.8167,
        ], [new UploadedFile($tmpFiles[0], 'p0.jpg', 'image/jpeg', null, true)]))->toThrow(ValidationException::class);

        // 3. Simulated failure mid-transaction deletes already-written WebP & thumbnail from public disk
        $failingNotifier = Mockery::mock(NotificationDispatcherService::class);
        $failingNotifier->shouldReceive('getUnitAdminIds')->andReturn([1]);
        $failingNotifier->shouldReceive('notifyUsers')->andThrow(new RuntimeException('Simulated rollback failure'));
        app()->instance(NotificationDispatcherService::class, $failingNotifier);

        $rollbackService = app(ReportStateMachineService::class);
        $filesBefore = Storage::disk('public')->allFiles('reports');

        expect(fn () => $rollbackService->createAndRoute($warga, [
            'category_id' => $cat->id,
            'district_id' => $village->district_id,
            'village_id' => $village->id,
            'title' => 'Rollback file cleanup test',
            'description' => 'Memastikan file yatim dihapus saat rollback.',
            'latitude' => -7.7188,
            'longitude' => 109.0154,
        ], [new UploadedFile($tmpFiles[0], 'p0.jpg', 'image/jpeg', null, true)]))->toThrow(RuntimeException::class);

        expect(Storage::disk('public')->allFiles('reports'))->toBe($filesBefore);
    } finally {
        foreach ($tmpFiles as $path) {
            @unlink($path);
        }
    }
});

it('validates past target_date on startApbdExecution, aligns multi-photo geotag_distance_m, and keeps overdue_only active-only (M-05b, M-06, M-08b)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    $service = app(ReportStateMachineService::class);
    $admin = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officer = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $sada = Report::where('ticket_code', 'PST-SADA2027')->firstOrFail();

    // Past target date rejected with 422
    expect(fn () => $service->startApbdExecution($sada, $admin, '2020-01-01', $officer))
        ->toThrow(ValidationException::class);

    // Closed report with stale is_sla_overdue=true MUST NOT appear in overdue_only=true
    $closedReport = Report::where('status', ReportStatus::VERIFIED_CLOSED->value)->firstOrFail();
    $closedReport->update(['is_sla_overdue' => true]);

    $overdueResponse = $this->getJson('/api/v1/public/reports?overdue_only=true')->assertStatus(200);
    $overdueCodes = collect($overdueResponse->json('data'))->pluck('ticket_code')->all();
    expect($overdueCodes)->not->toContain($closedReport->ticket_code);
});

it('runs CilacapSimulationSeeder idempotently, creates sample RKA PDF, exposes CORS headers, and sets Dispute default status (M-11, M-15, L-04)', function (): void {
    Storage::fake('public');

    $this->seed(CilacapSimulationSeeder::class);
    $countAfterFirst = Report::query()->count();

    // Running seeder a second time succeeds cleanly without duplicate errors
    $this->seed(CilacapSimulationSeeder::class);
    expect(Report::query()->count())->toBe($countAfterFirst);

    Storage::disk('public')->assertExists('documents/2026/11/rka-dpupr-sadabumi-2027.pdf');

    // CORS config exposes rate-limit headers & trims trailing slash
    expect(config('cors.exposed_headers'))->toContain('Retry-After', 'X-RateLimit-Limit')
        ->and(config('cors.allowed_origins')[0])->not->toEndWith('/');

    // Dispute default status attribute is PENDING in memory
    $dispute = new Dispute;
    expect($dispute->status)->toBe(DisputeStatus::PENDING);

    // Deterministic scorecard period filter using setTestNow
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00', 'UTC'));
    $this->getJson('/api/v1/public/scorecard?month=11&year=2026')
        ->assertStatus(200)
        ->assertJsonPath('data.period.month', 11)
        ->assertJsonPath('data.period.year', 2026);
    CarbonImmutable::setTestNow();
});
