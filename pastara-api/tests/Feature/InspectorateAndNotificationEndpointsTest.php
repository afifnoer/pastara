<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\DisputeStatus;
use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

it('serves Inspectorate cross-OPD oversight dashboard with full secret reporter identity and internal scorecard (TASK-BE-048, AC-057, AC-060)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $dpupr = OpdUnit::where('code', 'DPUPR')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();

    // 1. Auth 401 & RBAC 403
    $this->getJson('/api/v1/inspectorate/reports')->assertStatus(401);
    $this->getJson('/api/v1/inspectorate/scorecard')->assertStatus(401);
    $this->actingAs($adminDpupr, 'sanctum')->getJson('/api/v1/inspectorate/reports')->assertStatus(403);
    $this->actingAs($warga, 'sanctum')->getJson('/api/v1/inspectorate/scorecard')->assertStatus(403);

    // 2. Non-integer / BIGINT overflow FK filter rejected cleanly with 422
    $this->actingAs($auditor, 'sanctum')
        ->getJson('/api/v1/inspectorate/reports?opd_unit_id=abc&category_id=99999999999999999999999')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 3. tab=all across all units reveals full reporter identity on secret ticket PST-PURW2027 (AuditReportResource)
    $secret = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $secretList = $this->actingAs($auditor, 'sanctum')
        ->getJson("/api/v1/inspectorate/reports?tab=all&opd_unit_id={$secret->opd_unit_id}&category_id={$secret->category_id}");

    $secretList->assertStatus(200)
        ->assertJsonPath('success', true);

    $purwItem = collect($secretList->json('data'))->firstWhere('ticket_code', 'PST-PURW2027');
    expect($purwItem)->not->toBeNull()
        ->and($purwItem['reporter']['email'])->toBe('warga.demo@pastara.id')
        ->and($purwItem['reporter']['name'])->not->toBe('Pelapor Rahasia');

    // 4. tab=disputed contains PST-VETO0001, tab=sla_overdue & tab=milestone_overdue work cleanly
    $disputedRes = $this->actingAs($auditor, 'sanctum')
        ->getJson('/api/v1/inspectorate/reports?tab=disputed');
    $disputedRes->assertStatus(200);
    expect(collect($disputedRes->json('data'))->pluck('ticket_code')->all())->toContain('PST-VETO0001');

    $this->actingAs($auditor, 'sanctum')
        ->getJson("/api/v1/inspectorate/reports?tab=sla_overdue&opd_unit_id={$dpupr->id}&category_id={$cat01->id}")
        ->assertStatus(200);

    $this->actingAs($auditor, 'sanctum')
        ->getJson('/api/v1/inspectorate/reports?tab=milestone_overdue')
        ->assertStatus(200);

    // 5. Internal scorecard includes secret_reports summary & unit secret_count + sorting
    $scorecardRes = $this->actingAs($auditor, 'sanctum')
        ->getJson('/api/v1/inspectorate/scorecard?sort=compliance_desc');

    $scorecardRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'period' => ['month', 'year', 'timezone'],
                'summary' => [
                    'total_reports',
                    'secret_reports',
                    'budget_scheduled_apbd',
                    'verified_closed_physical',
                    'closed_by_audit',
                    'disputed_reports',
                    'sla_overdue_reports',
                    'milestone_overdue_reports',
                ],
                'opd_scorecard',
            ],
        ]);

    expect($scorecardRes->json('data.summary.secret_reports'))->toBeGreaterThanOrEqual(1)
        ->and(array_key_exists('secret_count', $scorecardRes->json('data.opd_scorecard.0')))->toBeTrue();
});

