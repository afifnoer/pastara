<?php

declare(strict_types=1);

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Category;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use App\Services\ReportStateMachineService;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

/**
 * @var list<string>
 */
$createdTempFiles = [];

afterEach(function () use (&$createdTempFiles): void {
    foreach ($createdTempFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $createdTempFiles = [];
});

function makeP0TestUploadedFile(string $contents, string $clientName, string $mime, array &$tracker): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'pastara_p0_');
    file_put_contents($tmp, $contents);
    $tracker[] = $tmp;

    return new UploadedFile($tmp, $clientName, $mime, null, true);
}

function makeP0ValidJpegBytes(): string
{
    $img = imagecreatetruecolor(80, 60);
    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('rejects non-allowlisted APBD uploads and never trusts client file extensions (H-01)', function () use (&$createdTempFiles): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    $service = app(ReportStateMachineService::class);
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $village = Village::query()->firstOrFail();

    $before = makeP0TestUploadedFile(makeP0ValidJpegBytes(), 'before.jpg', 'image/jpeg', $createdTempFiles);
    $created = $service->createAndRoute($warga, [
        'category_id' => $cat01->id,
        'district_id' => $village->district_id,
        'village_id' => $village->id,
        'title' => 'Jalan rusak parah butuh APBD',
        'description' => 'Kerusakan jalan panjang perlu penganggaran APBD.',
        'latitude' => -7.7188,
        'longitude' => 109.0154,
    ], [$before]);
    $report = $created['report'];

    // 1. Malicious PHP script disguised with .pdf filename is rejected with 422
    $phpPayload = makeP0TestUploadedFile('<?php system($_GET["c"]); ?>', 'rka.pdf', 'application/x-php', $createdTempFiles);
    expect(fn () => $service->scheduleApbd($report, $adminDpupr, [
        'fiscal_year' => 2027,
        'target_quarter' => 'Q2',
        'proposal_ref_number' => 'RKA-TEST-001',
    ], $phpPayload))->toThrow(ValidationException::class);

    // 2. Valid JPEG disguised with .php extension is re-encoded to safe .webp (never .php)
    $spoofedJpeg = makeP0TestUploadedFile(makeP0ValidJpegBytes(), 'shell.php', 'image/jpeg', $createdTempFiles);
    $scheduled = $service->scheduleApbd($report, $adminDpupr, [
        'fiscal_year' => 2027,
        'target_quarter' => 'Q2',
        'proposal_ref_number' => 'RKA-TEST-002',
    ], $spoofedJpeg);

    $docPath = $scheduled->budgetSchedule->document_proof_path;
    expect($docPath)->toEndWith('.webp')
        ->and($docPath)->not->toEndWith('.php');
    Storage::disk('public')->assertExists($docPath);

    // 3. Valid PDF with %PDF- magic header saves safely as .pdf
    $before2 = makeP0TestUploadedFile(makeP0ValidJpegBytes(), 'before2.jpg', 'image/jpeg', $createdTempFiles);
    $created2 = $service->createAndRoute($warga, [
        'category_id' => $cat01->id,
        'district_id' => $village->district_id,
        'village_id' => $village->id,
        'title' => 'Jalan rusak parah butuh APBD 2',
        'description' => 'Kerusakan jalan kedua perlu APBD.',
        'latitude' => -7.7190,
        'longitude' => 109.0160,
    ], [$before2]);

    $validPdf = makeP0TestUploadedFile("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n", 'rka.exe', 'application/pdf', $createdTempFiles);
    $scheduledPdf = $service->scheduleApbd($created2['report'], $adminDpupr, [
        'fiscal_year' => 2027,
        'target_quarter' => 'Q3',
        'proposal_ref_number' => 'RKA-TEST-003',
    ], $validPdf);

    $pdfPath = $scheduledPdf->budgetSchedule->document_proof_path;
    expect($pdfPath)->toEndWith('.pdf')
        ->and($pdfPath)->not->toEndWith('.exe');
    Storage::disk('public')->assertExists($pdfPath);
});

it('sanitizes address_detail PII and clamps sanitized title within VARCHAR(120) without crashing (H-09)', function () use (&$createdTempFiles): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    $service = app(ReportStateMachineService::class);
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $village = Village::query()->firstOrFail();

    $before = makeP0TestUploadedFile(makeP0ValidJpegBytes(), 'before.jpg', 'image/jpeg', $createdTempFiles);
    $longTitleWithPhone = str_repeat('A', 108).' 0812345678'; // 119 raw chars -> >120 after [NO. HP DISENSOR]

    $created = $service->createAndRoute($warga, [
        'category_id' => $cat01->id,
        'district_id' => $village->district_id,
        'village_id' => $village->id,
        'title' => $longTitleWithPhone,
        'description' => 'Hubungi 081234567890 untuk lokasi pasti.',
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'address_detail' => 'Dekat rumah Pak Budi NIK 3301120101900001 WA 081234567890',
    ], [$before]);

    $report = $created['report'];

    expect(mb_strlen($report->title))->toBeLessThanOrEqual(120)
        ->and($report->title)->not->toContain('0812345678')
        ->and($report->address_detail)->not->toContain('3301120101900001')
        ->and($report->address_detail)->not->toContain('081234567890')
        ->and($report->address_detail)->toContain('[NIK DISENSOR]')
        ->and($report->address_detail)->toContain('[NO. HP DISENSOR]');
});

