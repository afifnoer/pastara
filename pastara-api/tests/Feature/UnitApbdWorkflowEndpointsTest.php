<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

it('validates APBD scheduling (422 without proposal_ref_number, 404 cross-unit) and transitions ROUTED_TO_UNIT to BUDGET_SCHEDULED with 7-weekday due date (TASK-BE-039)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $adminDlh = User::where('email', 'admin.dlh@pastara.id')->firstOrFail();

    $dpuprRouted = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();

    // Cross-unit admin gets 404 BEFORE validation
    $this->actingAs($adminDlh, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/schedule-apbd", [])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // Missing proposal_ref_number rejected with 422
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$dpuprRouted->id}/schedule-apbd", [
            'fiscal_year' => 2027,
            'target_quarter' => 'Q2',
            'note' => 'Pengajuan peningkatan struktur ruas jalan membutuhkan anggaran APBD.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['proposal_ref_number']]]);

    // Valid Track 2 APBD scheduling with PDF proof
    $pdfProof = UploadedFile::fake()->createWithContent(
        'usulan-rka.pdf',
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
    );

    $response = $this->actingAs($adminDpupr, 'sanctum')
        ->post("/api/v1/unit/reports/{$dpuprRouted->id}/schedule-apbd", [
            'fiscal_year' => 2027,
            'proposal_ref_number' => 'RKA-DPUPR-2027-099',
            'target_quarter' => 'Q2',
            'estimated_budget' => 450000000,
            'note' => 'Rekonstruksi penuh memerlukan pagu APBD Tahun Anggaran 2027 Q2.',
            'document_proof' => $pdfProof,
        ], ['Accept' => 'application/json']);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ReportStatus::BUDGET_SCHEDULED->value)
        ->assertJsonPath('data.resolution_track', ResolutionTrack::APBD->value)
        ->assertJsonPath('data.budget_schedule.proposal_ref_number', 'RKA-DPUPR-2027-099')
        ->assertJsonPath('data.budget_schedule.target_quarter', 'Q2');

    $dpuprRouted->refresh()->load('budgetSchedule');
    expect($dpuprRouted->budgetSchedule->document_due_at)->not->toBeNull()
        ->and($dpuprRouted->budgetSchedule->document_uploaded_at)->not->toBeNull();
    Storage::disk('public')->assertExists($dpuprRouted->budgetSchedule->document_proof_path);
});

it('executes full Track 2 APBD milestone update and start-execution lifecycle on PST-SADA2027 (TASK-BE-040, TC-F-11)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $sada = Report::with('budgetSchedule')->where('ticket_code', 'PST-SADA2027')->firstOrFail();

    // Simulate overdue APBD milestone flag (missing RKA doc past due)
    $sada->update(['is_milestone_overdue' => true]);
    $sada->budgetSchedule->update([
        'document_proof_path' => null,
        'document_uploaded_at' => null,
        'document_due_at' => now('UTC')->subDays(3),
    ]);

    // 1. POST /apbd-milestones with RKA document proof + attachment clears is_milestone_overdue
    $rkaDoc = UploadedFile::fake()->createWithContent(
        'rka-sadabumi-final.pdf',
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
    );
    $attachment = UploadedFile::fake()->createWithContent(
        'rab-lampiran.pdf',
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
    );

    $milestoneResponse = $this->actingAs($adminDpupr, 'sanctum')
        ->post("/api/v1/unit/reports/{$sada->id}/apbd-milestones", [
            'quarter_label' => '2027-Q1',
            'progress_note' => 'Dokumen RKA dan RAB final telah disahkan oleh TAPD Kabupaten Cilacap dan siap tender.',
            'document_proof' => $rkaDoc,
            'attachment' => $attachment,
        ], ['Accept' => 'application/json']);

    $milestoneResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ReportStatus::BUDGET_SCHEDULED->value)
        ->assertJsonPath('data.sla.is_milestone_overdue', false)
        ->assertJsonCount(2, 'data.budget_schedule.milestones');

    expect($sada->auditLogs()->where('action_type', AuditActionType::MILESTONE_UPDATED)->exists())->toBeTrue();

    // 2. Start execution with cross-unit officer rejected 422
    $targetDate = CarbonImmutable::now('Asia/Jakarta')->addDays(30)->toDateString();

    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$sada->id}/start-execution", [
            'execution_target_date' => $targetDate,
            'officer_id' => $officerDishub->id,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['officer_id']]]);

    // 3. Valid start-execution transitions PST-SADA2027 -> IN_PROGRESS with sla_deadline_at = targetDate 23:59:59 WIB
    $execResponse = $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$sada->id}/start-execution", [
            'execution_target_date' => $targetDate,
            'officer_id' => $officerDpupr->id,
            'note' => 'Kontraktor dan tim pengawas DPUPR mulai mobilisasi alat berat ke Sadabumi.',
        ]);

    $expectedDeadlineUtc = CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->endOfDay()->utc()->toIso8601ZuluString();

    $execResponse->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::IN_PROGRESS->value)
        ->assertJsonPath('data.assigned_officer.id', $officerDpupr->id)
        ->assertJsonPath('data.sla.is_milestone_overdue', false)
        ->assertJsonPath('data.sla.sla_deadline_at', $expectedDeadlineUtc)
        ->assertJsonPath('data.budget_schedule.execution_target_date', $targetDate);

    // 4. Attempting milestone update after moving to IN_PROGRESS returns 409 Conflict
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$sada->id}/apbd-milestones", [
            'quarter_label' => '2027-Q2',
            'progress_note' => 'Mencoba menambah milestone setelah eksekusi berjalan harus ditolak 409.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
});
