<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use App\Enums\UserRole;
use App\Models\Category;
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

it('enforces strict unit inbox isolation (404 cross-unit TC-SEC-02), secret reporter masking, and officer active workload list (TASK-BE-037)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $adminDlh = User::where('email', 'admin.dlh@pastara.id')->firstOrFail();
    $adminDisdikbud = User::where('email', 'admin.disdikbud@pastara.id')->firstOrFail();
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();

    // Non-ADMIN_UNIT rejected with 403
    $this->actingAs($warga, 'sanctum')
        ->getJson('/api/v1/unit/reports')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // Inbox strictly returns only DPUPR tickets
    $inbox = $this->actingAs($adminDpupr, 'sanctum')
        ->getJson('/api/v1/unit/reports?tab=apbd');

    $inbox->assertStatus(200)
        ->assertJsonPath('success', true);

    foreach ($inbox->json('data') as $item) {
        expect($item['opd_unit']['code'])->toBe('DPUPR')
            ->and($item['status'])->toBe(ReportStatus::BUDGET_SCHEDULED->value);
    }

    // TC-SEC-02: Admin DLH accessing DPUPR report detail receives HTTP 404 NOT_FOUND
    $dpuprReport = Report::where('ticket_code', 'PST-SADA2027')->firstOrFail();
    $this->actingAs($adminDlh, 'sanctum')
        ->getJson("/api/v1/unit/reports/{$dpuprReport->id}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // Admin Disdikbud viewing secret ticket PST-PURW2027 sees anonymized reporter (Hukum 4)
    $secretReport = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $this->actingAs($adminDisdikbud, 'sanctum')
        ->getJson("/api/v1/unit/reports/{$secretReport->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.ticket_code', 'PST-PURW2027')
        ->assertJsonPath('data.reporter.name', 'Pelapor Rahasia')
        ->assertJsonPath('data.reporter.email', null)
        ->assertJsonPath('data.reporter.phone', null);

    // GET /unit/officers returns only active PETUGAS in admin's unit with active_tasks_count
    $inactiveOfficer = User::create([
        'name' => 'Petugas Nonaktif DPUPR',
        'email' => 'petugas.off.dpupr@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $adminDpupr->opd_unit_id,
        'is_active' => false,
    ]);

    $officersResponse = $this->actingAs($adminDpupr, 'sanctum')
        ->getJson('/api/v1/unit/officers');

    $officersResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $officers = collect($officersResponse->json('data'));
    expect($officers->pluck('email')->all())->toContain('petugas.dpupr@pastara.id')
        ->and($officers->pluck('email')->all())->not->toContain($inactiveOfficer->email, 'petugas.dishub@pastara.id')
        ->and($officers->first()['active_tasks_count'])->toBeInt();
});

it('assigns Track 1 operational officer, blocks cross-unit officer (422) and cross-unit report (404), and reassigns without altering SLA deadline (TASK-BE-038, BR-030)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $adminDlh = User::where('email', 'admin.dlh@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();

    $secondOfficerDpupr = User::create([
        'name' => 'Petugas Kedua DPUPR',
        'email' => 'petugas2.dpupr@pastara.id',
        'phone' => '081200009999',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $adminDpupr->opd_unit_id,
        'is_active' => true,
    ]);

    $dpuprRouted = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();

    // Cross-unit admin attempting assign gets 404 BEFORE body validation (TC-SEC-02)
    $this->actingAs($adminDlh, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/assign", [
            'officer_id' => $officerDishub->id,
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // Same-unit admin assigning officer from another unit (Dishub) is rejected with 422
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/assign", [
            'officer_id' => $officerDishub->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['officer_id']]]);

    // Valid Track 1 operational assignment -> IN_PROGRESS + SLA starts
    $assignResponse = $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/assign", [
            'officer_id' => $officerDpupr->id,
            'note' => 'Segera lakukan tambal sulam aspal prioritas.',
        ]);

    $assignResponse->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::IN_PROGRESS->value)
        ->assertJsonPath('data.resolution_track', ResolutionTrack::OPERATIONAL->value)
        ->assertJsonPath('data.assigned_officer.id', $officerDpupr->id);

    $dpuprRouted->refresh();
    $originalDeadline = $dpuprRouted->sla_deadline_at?->toIso8601ZuluString();
    expect($originalDeadline)->not->toBeNull();

    // Attempting assign again on IN_PROGRESS returns 409 INVALID_STATE_TRANSITION
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/assign", [
            'officer_id' => $secondOfficerDpupr->id,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    // Reassign to same officer -> 422
    $this->actingAs($adminDpupr, 'sanctum')
        ->patchJson("/api/v1/unit/reports/{$dpuprRouted->id}/reassign-officer", [
            'officer_id' => $officerDpupr->id,
            'reason' => 'Pengalihan petugas tidak valid karena orang yang sama.',
        ])
        ->assertStatus(422);

    // Valid reassign to second officer in DPUPR preserves sla_deadline_at unchanged (BR-030)
    $reassignResponse = $this->actingAs($adminDpupr, 'sanctum')
        ->patchJson("/api/v1/unit/reports/{$dpuprRouted->id}/reassign-officer", [
            'officer_id' => $secondOfficerDpupr->id,
            'reason' => 'Petugas sebelumnya sedang cuti mendadak sehingga dialihkan.',
        ]);

    $reassignResponse->assertStatus(200)
        ->assertJsonPath('data.assigned_officer.id', $secondOfficerDpupr->id)
        ->assertJsonPath('data.sla.sla_deadline_at', $originalDeadline);

    expect($dpuprRouted->auditLogs()->where('action_type', AuditActionType::OFFICER_CHANGED)->exists())->toBeTrue();
});