it('resolves citizen disputes via TR-11 REOPENED (cycle 2, is_late=true) and TR-12 CLOSED with strict RBAC & 409 guards (TASK-BE-049, AC-052, AC-058, AC-059)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();

    $vetoReport = Report::with('disputes')->where('ticket_code', 'PST-VETO0001')->firstOrFail();
    $pendingDispute = $vetoReport->disputes->where('status', DisputeStatus::PENDING)->firstOrFail();

    // 1. Auth 401, RBAC 403, missing dispute 404
    $this->postJson("/api/v1/inspectorate/disputes/{$pendingDispute->id}/resolve", [])->assertStatus(401);
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/inspectorate/disputes/{$pendingDispute->id}/resolve", [])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
    $this->actingAs($auditor, 'sanctum')
        ->postJson('/api/v1/inspectorate/disputes/999999/resolve', [])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // 2. Validation 422: REOPENED without reopen_sla_hours / invalid hours / short notes
    $this->actingAs($auditor, 'sanctum')
        ->postJson("/api/v1/inspectorate/disputes/{$pendingDispute->id}/resolve", [
            'decision' => 'REOPENED',
            'audit_notes' => 'Terlalu singkat',
            'reopen_sla_hours' => 36,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['audit_notes', 'reopen_sla_hours']]]);

    // 3. TR-11 REOPENED (48h) on PST-VETO0001 -> current_cycle = 2, is_late = true, REOPENED_BY_AUDIT
    $reopenRes = $this->actingAs($auditor, 'sanctum')
        ->postJson("/api/v1/inspectorate/disputes/{$pendingDispute->id}/resolve", [
            'decision' => 'REOPENED',
            'audit_notes' => 'Foto bukti geotag menyimpang 620 meter dari titik aduan. Unit wajib mengerjakan ulang dalam 48 jam.',
            'reopen_sla_hours' => 48,
        ]);

    $reopenRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.ticket_code', 'PST-VETO0001')
        ->assertJsonPath('data.status', ReportStatus::REOPENED_BY_AUDIT->value)
        ->assertJsonPath('data.current_cycle', 2)
        ->assertJsonPath('data.sla.is_late', true)
        ->assertJsonPath('data.sla.is_sla_overdue', false);

    $pendingDispute->refresh();
    expect($pendingDispute->status)->toBe(DisputeStatus::REOPENED)
        ->and($pendingDispute->reopen_sla_hours)->toBe(48)
        ->and($vetoReport->auditLogs()->where('action_type', AuditActionType::AUDIT_REOPENED)->exists())->toBeTrue()
        ->and(Notification::where('report_id', $vetoReport->id)->where('type', NotificationType::AUDIT_DECISION_MADE->value)->exists())->toBeTrue();

    // 4. Re-resolving already decided dispute -> 409 Conflict
    $this->actingAs($auditor, 'sanctum')
        ->postJson("/api/v1/inspectorate/disputes/{$pendingDispute->id}/resolve", [
            'decision' => 'CLOSED',
            'audit_notes' => 'Mencoba memutus ulang sengketa yang sudah diputus harus ditolak 409.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    // 5. TR-12 CLOSED on a fresh DISPUTED ticket -> CLOSED_BY_AUDIT + sla_stopped_at
    $otherReport = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $otherReport->update(['status' => ReportStatus::DISPUTED]);
    $secondDispute = Dispute::create([
        'report_id' => $otherReport->id,
        'cycle' => $otherReport->current_cycle,
        'raised_by_user_id' => $warga->id,
        'citizen_reason' => 'Warga merasa hasil kurang rapi namun secara teknis fungsional.',
        'status' => DisputeStatus::PENDING,
    ]);

    $closeRes = $this->actingAs($auditor, 'sanctum')
        ->postJson("/api/v1/inspectorate/disputes/{$secondDispute->id}/resolve", [
            'decision' => 'CLOSED',
            'audit_notes' => 'Pemeriksaan lapangan membuktikan lampu PJU sudah menyala sesuai standar teknis Dishub.',
        ]);

    $closeRes->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::CLOSED_BY_AUDIT->value);

    expect($otherReport->fresh()->sla_stopped_at)->not->toBeNull()
        ->and($secondDispute->fresh()->status)->toBe(DisputeStatus::CLOSED)
        ->and($otherReport->auditLogs()->where('action_type', AuditActionType::AUDIT_CLOSED)->exists())->toBeTrue();
});

