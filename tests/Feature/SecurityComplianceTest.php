<?php

declare(strict_types=1);

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Exceptions\EscrowLockViolationException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\District;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use App\Services\ReportStateMachineService;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
    $this->travelBack();
    Auth::forgetGuards();
});

function makeSecJpeg(string $name = 'valid.jpg'): UploadedFile
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

it('TC-SEC-01: blocks unilateral ticket closure by ADMIN_UNIT, PETUGAS, and SUPER_ADMIN with HTTP 403 ESCROW_LOCK_VIOLATION (Hukum 1, TASK-BE-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $adminDishub = User::where('email', 'admin.dishub@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();
    $stateMachine = app(ReportStateMachineService::class);

    foreach ([$adminDishub, $officerDishub, $superAdmin] as $internalActor) {
        // HTTP endpoint guard
        $this->actingAs($internalActor, 'sanctum')
            ->postJson("/api/v1/reports/{$escrow->ticket_code}/sign-off/approve")
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ESCROW_LOCK_VIOLATION');

        // Domain service guard
        expect(fn () => $stateMachine->citizenApprove($escrow, $internalActor))
            ->toThrow(EscrowLockViolationException::class);
    }

    // Ticket remains locked in PENDING_CITIZEN_SIGN_OFF
    expect($escrow->fresh()->status)->toBe(ReportStatus::PENDING_CITIZEN_SIGN_OFF);
});

