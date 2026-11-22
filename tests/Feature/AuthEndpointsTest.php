<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('registers a citizen account, forces WARGA role against injection, and rejects duplicate email (TC-F-01, TC-SEC-04)', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Budi Santoso',
        'email' => 'Budi.Santoso@Pastara.id',
        'phone' => '081234567890',
        'password' => 'Pastara2027!',
        'password_confirmation' => 'Pastara2027!',
        'role' => 'SUPER_ADMIN',
        'opd_unit_id' => 1,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.role', UserRole::WARGA->value)
        ->assertJsonPath('data.user.email', 'budi.santoso@pastara.id')
        ->assertJsonPath('data.user.opd_unit_id', null)
        ->assertJsonStructure(['success', 'server_time', 'data' => ['token', 'user']]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Budi Duplikat',
        'email' => 'budi.santoso@pastara.id',
        'password' => 'Pastara2027!',
        'password_confirmation' => 'Pastara2027!',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.fields.email.0', 'Email sudah terdaftar.');
});

it('authenticates valid users, rejects wrong password or inactive accounts, and enforces 5/min login throttle (TC-F-02)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin.dpupr@pastara.id',
        'password' => 'Pastara2027!',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.user.role', UserRole::ADMIN_UNIT->value)
        ->assertJsonPath('data.user.opd_unit.code', 'DPUPR');

    $inactive = User::where('email', 'admin.dlh@pastara.id')->firstOrFail();
    $inactive->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin.dlh@pastara.id',
        'password' => 'Pastara2027!',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED')
        ->assertJsonPath('error.message', 'Email atau password salah.');

    // 5 failed attempts allowed, 6th triggers HTTP 429
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'bruteforce@pastara.id',
            'password' => 'SalahTerus123',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bruteforce@pastara.id',
        'password' => 'SalahTerus123',
    ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

it('supports /auth/me with unread count, profile update without role escalation, password change revoking other tokens, and logout', function (): void {
    $user = User::create([
        'name' => 'Warga Awal',
        'email' => 'warga.profile@pastara.id',
        'phone' => '081200001111',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);

    Notification::create([
        'user_id' => $user->id,
        'type' => NotificationType::REPORT_ROUTED,
        'title' => 'Info',
        'message' => 'Notifikasi 1 belum dibaca',
        'created_at' => now('UTC'),
    ]);

    $tokenOtherDevice = $user->createToken('device-1')->plainTextToken;
    $tokenCurrentDevice = $user->createToken('device-2')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenCurrentDevice}")
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200)
        ->assertJsonPath('data.user.email', 'warga.profile@pastara.id')
        ->assertJsonPath('data.unread_notifications_count', 1);

    $this->withHeader('Authorization', "Bearer {$tokenCurrentDevice}")
        ->patchJson('/api/v1/auth/profile', [
            'name' => 'Warga Terbarui',
            'phone' => '+6281234567899',
            'role' => 'SUPER_ADMIN',
            'email' => 'hacked@pastara.id',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Warga Terbarui')
        ->assertJsonPath('data.phone', '+6281234567899')
        ->assertJsonPath('data.role', 'WARGA')
        ->assertJsonPath('data.email', 'warga.profile@pastara.id');

    $this->withHeader('Authorization', "Bearer {$tokenCurrentDevice}")
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'Pastara2027!',
            'password' => 'BaruAman2027!',
            'password_confirmation' => 'BaruAman2027!',
        ])
        ->assertStatus(200);

    $user->refresh();
    expect(Hash::check('BaruAman2027!', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);

    // Other device token was revoked (401)
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$tokenOtherDevice}")
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);

    // Current device token still works, then logout revokes it
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$tokenCurrentDevice}")
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(200);

    app('auth')->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$tokenCurrentDevice}")
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});
