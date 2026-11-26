<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware(['auth:sanctum', 'role:SUPER_ADMIN'])
        ->get('/api/v1/_test/role-super-admin', fn (): JsonResponse => ApiResponse::success(['ok' => true]));

    Route::middleware(['auth:sanctum', 'role:ADMIN_UNIT,INSPEKTORAT'])
        ->get('/api/v1/_test/role-multi', fn (): JsonResponse => ApiResponse::success(['ok' => true]));
});

it('rejects unauthenticated requests with 401 and wrong role with 403', function (): void {
    $this->getJson('/api/v1/_test/role-super-admin')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $warga = User::create([
        'name' => 'Warga Biasa',
        'email' => 'warga.role@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);

    $this->actingAs($warga, 'sanctum')
        ->getJson('/api/v1/_test/role-super-admin')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('allows matching role in single or multi-role middleware', function (): void {
    $inspector = User::create([
        'name' => 'Auditor',
        'email' => 'auditor.role@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::INSPEKTORAT,
    ]);

    $this->actingAs($inspector, 'sanctum')
        ->getJson('/api/v1/_test/role-multi')
        ->assertStatus(200)
        ->assertJsonPath('data.ok', true);
});

it('revokes Sanctum token and returns 401 when deactivated user attempts API access', function (): void {
    $user = User::create([
        'name' => 'User Nonaktif',
        'email' => 'inactive.role@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::ADMIN_UNIT,
        'is_active' => true,
    ]);

    $plainToken = $user->createToken('test-token')->plainTextToken;
    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(1);

    $user->update(['is_active' => false]);

    $this->withHeader('Authorization', "Bearer {$plainToken}")
        ->getJson('/api/v1/_test/role-multi')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);
});