it('lists strictly user-owned in-app notifications with unread_count and marks specific or all notifications read safely (TASK-BE-050, BR-041, AC-064)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $otherUser = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $sampleReport = Report::firstOrFail();

    // Unauthenticated -> 401
    $this->getJson('/api/v1/notifications')->assertStatus(401);
    $this->postJson('/api/v1/notifications/read-all')->assertStatus(401);

    // Seed controlled unread notifications for both users
    Notification::whereIn('user_id', [$warga->id, $otherUser->id])->delete();

    $n1 = Notification::create([
        'user_id' => $warga->id,
        'report_id' => $sampleReport->id,
        'type' => NotificationType::REPORT_ROUTED,
        'title' => 'Notif Warga 1',
        'message' => 'Laporan Anda telah diteruskan ke unit kerja.',
        'read_at' => null,
        'created_at' => now('UTC')->subMinutes(5),
    ]);
    $n2 = Notification::create([
        'user_id' => $warga->id,
        'report_id' => $sampleReport->id,
        'type' => NotificationType::SIGN_OFF_REQUESTED,
        'title' => 'Notif Warga 2',
        'message' => 'Mohon konfirmasi penyelesaian laporan.',
        'read_at' => null,
        'created_at' => now('UTC')->subMinute(),
    ]);
    $otherNotif = Notification::create([
        'user_id' => $otherUser->id,
        'report_id' => $sampleReport->id,
        'type' => NotificationType::REPORT_ROUTED,
        'title' => 'Notif Admin Lain',
        'message' => 'Notifikasi milik akun lain tidak boleh tersentuh.',
        'read_at' => null,
        'created_at' => now('UTC'),
    ]);

    // 1. GET /notifications returns ONLY authenticated user's notifications + unread_count = 2
    $listRes = $this->actingAs($warga, 'sanctum')->getJson('/api/v1/notifications');
    $listRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.unread_count', 2)
        ->assertJsonPath('meta.total', 2);

    $returnedIds = collect($listRes->json('data.items'))->pluck('id')->all();
    expect($returnedIds)->toContain($n1->id, $n2->id)
        ->and($returnedIds)->not->toContain($otherNotif->id);

    // 2. Attempting to mark ANOTHER user's notification ID marks 0 and leaves other user untouched (BR-041)
    $this->actingAs($warga, 'sanctum')
        ->postJson('/api/v1/notifications/read-all', [
            'notification_ids' => [$otherNotif->id],
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.marked_count', 0)
        ->assertJsonPath('data.unread_count', 2);
    expect($otherNotif->fresh()->read_at)->toBeNull();

    // 3. Mark single notification ($n1) as read -> unread_count drops to 1
    $this->actingAs($warga, 'sanctum')
        ->postJson('/api/v1/notifications/read-all', [
            'notification_ids' => [$n1->id],
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.marked_count', 1)
        ->assertJsonPath('data.unread_count', 1);

    // 4. unread_only=true returns only $n2
    $unreadRes = $this->actingAs($warga, 'sanctum')->getJson('/api/v1/notifications?unread_only=true');
    $unreadRes->assertStatus(200)
        ->assertJsonPath('data.unread_count', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.items.0.id', $n2->id);

    // 5. Mark all remaining unread notifications -> unread_count becomes 0
    $this->actingAs($warga, 'sanctum')
        ->postJson('/api/v1/notifications/read-all')
        ->assertStatus(200)
        ->assertJsonPath('data.marked_count', 1)
        ->assertJsonPath('data.unread_count', 0);

    expect($otherNotif->fresh()->read_at)->toBeNull();
});