it('TC-SEC-02: prevents IDOR across unit boundaries, officer assignments, and citizen ownership with HTTP 404/403 (TASK-BE-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $adminDlh = User::where('email', 'admin.dlh@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $officerDlh = User::where('email', 'petugas.dlh@pastara.id')->firstOrFail();
    $otherWarga = User::where('email', 'warga.sim01@pastara.id')->firstOrFail();

    $dpuprReport = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();

    // 1. Admin DLH attempting to read/mutate DPUPR ticket -> HTTP 404 NOT_FOUND
    $this->actingAs($adminDlh, 'sanctum')
        ->getJson("/api/v1/unit/reports/{$dpuprReport->id}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->actingAs($adminDlh, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprReport->id}/assign", ['officer_id' => $officerDlh->id])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->actingAs($adminDlh, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprReport->id}/schedule-apbd", [])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->actingAs($adminDlh, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprReport->id}/reject", [])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->actingAs($adminDlh, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprReport->id}/reroute", [])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // 2. Officer Dishub attempting to mutate Officer DLH's task -> HTTP 404 NOT_FOUND
    $dlhTask = Report::where('assigned_officer_id', $officerDlh->id)
        ->where('status', ReportStatus::IN_PROGRESS->value)
        ->firstOrFail();

    $this->actingAs($officerDishub, 'sanctum')
        ->postJson("/api/v1/officer/tasks/{$dlhTask->id}/progress", [
            'note' => 'Mencoba akses IDOR ke tugas petugas lain.',
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->actingAs($officerDishub, 'sanctum')
        ->postJson("/api/v1/officer/tasks/{$dlhTask->id}/evidence", [
            'action_note' => 'Mencoba unggah bukti IDOR ke tugas petugas lain.',
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // 3. Non-owner citizen attempting sign-off on PST-ESCROW01 -> HTTP 403, and public detail of secret ticket -> 404
    $this->actingAs($otherWarga, 'sanctum')
        ->postJson('/api/v1/reports/PST-ESCROW01/sign-off/approve')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->actingAs($otherWarga, 'sanctum')
        ->getJson('/api/v1/public/reports/PST-PURW2027')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('TC-SEC-03 & TC-SEC-04: enforces strict RBAC matrix (401/403) and blocks role mass-assignment on registration and profile update (TASK-BE-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $dpupr = OpdUnit::where('code', 'DPUPR')->firstOrFail();

    // Unauthenticated -> 401
    foreach (['/api/v1/unit/reports', '/api/v1/officer/tasks', '/api/v1/inspectorate/reports', '/api/v1/admin/users', '/api/v1/admin/audit-logs'] as $uri) {
        $this->getJson($uri)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    // TC-SEC-03: WARGA calling privileged endpoints -> 403
    foreach ([
        '/api/v1/unit/reports',
        '/api/v1/officer/tasks',
        '/api/v1/inspectorate/reports',
        '/api/v1/inspectorate/scorecard',
        '/api/v1/admin/users',
        '/api/v1/admin/opd-units',
        '/api/v1/admin/categories',
        '/api/v1/admin/locations',
        '/api/v1/admin/audit-logs',
    ] as $uri) {
        $this->actingAs($warga, 'sanctum')
            ->getJson($uri)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // ADMIN_UNIT & PETUGAS cannot access Inspectorate or Super Admin routes
    foreach ([$adminDpupr, $officerDpupr] as $actor) {
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/inspectorate/reports')->assertStatus(403);
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
    }

    // INSPEKTORAT cannot manage Super Admin users (403) but CAN read audit-logs (200)
    $this->actingAs($auditor, 'sanctum')->getJson('/api/v1/admin/users')->assertStatus(403);
    $this->actingAs($auditor, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertStatus(200);

    // TC-SEC-04: Role & privilege injection during registration is ignored -> locked to WARGA
    Auth::forgetGuards();
    $regRes = $this->postJson('/api/v1/auth/register', [
        'name' => 'Penyerang Role Injection',
        'email' => 'attacker.role@pastara.id',
        'password' => 'Pastara2027!',
        'password_confirmation' => 'Pastara2027!',
        'phone' => '081299998888',
        'role' => UserRole::SUPER_ADMIN->value,
        'opd_unit_id' => $dpupr->id,
        'is_active' => false,
    ]);

    $regRes->assertStatus(201)
        ->assertJsonPath('data.user.role', UserRole::WARGA->value);

    $attacker = User::where('email', 'attacker.role@pastara.id')->firstOrFail();
    expect($attacker->role)->toBe(UserRole::WARGA)
        ->and($attacker->opd_unit_id)->toBeNull()
        ->and($attacker->is_active)->toBeTrue();

    // TC-SEC-04: Role injection during profile PATCH is also ignored
    $this->actingAs($attacker, 'sanctum')
        ->patchJson('/api/v1/auth/profile', [
            'name' => 'Nama Baru Aman',
            'role' => UserRole::SUPER_ADMIN->value,
            'opd_unit_id' => $dpupr->id,
            'is_active' => false,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.role', UserRole::WARGA->value);

    $attacker->refresh();
    expect($attacker->role)->toBe(UserRole::WARGA)
        ->and($attacker->opd_unit_id)->toBeNull()
        ->and($attacker->is_active)->toBeTrue();
});

it('TC-SEC-05: guarantees zero secret reporter identity leakage across public, unit, officer, and anonymous track responses (Hukum 4, TASK-BE-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $secret = Report::with('reporter')->where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $reporter = $secret->reporter;
    $adminDisdikbud = User::where('email', 'admin.disdikbud@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();

    $officerDisdikbud = User::create([
        'name' => 'Petugas Disdikbud Sec',
        'email' => 'petugas.disdikbud.sec@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $secret->opd_unit_id,
        'is_active' => true,
    ]);

    $secret->update([
        'assigned_officer_id' => $officerDisdikbud->id,
        'status' => ReportStatus::IN_PROGRESS,
    ]);

    // 1. Public endpoints hide secret ticket completely
    $this->getJson('/api/v1/public/reports/PST-PURW2027')->assertStatus(404);
    expect($this->getJson('/api/v1/public/map-markers')->assertStatus(200)->getContent())
        ->not->toContain('PST-PURW2027');
    expect($this->getJson('/api/v1/public/reports?q=PST-PURW2027')->assertStatus(200)->getContent())
        ->not->toContain('PST-PURW2027');

    // 2. Admin Unit detail & list mask secret reporter completely
    $unitDetail = $this->actingAs($adminDisdikbud, 'sanctum')
        ->getJson("/api/v1/unit/reports/{$secret->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.reporter.name', 'Pelapor Rahasia')
        ->assertJsonPath('data.reporter.email', null)
        ->assertJsonPath('data.reporter.phone', null);

    expect($unitDetail->getContent())
        ->not->toContain($reporter->email)
        ->not->toContain($reporter->name)
        ->not->toContain((string) $reporter->phone);

    // 3. Officer task list masks secret reporter completely
    $officerTasks = $this->actingAs($officerDisdikbud, 'sanctum')
        ->getJson('/api/v1/officer/tasks?status=active')
        ->assertStatus(200);

    $taskItem = collect($officerTasks->json('data'))->firstWhere('ticket_code', 'PST-PURW2027');
    expect($taskItem)->not->toBeNull()
        ->and($taskItem['reporter']['name'])->toBe('Pelapor Rahasia')
        ->and($taskItem['reporter']['email'])->toBeNull()
        ->and($taskItem['reporter']['phone'])->toBeNull()
        ->and($officerTasks->getContent())->not->toContain($reporter->email);

    // 4. Anonymous /public/track with valid token masks reporter
    Auth::forgetGuards();
    $trackRes = $this->postJson('/api/v1/public/track', [
        'ticket_code' => 'PST-PURW2027',
        'secret_token' => 'TKN-DEMO-2027',
    ])->assertStatus(200);

    expect($trackRes->getContent())
        ->not->toContain($reporter->email)
        ->not->toContain((string) $reporter->phone);

    // 5. Only Inspectorate sees full reporter identity
    $inspRes = $this->actingAs($auditor, 'sanctum')
        ->getJson("/api/v1/inspectorate/reports?tab=all&opd_unit_id={$secret->opd_unit_id}")
        ->assertStatus(200);

    $inspItem = collect($inspRes->json('data'))->firstWhere('ticket_code', 'PST-PURW2027');
    expect($inspItem['reporter']['email'])->toBe($reporter->email);
});

it('TC-SEC-06: enforces brute-force rate limiting (HTTP 429) on login and secret ticket tracking with generic error messages (TASK-BE-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    RateLimiter::clear('login');
    RateLimiter::clear('track');

    // 1. Login rate limit: 5/min per email+IP, generic error message preventing user enumeration
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'warga.demo@pastara.id',
            'password' => 'WrongPassword123',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.message', 'Email atau password salah.');
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'warga.demo@pastara.id',
        'password' => 'WrongPassword123',
    ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

    // 2. Track secret ticket rate limit: 10/min per IP, uniform 404 on wrong token
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson('/api/v1/public/track', [
            'ticket_code' => 'PST-PURW2027',
            'secret_token' => 'TKN-FAKE-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.message', 'Tiket tidak ditemukan atau token tidak valid.');
    }

    $this->postJson('/api/v1/public/track', [
        'ticket_code' => 'PST-PURW2027',
        'secret_token' => 'TKN-FAKE-0011',
    ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

it('TC-SEC-07 & TC-SEC-08: rejects disguised PHP, SVG-XSS, and oversized (>5MB) uploads, re-encodes valid photos as EXIF-free UUID WebP, and serves XSS inputs safely as JSON (TASK-BE-055)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $cat02 = Category::where('code', 'CAT-02')->firstOrFail();
    $district = District::where('name', 'Cilacap Tengah')->firstOrFail();
    $village = Village::where('district_id', $district->id)->firstOrFail();

    $basePayload = [
        'category_id' => $cat02->id,
        'district_id' => $district->id,
        'village_id' => $village->id,
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'address_detail' => 'Jl. Ahmad Yani Cilacap',
        'title' => 'Laporan uji keamanan unggahan berkas',
        'description' => 'Deskripsi cukup panjang untuk memverifikasi validasi unggahan keamanan.',
        'is_secret' => 'false',
    ];

    // 1. Disguised PHP shell with .jpg extension -> 422
    $phpAsJpg = UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>');
    $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', $basePayload + ['photos' => [$phpAsJpg]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 2. SVG containing script payload -> 422
    $svgXss = UploadedFile::fake()->createWithContent(
        'malicious.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script></svg>'
    );
    $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', $basePayload + ['photos' => [$svgXss]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 3. Oversized file > 5 MB (5200 KB) -> 422
    $oversized = UploadedFile::fake()->create('huge.jpg', 5200, 'image/jpeg');
    $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', $basePayload + ['photos' => [$oversized]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 4. TC-SEC-08: XSS payload in title/description accepted strictly as JSON (nosniff), valid photo stored as UUID WebP
    $xssTitle = '<script>alert("xss")</script> Lampu jalan mati';
    $xssRes = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', array_merge($basePayload, [
            'title' => $xssTitle,
            'description' => '<img src=x onerror=alert(1)> Kondisi gelap sekali, hubungi 081234567890.',
            'photos' => [makeSecJpeg('original-evil-name.jpg')],
        ]), ['Accept' => 'application/json']);

    $xssRes->assertStatus(201)
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $report = Report::with('photos')->findOrFail((int) $xssRes->json('data.id'));
    $photo = $report->photos->firstOrFail();

    expect($photo->file_path)->toEndWith('.webp')
        ->and($photo->file_path)->not->toContain('original-evil-name')
        ->and($report->description_sanitized)->toContain('[NO. HP DISENSOR]');

    Storage::disk('public')->assertExists($photo->file_path);
});

it('TC-SEC-09: resists SQL injection payloads across public, inspectorate, and admin filter/search parameters (TASK-BE-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $initialReportCount = Report::count();

    $sqliPayloads = [
        "' OR '1'='1",
        "'; DROP TABLE reports; --",
        '1 UNION SELECT password FROM users --',
        '1; SELECT pg_sleep(1); --',
    ];

    foreach ($sqliPayloads as $payload) {
        // String search q -> safe 200, never leaks secret ticket PST-PURW2027
        $searchRes = $this->getJson('/api/v1/public/reports?'.http_build_query(['q' => $payload]))
            ->assertStatus(200);
        expect($searchRes->getContent())->not->toContain('PST-PURW2027');

        // Typed/enum filters -> cleanly rejected 422 (never 500)
        $this->getJson('/api/v1/public/map-markers?'.http_build_query(['category_id' => $payload]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/v1/inspectorate/reports?'.http_build_query(['opd_unit_id' => $payload]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs?'.http_build_query(['report_id' => $payload]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // Database integrity completely preserved
    expect(Report::count())->toBe($initialReportCount);
});

it('TC-SEC-10: enforces immutable audit_logs via PostgreSQL trigger, Eloquent guard, and absence of mutating HTTP routes (Hukum 3, TASK-BE-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();
    $audit = AuditLog::firstOrFail();

    // 1. PostgreSQL trigger blocks raw SQL UPDATE and DELETE
    expect(fn () => DB::table('audit_logs')->where('id', $audit->id)->update(['notes' => 'tampered']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('audit_logs')->where('id', $audit->id)->delete())
        ->toThrow(QueryException::class);

    // 2. Eloquent model blocks update() and delete()
    expect(fn () => $audit->update(['notes' => 'tampered']))
        ->toThrow(LogicException::class);

    expect(fn () => $audit->delete())
        ->toThrow(LogicException::class);

    // 3. No HTTP PUT/PATCH/DELETE endpoint exists for audit-logs
    expect($this->actingAs($superAdmin, 'sanctum')->putJson("/api/v1/admin/audit-logs/{$audit->id}", [])->status())
        ->toBeIn([404, 405]);
    expect($this->actingAs($superAdmin, 'sanctum')->patchJson("/api/v1/admin/audit-logs/{$audit->id}", [])->status())
        ->toBeIn([404, 405]);
    expect($this->actingAs($superAdmin, 'sanctum')->deleteJson("/api/v1/admin/audit-logs/{$audit->id}")->status())
        ->toBeIn([404, 405]);
});

it('TC-SEC-11: rejects logged-out, password-rotated, expired (>24h), and deactivated-account Sanctum Bearer tokens with HTTP 401 (TASK-BE-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $baseTime = CarbonImmutable::parse('2026-12-02 00:00:00', 'UTC');
    $this->travelTo($baseTime);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();

    // 1. Login -> valid token works -> logout revokes it -> reuse returns 401
    $loginRes = $this->postJson('/api/v1/auth/login', [
        'email' => $warga->email,
        'password' => 'Pastara2027!',
    ])->assertStatus(200);

    $token1 = (string) $loginRes->json('data.token');
    $this->withHeader('Authorization', 'Bearer '.$token1)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);

    $this->withHeader('Authorization', 'Bearer '.$token1)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(200);

    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token1)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    // 2. Password change revokes other active tokens
    $deviceA = (string) $this->postJson('/api/v1/auth/login', [
        'email' => $warga->email,
        'password' => 'Pastara2027!',
    ])->assertStatus(200)->json('data.token');

    $deviceB = (string) $this->postJson('/api/v1/auth/login', [
        'email' => $warga->email,
        'password' => 'Pastara2027!',
    ])->assertStatus(200)->json('data.token');

    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$deviceA)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'Pastara2027!',
            'password' => 'PastaraNew2027!',
            'password_confirmation' => 'PastaraNew2027!',
        ])
        ->assertStatus(200);

    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$deviceB)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    // 3. Token expiration (> 24h = 1440 min) -> HTTP 401
    $this->travelTo($baseTime->addHours(25));
    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$deviceA)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    // 4. Account deactivation revokes all tokens immediately and blocks login
    $this->travelTo($baseTime->addHours(26));
    $adminOfficer = User::create([
        'name' => 'Petugas Uji Nonaktif',
        'email' => 'petugas.nonaktif.sec@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => OpdUnit::where('code', 'DISHUB')->firstOrFail()->id,
        'is_active' => true,
    ]);

    $officerToken = (string) $this->postJson('/api/v1/auth/login', [
        'email' => $adminOfficer->email,
        'password' => 'Pastara2027!',
    ])->assertStatus(200)->json('data.token');

    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$adminOfficer->id}", ['is_active' => false])
        ->assertStatus(200);

    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$officerToken)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $this->postJson('/api/v1/auth/login', [
        'email' => $adminOfficer->email,
        'password' => 'Pastara2027!',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.message', 'Email atau password salah.');
});

it('TC-SEC-12: attaches security headers, restricts CORS to configured frontend origin, and hides stack traces when APP_DEBUG=false (SR-023, SR-024, TASK-BE-055)', function (): void {
    $allowedOrigin = config('cors.allowed_origins.0', 'http://localhost:5173');

    // 1. Security headers + allowed CORS origin
    $okRes = $this->withHeaders(['Origin' => $allowedOrigin])
        ->getJson('/api/v1/health');

    $okRes->assertStatus(200)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('Access-Control-Allow-Origin', $allowedOrigin);

    // 2. Untrusted CORS origin is NEVER reflected and NEVER wildcarded
    $evilOrigin = 'https://evil-attacker.example.com';
    $evilRes = $this->withHeaders(['Origin' => $evilOrigin])
        ->getJson('/api/v1/health');

    $evilRes->assertStatus(200);
    $evilAcao = $evilRes->headers->get('Access-Control-Allow-Origin');
    expect($evilAcao)->not->toBe($evilOrigin)
        ->and($evilAcao)->not->toBe('*');

    $preflightEvil = $this->withHeaders([
        'Origin' => $evilOrigin,
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/v1/auth/login');
    expect($preflightEvil->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe($evilOrigin)
        ->and($preflightEvil->headers->get('Access-Control-Allow-Origin'))->not->toBe('*');

    // 3. APP_DEBUG=false masks unhandled exceptions as standardized 500 JSON without trace/file leaks
    config(['app.debug' => false]);
    Route::get('/api/v1/__test-sec-500', function (): never {
        throw new RuntimeException('SENSITIVE_DB_PASSWORD_LEAK_TEST');
    });

    $errRes = $this->getJson('/api/v1/__test-sec-500');
    $errRes->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INTERNAL_SERVER_ERROR')
        ->assertJsonMissingPath('trace')
        ->assertJsonMissingPath('file');

    expect($errRes->getContent())->not->toContain('SENSITIVE_DB_PASSWORD_LEAK_TEST');
});
