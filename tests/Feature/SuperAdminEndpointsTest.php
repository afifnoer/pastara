<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\District;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

it('manages internal users with RBAC, active-task 409 guard, self-deactivation 403, and Sanctum token revocation (TASK-BE-051, TC-F-18)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $dpupr = OpdUnit::where('code', 'DPUPR')->firstOrFail();

    // 1. Auth 401 & RBAC 403
    $this->getJson('/api/v1/admin/users')->assertStatus(401);
    $this->actingAs($adminDpupr, 'sanctum')->getJson('/api/v1/admin/users')->assertStatus(403);
    $this->actingAs($auditor, 'sanctum')->getJson('/api/v1/admin/users')->assertStatus(403);
    $this->actingAs($warga, 'sanctum')->getJson('/api/v1/admin/users')->assertStatus(403);

    // 2. Listing + filters + non-integer FK guard (422)
    $this->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/v1/admin/users?opd_unit_id=abc')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $listRes = $this->actingAs($superAdmin, 'sanctum')
        ->getJson("/api/v1/admin/users?role=PETUGAS&opd_unit_id={$dpupr->id}&is_active=true");

    $listRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.per_page', 20);
    expect($listRes->json('data'))->not->toBeEmpty();

    // 3. TC-F-18: Create PETUGAS without opd_unit_id -> 422
    $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/admin/users', [
            'name' => 'Petugas Tanpa Unit',
            'email' => 'petugas.tanpa.unit@pastara.id',
            'password' => 'Pastara2027',
            'role' => 'PETUGAS',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // Cannot create SUPER_ADMIN or WARGA via internal endpoint
    $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/admin/users', [
            'name' => 'Super Admin Ilegal',
            'email' => 'ilegal.sa@pastara.id',
            'password' => 'Pastara2027',
            'role' => 'SUPER_ADMIN',
        ])
        ->assertStatus(422);

    // 4. Create PETUGAS validly -> 201 + USER_CREATED audit log
    $createdOfficerRes = $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/admin/users', [
            'name' => 'Petugas Lapangan Baru',
            'email' => 'petugas.baru@pastara.id',
            'phone' => '081234567890',
            'password' => 'Pastara2027',
            'role' => 'PETUGAS',
            'opd_unit_id' => $dpupr->id,
        ]);

    $createdOfficerRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'petugas.baru@pastara.id')
        ->assertJsonPath('data.role', 'PETUGAS')
        ->assertJsonPath('data.opd_unit_id', $dpupr->id)
        ->assertJsonPath('data.is_active', true);

    $newOfficer = User::where('email', 'petugas.baru@pastara.id')->firstOrFail();

    $createAudit = AuditLog::query()
        ->where('action_type', AuditActionType::USER_CREATED->value)
        ->where('actor_user_id', $superAdmin->id)
        ->latest('id')
        ->first();
    expect($createAudit)->not->toBeNull()
        ->and($createAudit->is_public_visible)->toBeFalse();

    // Create INSPEKTORAT without opd_unit_id -> 201
    $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/admin/users', [
            'name' => 'Auditor Baru Inspektorat',
            'email' => 'auditor.baru@pastara.id',
            'password' => 'Pastara2027',
            'role' => 'INSPEKTORAT',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.role', 'INSPEKTORAT');

    // 5. Self-deactivation rejected with 403
    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$superAdmin->id}", [
            'is_active' => false,
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // 6. TC-F-18: Deactivating PETUGAS who holds an active IN_PROGRESS task -> 409 INVALID_STATE_TRANSITION
    $activeTask = Report::query()
        ->where('status', ReportStatus::IN_PROGRESS->value)
        ->whereNotNull('assigned_officer_id')
        ->firstOrFail();

    $busyOfficer = User::findOrFail($activeTask->assigned_officer_id);

    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$busyOfficer->id}", [
            'is_active' => false,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    expect($busyOfficer->fresh()->is_active)->toBeTrue();

    // 7. Password reset + deactivation on idle officer revokes ALL Sanctum tokens (SR-003 / BR-042)
    $tokenA = $newOfficer->createToken('device-a')->plainTextToken;
    $newOfficer->createToken('device-b');
    expect($newOfficer->tokens()->count())->toBe(2);

    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$newOfficer->id}", [
            'name' => 'Petugas Lapangan Nonaktif',
            'password' => 'NewPass2027',
            'is_active' => false,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Petugas Lapangan Nonaktif')
        ->assertJsonPath('data.is_active', false);

    $newOfficer->refresh();
    expect($newOfficer->is_active)->toBeFalse()
        ->and(Hash::check('NewPass2027', $newOfficer->password))->toBeTrue()
        ->and($newOfficer->tokens()->count())->toBe(0);

    $deactivateAudit = AuditLog::query()
        ->where('action_type', AuditActionType::USER_DEACTIVATED->value)
        ->where('actor_user_id', $superAdmin->id)
        ->latest('id')
        ->first();
    expect($deactivateAudit)->not->toBeNull();

    // Verify revoked bearer token is immediately rejected with 401
    Auth::forgetGuards();
    $this->withToken($tokenA)->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('manages OPD units and rejects deactivating units with active tickets (TASK-BE-051, TC-F-18)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();
    $dpupr = OpdUnit::where('code', 'DPUPR')->firstOrFail();

    // 1. List OPD units
    $this->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/v1/admin/opd-units?level=OPD')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // 2. Create new OPD unit -> 201
    $createRes = $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/admin/opd-units', [
            'code' => 'desa_uji_baru',
            'name' => 'Pemerintah Desa Uji Baru',
            'level' => UnitLevel::DESA->value,
            'description' => 'Unit desa untuk pengujian Super Admin.',
        ]);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.code', 'DESA_UJI_BARU')
        ->assertJsonPath('data.level', 'DESA')
        ->assertJsonPath('data.is_active', true);

    $newUnitId = (int) $createRes->json('data.id');

    // Duplicate code -> 422
    $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/admin/opd-units', [
            'code' => 'DESA_UJI_BARU',
            'name' => 'Duplikat Unit',
            'level' => UnitLevel::DESA->value,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 3. TC-F-18: Deactivate DPUPR (which has active non-final tickets) -> 409 INVALID_STATE_TRANSITION
    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/opd-units/{$dpupr->id}", [
            'is_active' => false,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    expect($dpupr->fresh()->is_active)->toBeTrue();

    // 4. Update & deactivate empty unit -> 200 + UNIT_UPDATED audit log
    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/opd-units/{$newUnitId}", [
            'name' => 'Pemerintah Desa Uji Baru (Arsip)',
            'is_active' => false,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Pemerintah Desa Uji Baru (Arsip)')
        ->assertJsonPath('data.is_active', false);

    expect(AuditLog::where('action_type', AuditActionType::UNIT_UPDATED->value)->count())->toBeGreaterThanOrEqual(2);
});