it('rejects reports with reason_code and reroutes CAT-05 village tickets to DPUPR with recalculated CAT-01 snapshots (TASK-BE-041)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $adminDesa = User::where('email', 'admin.sadabumi@pastara.id')->firstOrFail();
    $dpuprUnit = OpdUnit::where('code', 'DPUPR')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $cat05 = Category::where('code', 'CAT-05')->firstOrFail();
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();

    // 1. Reject a DPUPR ROUTED_TO_UNIT report
    $toReject = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();

    $rejectResponse = $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$toReject->id}/reject", [
            'reason_code' => RejectionReasonCode::HOAX->value,
            'note' => 'Hasil verifikasi awal menunjukkan foto dan titik lokasi tidak sesuai fakta lapangan.',
        ]);

    $rejectResponse->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::REJECTED->value)
        ->assertJsonPath('data.rejection_reason_code', RejectionReasonCode::HOAX->value);

    // 2. Reroute a CAT-05 report in Admin Desa's unit -> DPUPR with CAT-01 (recalculates snapshots)
    $desaReport = Report::create([
        'ticket_code' => 'PST-REROUTE1',
        'reporter_id' => $warga->id,
        'category_id' => $cat05->id,
        'opd_unit_id' => $adminDesa->opd_unit_id,
        'district_id' => Report::firstOrFail()->district_id,
        'village_id' => Report::firstOrFail()->village_id,
        'title' => 'Jalan kabupaten rusak dilaporkan ke kategori desa',
        'description_sanitized' => 'Kerusakan jalan ternyata kewenangan kabupaten bukan jalan desa.',
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'status' => ReportStatus::ROUTED_TO_UNIT,
        'issue_type_snapshot' => $cat05->issue_type,
        'duplicate_radius_snapshot_m' => $cat05->duplicate_radius_m,
        'geotag_tolerance_snapshot_m' => $cat05->geotag_tolerance_m,
        'sla_hours_snapshot' => $cat05->default_sla_hours,
    ]);

    // Rerouting to same unit rejected with 422
    $this->actingAs($adminDesa, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$desaReport->id}/reroute", [
            'target_opd_unit_id' => $adminDesa->opd_unit_id,
            'note' => 'Mencoba mengalihkan ke unit yang sama harus ditolak validasi.',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['target_opd_unit_id']]]);

    // Valid reroute to DPUPR + CAT-01 updates unit, category, and parameter snapshots
    $rerouteResponse = $this->actingAs($adminDesa, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$desaReport->id}/reroute", [
            'target_opd_unit_id' => $dpuprUnit->id,
            'new_category_id' => $cat01->id,
            'note' => 'Ruas jalan ini merupakan jalan kabupaten kewenangan penuh DPUPR Cilacap.',
        ]);

    $rerouteResponse->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::ROUTED_TO_UNIT->value)
        ->assertJsonPath('data.opd_unit.code', 'DPUPR')
        ->assertJsonPath('data.category.code', 'CAT-01')
        ->assertJsonPath('data.sla.sla_hours_snapshot', $cat01->default_sla_hours)
        ->assertJsonPath('data.geotag.geotag_tolerance_snapshot_m', $cat01->geotag_tolerance_m);

    // After reroute, Admin Desa can no longer access the report (404 cross-unit isolation)
    $this->actingAs($adminDesa, 'sanctum')
        ->getJson("/api/v1/unit/reports/{$desaReport->id}")
        ->assertStatus(404);
});
