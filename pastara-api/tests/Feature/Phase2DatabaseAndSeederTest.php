<?php

declare(strict_types=1);

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BudgetSchedule;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\District;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Rating;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\ReportUpvote;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates all 14 core tables and 9 performance indexes in PostgreSQL', function (): void {
    $tables = [
        'opd_units', 'districts', 'villages', 'categories', 'users',
        'reports', 'report_photos', 'report_upvotes', 'budget_schedules',
        'budget_milestones', 'disputes', 'ratings', 'notifications', 'audit_logs',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table {$table}");
    }

    $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public'"))
        ->pluck('indexname')
        ->all();

    expect($indexes)->toContain(
        'idx_reports_public_list',
        'idx_reports_haversine_bbox',
        'idx_reports_unit_inbox',
        'idx_reports_officer_tasks',
        'idx_reports_sla_scheduler',
        'idx_reports_signoff_scheduler',
        'idx_photos_report_cycle',
        'idx_notifications_user_unread',
        'idx_audit_logs_report_time',
    );
});

it('seeds master data, 11 default accounts, 5 hero tickets, and >= 50 total reports with valid relations', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    expect(OpdUnit::active()->count())->toBe(8)
        ->and(Category::active()->count())->toBe(7)
        ->and(District::count())->toBe(24)
        ->and(Village::count())->toBeGreaterThanOrEqual(48)
        ->and(Report::count())->toBeGreaterThanOrEqual(50);

    $defaultEmails = [
        'warga.demo@pastara.id',
        'admin.dpupr@pastara.id',
        'admin.dishub@pastara.id',
        'admin.dlh@pastara.id',
        'admin.disdikbud@pastara.id',
        'admin.sadabumi@pastara.id',
        'petugas.dpupr@pastara.id',
        'petugas.dishub@pastara.id',
        'petugas.dlh@pastara.id',
        'auditor.inspektorat@pastara.id',
        'superadmin@pastara.id',
    ];

    foreach ($defaultEmails as $email) {
        $user = User::where('email', $email)->first();
        expect($user)->not->toBeNull("Missing seeded user {$email}")
            ->and(Hash::check('Pastara2027!', $user->password))->toBeTrue();
    }

    $sada = Report::where('ticket_code', 'PST-SADA2027')->firstOrFail();
    $purw = Report::where('ticket_code', 'PST-PURW2027')->firstOrFail();
    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $veto = Report::where('ticket_code', 'PST-VETO0001')->firstOrFail();
    $dupl = Report::where('ticket_code', 'PST-DUPL0001')->firstOrFail();

    expect($sada->status)->toBe(ReportStatus::BUDGET_SCHEDULED)
        ->and($sada->budgetSchedule)->toBeInstanceOf(BudgetSchedule::class)
        ->and($sada->budgetSchedule->milestones)->not->toBeEmpty()
        ->and($purw->is_secret)->toBeTrue()
        ->and($purw->verifySecretToken('TKN-DEMO-2027'))->toBeTrue()
        ->and($purw->verifySecretToken('TKN-SALAH-0000'))->toBeFalse()
        ->and($escrow->status)->toBe(ReportStatus::PENDING_CITIZEN_SIGN_OFF)
        ->and($escrow->photos)->toHaveCount(2)
        ->and($veto->status)->toBe(ReportStatus::DISPUTED)
        ->and($veto->geotag_warning)->toBeTrue()
        ->and($veto->geotag_distance_m)->toBe(620)
        ->and($veto->photos)->toHaveCount(3)
        ->and($veto->disputes->first())->toBeInstanceOf(Dispute::class)
        ->and($dupl->upvote_count)->toBe(14)
        ->and($dupl->upvotes)->toHaveCount(14);

    // Verify all 14 models' basic relations resolve without errors
    expect($dupl->reporter->role)->toBe(UserRole::WARGA)
        ->and($dupl->category)->toBeInstanceOf(Category::class)
        ->and($dupl->opdUnit)->toBeInstanceOf(OpdUnit::class)
        ->and($dupl->assignedOfficer)->toBeInstanceOf(User::class)
        ->and($dupl->district)->toBeInstanceOf(District::class)
        ->and($dupl->village)->toBeInstanceOf(Village::class)
        ->and($dupl->photos->first())->toBeInstanceOf(ReportPhoto::class)
        ->and($dupl->upvotes->first())->toBeInstanceOf(ReportUpvote::class)
        ->and($dupl->auditLogs->first())->toBeInstanceOf(AuditLog::class)
        ->and(Rating::count())->toBeGreaterThan(0)
        ->and(Notification::count())->toBeGreaterThan(0);
});

it('rejects UPDATE and DELETE on audit_logs via PostgreSQL trigger trg_audit_logs_immutable', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $log = AuditLog::firstOrFail();

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['note' => 'manipulated'])))
        ->toThrow(QueryException::class, 'Table audit_logs is append-only');

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $log->id)->delete()))
        ->toThrow(QueryException::class, 'Table audit_logs is append-only');

    expect(fn () => $log->update(['note' => 'manipulated']))
        ->toThrow(LogicException::class);

    expect(fn () => $log->delete())
        ->toThrow(LogicException::class);

    expect(AuditLog::find($log->id))->not->toBeNull();
});