it('updates category parameters without altering existing ticket snapshots (TASK-BE-052, BR-004) and manages Cilacap locations', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();
    $cat02 = Category::where('code', 'CAT-02')->firstOrFail();
    $existingCat02Reports = Report::where('category_id', $cat02->id)->get();
    expect($existingCat02Reports)->not->toBeEmpty();

    $originalRadius = $cat02->duplicate_radius_m;
    expect($originalRadius)->toBe(200);

    // 1. List categories
    $this->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/v1/admin/categories')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // 2. Reject invalid SLA hours (422)
    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/categories/{$cat02->id}", [
            'default_sla_hours' => 36,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 3. Update CAT-02 radius from 200m -> 300m, verify existing report snapshots remain 200m (BR-004)
    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/categories/{$cat02->id}", [
            'duplicate_radius_m' => 300,
            'geotag_tolerance_m' => 150,
            'default_sla_hours' => 48,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.duplicate_radius_m', 300)
        ->assertJsonPath('data.geotag_tolerance_m', 150)
        ->assertJsonPath('data.default_sla_hours', 48);

    foreach ($existingCat02Reports as $report) {
        expect($report->fresh()->duplicate_radius_snapshot_m)->toBe(200);
    }

    $catAudit = AuditLog::query()
        ->where('action_type', AuditActionType::CATEGORY_UPDATED->value)
        ->latest('id')
        ->first();
    expect($catAudit)->not->toBeNull()
        ->and($catAudit->metadata['after']['duplicate_radius_m'])->toBe(300)
        ->and($catAudit->metadata['before']['duplicate_radius_m'])->toBe(200);

    // 4. Locations CRUD: list, create district, create village, update district & village
    $this->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/v1/admin/locations')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $districtRes = $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/admin/locations', [
            'type' => 'district',
            'code' => '330199',
            'name' => 'Kecamatan Uji Cilacap',
        ]);

    $districtRes->assertStatus(201)
        ->assertJsonPath('data.type', 'district')
        ->assertJsonPath('data.code', '330199');
    $districtId = (int) $districtRes->json('data.id');

    $desaUnit = OpdUnit::where('level', UnitLevel::DESA->value)->firstOrFail();

    $villageRes = $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/v1/admin/locations', [
            'type' => 'village',
            'district_id' => $districtId,
            'opd_unit_id' => $desaUnit->id,
            'name' => 'Desa Uji Makmur',
            'village_type' => 'DESA',
        ]);

    $villageRes->assertStatus(201)
        ->assertJsonPath('data.type', 'village')
        ->assertJsonPath('data.village_type', 'DESA')
        ->assertJsonPath('data.opd_unit_id', $desaUnit->id);
    $villageId = (int) $villageRes->json('data.id');

    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/locations/district/{$districtId}", [
            'name' => 'Kecamatan Uji Cilacap Barat',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Kecamatan Uji Cilacap Barat');

    $this->actingAs($superAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/locations/village/{$villageId}", [
            'name' => 'Kelurahan Uji Makmur',
            'village_type' => 'KELURAHAN',
            'opd_unit_id' => null,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Kelurahan Uji Makmur')
        ->assertJsonPath('data.village_type', 'KELURAHAN')
        ->assertJsonPath('data.opd_unit_id', null);

    expect(District::findOrFail($districtId)->name)->toBe('Kecamatan Uji Cilacap Barat')
        ->and(Village::findOrFail($villageId)->type)->toBe('KELURAHAN');
});