it('blocks deactivated citizens and null sign-off deadlines on both Policy and StateMachine (H-02, H-10)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $service = app(ReportStateMachineService::class);
    $reporter = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $admin = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();

    // viewCitizen blocks internal roles and inactive citizens
    expect(Gate::forUser($admin)->allows('viewCitizen', $escrow))->toBeFalse();

    // Null signoff_deadline_at is fail-closed in both Policy and StateMachine
    $escrow->update(['signoff_deadline_at' => null]);
    expect(Gate::forUser($reporter)->allows('signOff', [$escrow, null]))->toBeFalse();
    expect(fn () => $service->citizenApprove($escrow, $reporter, null))
        ->toThrow(InvalidStateTransitionException::class);

    // Restore deadline, deactivate reporter -> blocked by Policy and StateMachine
    $escrow->update(['signoff_deadline_at' => now('UTC')->addHours(48)]);
    $reporter->update(['is_active' => false]);
    $reporter->refresh();

    expect(Gate::forUser($reporter)->allows('viewCitizen', $escrow))->toBeFalse()
        ->and(Gate::forUser($reporter)->allows('signOff', [$escrow, null]))->toBeFalse()
        ->and(fn () => $service->citizenApprove($escrow, $reporter, null))
        ->toThrow(AuthorizationException::class);
});

it('handles array inputs safely as HTTP 422 instead of HTTP 500 across login, register, and track (H-05)', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => ['malformed@pastara.id'],
        'password' => 'Pastara2027!',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Warga Uji',
        'email' => ['malformed@pastara.id'],
        'password' => 'Pastara2027!',
        'password_confirmation' => 'Pastara2027!',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $this->postJson('/api/v1/public/track', [
        'ticket_code' => ['PST-ESCROW01'],
        'secret_token' => ['TKN-DEMO-2027'],
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('renders 405 cleanly under APP_DEBUG=false, preserves 429 headers, logs 403/409 to security channel, and revokes all tokens on deactivated user (H-06, M-09, M-12)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    // 1. HTTP 405 Method Not Allowed must NOT turn into 500 when APP_DEBUG=false
    config(['app.debug' => false]);
    $this->getJson('/api/v1/auth/login')
        ->assertStatus(405)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');

    // 2. HTTP 429 preserves Retry-After and X-RateLimit-Limit headers
    Route::post('/api/v1/_test/p0-throttle', fn () => response()->json(['ok' => true]))
        ->middleware('throttle:track');

    for ($i = 1; $i <= 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.90.0.1'])
            ->postJson('/api/v1/_test/p0-throttle')
            ->assertStatus(200);
    }

    $throttled = $this->withServerVariables(['REMOTE_ADDR' => '10.90.0.1'])
        ->postJson('/api/v1/_test/p0-throttle');
    $throttled->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')
        ->assertHeader('X-RateLimit-Limit', '10')
        ->assertHeader('Retry-After');

    // 3. 403 and 409 write to security log channel (verified via Monolog TestHandler)
    $testHandler = new TestHandler;
    Log::channel('security')->getLogger()->pushHandler($testHandler);

    Route::get('/api/v1/_test/p0-forbidden', function (): void {
        throw new AuthorizationException('Pesan kustom forbidden.');
    });
    Route::get('/api/v1/_test/p0-conflict', function (): void {
        throw new InvalidStateTransitionException('Transisi ilegal.');
    });

    $this->getJson('/api/v1/_test/p0-forbidden')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN')
        ->assertJsonPath('error.message', 'Pesan kustom forbidden.');

    $this->getJson('/api/v1/_test/p0-conflict')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    expect($testHandler->hasWarningThatContains('Forbidden access denied'))->toBeTrue()
        ->and($testHandler->hasWarningThatContains('Invalid state transition rejected'))->toBeTrue();

    // 4. EnsureRole revokes ALL tokens (multi-device) and preserves deactivated message
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $token1 = $warga->createToken('device-1')->plainTextToken;
    $warga->createToken('device-2');
    expect($warga->tokens()->count())->toBe(2);

    $warga->update(['is_active' => false]);

    $this->withToken($token1)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED')
        ->assertJsonPath('error.message', 'Akun Anda sedang dinonaktifkan.');

    expect($warga->tokens()->count())->toBe(0);

    // 5. Report::hashSecretToken guards empty APP_KEY
    config(['app.key' => '']);
    expect(fn () => Report::hashSecretToken('TKN-DEMO-2027'))->toThrow(RuntimeException::class);
});