it('serves read-only filtered system audit logs for SUPER_ADMIN and INSPEKTORAT and rejects write methods (TASK-BE-053)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $superAdmin = User::where('email', 'superadmin@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $petugas = User::where('role', UserRole::PETUGAS->value)->firstOrFail();
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();

    // 1. Auth 401 & RBAC 403
    $this->getJson('/api/v1/admin/audit-logs')->assertStatus(401);
    $this->actingAs($adminDpupr, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
    $this->actingAs($petugas, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
    $this->actingAs($warga, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertStatus(403);

    // 2. Accessible by SUPER_ADMIN and INSPEKTORAT
    $this->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/v1/admin/audit-logs')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.per_page', 20);

    $heroReport = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $todayWib = now('Asia/Jakarta')->toDateString();

    $filtered = $this->actingAs($auditor, 'sanctum')
        ->getJson("/api/v1/admin/audit-logs?report_id={$heroReport->id}&action_type=SUBMITTED&from=2025-01-01&to={$todayWib}");

    $filtered->assertStatus(200)
        ->assertJsonPath('success', true);

    foreach ($filtered->json('data') as $item) {
        expect($item['report_id'])->toBe($heroReport->id)
            ->and($item['action_type'])->toBe('SUBMITTED');
    }

    // 3. Invalid filter validation -> 422
    $this->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/v1/admin/audit-logs?report_id=abc&from=2026-12-31&to=2026-01-01')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 4. Strictly read-only: POST, PUT, PATCH, DELETE must return 405 Method Not Allowed
    $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/audit-logs', [])->assertStatus(405);
    $this->actingAs($superAdmin, 'sanctum')->putJson('/api/v1/admin/audit-logs', [])->assertStatus(405);
    $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/audit-logs', [])->assertStatus(405);
    $this->actingAs($superAdmin, 'sanctum')->deleteJson('/api/v1/admin/audit-logs')->assertStatus(405);
});
