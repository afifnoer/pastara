<?php

declare(strict_types=1);

use App\Enums\ReportStatus;
use App\Models\OpdUnit;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

it('paginates, searches, filters, and sorts non-secret public reports while excluding secret tickets (AC-007, BR-026)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $expectedPublicTotal = Report::query()->where('is_secret', false)->count();
    $secretCodes = Report::query()->where('is_secret', true)->pluck('ticket_code')->all();

    expect($secretCodes)->toContain('PST-PURW2027')
        ->and($expectedPublicTotal)->toBeGreaterThan(20);

    // Page 1 returns max 20 items & accurate pagination metadata
    $page1 = $this->getJson('/api/v1/public/reports');
    $page1->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', $expectedPublicTotal);

    $page1Codes = collect($page1->json('data'))->pluck('ticket_code')->all();
    foreach ($secretCodes as $secretCode) {
        expect($page1Codes)->not->toContain($secretCode);
    }

    // Search q matches non-secret tickets, hides secret tickets
    $searchEscrow = $this->getJson('/api/v1/public/reports?q=PST-ESCROW01');
    $searchEscrow->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ticket_code', 'PST-ESCROW01');

    $searchSecret = $this->getJson('/api/v1/public/reports?q=PST-PURW2027');
    $searchSecret->assertStatus(200)
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');

    // Filters: category_id, status, opd_unit_id, district_id
    $sada = Report::query()->where('ticket_code', 'PST-SADA2027')->firstOrFail();
    $filtered = $this->getJson('/api/v1/public/reports?'.http_build_query([
        'category_id' => $sada->category_id,
        'status' => ReportStatus::BUDGET_SCHEDULED->value,
        'opd_unit_id' => $sada->opd_unit_id,
        'district_id' => $sada->district_id,
    ]));

    $filtered->assertStatus(200);
    $filteredCodes = collect($filtered->json('data'))->pluck('ticket_code')->all();
    expect($filteredCodes)->toContain('PST-SADA2027')
        ->and($filteredCodes)->not->toContain('PST-PURW2027');

    // Filter: overdue_only=1 returns only overdue reports
    $overdueResponse = $this->getJson('/api/v1/public/reports?overdue_only=1');
    $overdueResponse->assertStatus(200);
    $overdueItems = collect($overdueResponse->json('data'));
    expect($overdueItems->isNotEmpty())->toBeTrue();
    $overdueItems->each(function (array $item): void {
        expect($item['sla']['is_sla_overdue'] || $item['sla']['is_milestone_overdue'])->toBeTrue();
    });

    // Sort: upvotes orders descending
    $upvotesResponse = $this->getJson('/api/v1/public/reports?sort=upvotes');
    $upvotesResponse->assertStatus(200);
    $counts = collect($upvotesResponse->json('data'))->pluck('upvote_count')->all();
    $sortedCounts = $counts;
    rsort($sortedCounts);
    expect($counts)->toBe($sortedCounts);

    // Sort: sla places overdue tickets before non-overdue
    $slaResponse = $this->getJson('/api/v1/public/reports?sort=sla');
    $slaResponse->assertStatus(200);
    $slaItems = collect($slaResponse->json('data'));
    $firstOverdue = $slaItems->first();
    expect($firstOverdue['sla']['is_sla_overdue'])->toBeTrue();
});

it('shows public report detail for non-secret tickets and returns 404 for secret tickets (AC-008, BR-026)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $publicDetail = $this->getJson('/api/v1/public/reports/pst-escrow01');
    $publicDetail->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.ticket_code', 'PST-ESCROW01')
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value);

    $apbdDetail = $this->getJson('/api/v1/public/reports/PST-SADA2027');
    $apbdDetail->assertStatus(200)
        ->assertJsonPath('data.ticket_code', 'PST-SADA2027')
        ->assertJsonPath('data.status', ReportStatus::BUDGET_SCHEDULED->value);

    // Secret ticket MUST NOT be exposed via public detail endpoint
    $secretAttempt = $this->getJson('/api/v1/public/reports/PST-PURW2027');
    $secretAttempt->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $unknownAttempt = $this->getJson('/api/v1/public/reports/PST-NOTFOUND');
    $unknownAttempt->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('tracks public and secret tickets safely without leaking reporter identity and enforces 10/min rate limit (AC-009, AC-010)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    // 1. Non-secret ticket returns report detail directly
    $nonSecretTrack = $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.1'])
        ->postJson('/api/v1/public/track', [
            'ticket_code' => 'PST-ESCROW01',
        ]);

    $nonSecretTrack->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.requires_secret_token', false)
        ->assertJsonPath('data.ticket_code', 'PST-ESCROW01')
        ->assertJsonPath('data.report.ticket_code', 'PST-ESCROW01');

    // 2. Secret ticket without token prompts for secret_token cleanly
    $promptTrack = $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.2'])
        ->postJson('/api/v1/public/track', [
            'ticket_code' => 'PST-PURW2027',
        ]);

    $promptTrack->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.requires_secret_token', true)
        ->assertJsonPath('data.ticket_code', 'PST-PURW2027')
        ->assertJsonMissingPath('data.report');

    // 3. Wrong token vs unknown ticket both return uniform 404 anti-enumeration message
    $wrongToken = $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.3'])
        ->postJson('/api/v1/public/track', [
            'ticket_code' => 'PST-PURW2027',
            'secret_token' => 'TKN-SALAH-9999',
        ]);

    $unknownTicket = $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.4'])
        ->postJson('/api/v1/public/track', [
            'ticket_code' => 'PST-NONE0001',
            'secret_token' => 'TKN-DEMO-2027',
        ]);

    $wrongToken->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Tiket tidak ditemukan atau token tidak valid.');

    $unknownTicket->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Tiket tidak ditemukan atau token tidak valid.');

    // 4. Valid secret token unlocks detail with anonymized reporter ("Pelapor Rahasia")
    $secretReport = Report::query()->with('reporter')->where('ticket_code', 'PST-PURW2027')->firstOrFail();

    $validTrack = $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.5'])
        ->postJson('/api/v1/public/track', [
            'ticket_code' => 'pst-purw2027',
            'secret_token' => 'TKN-DEMO-2027',
        ]);

    $validTrack->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.requires_secret_token', false)
        ->assertJsonPath('data.ticket_code', 'PST-PURW2027')
        ->assertJsonPath('data.report.ticket_code', 'PST-PURW2027')
        ->assertJsonPath('data.report.reporter.name', 'Pelapor Rahasia');

    $rawJson = $validTrack->getContent();
    expect($rawJson)->not->toContain((string) $secretReport->reporter?->email);

    // 5. Rate limiter blocks 11th request from same IP within 1 minute
    for ($i = 1; $i <= 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.99'])
            ->postJson('/api/v1/public/track', ['ticket_code' => 'PST-PURW2027'])
            ->assertStatus(200);
    }

    $throttled = $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.99'])
        ->postJson('/api/v1/public/track', ['ticket_code' => 'PST-PURW2027']);

    $throttled->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

it('returns honest global summary separating APBD scheduled from verified physical closed and OPD scorecard (AC-011, AC-012, BR-014)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $response = $this->getJson('/api/v1/public/scorecard');
    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.period.timezone', 'Asia/Jakarta');

    $summary = $response->json('data.summary');
    $expectedTotal = Report::query()->count();
    $expectedBudgetScheduled = Report::query()->where('status', ReportStatus::BUDGET_SCHEDULED->value)->count();
    $expectedPhysicalClosed = Report::query()->whereIn('status', [
        ReportStatus::VERIFIED_CLOSED->value,
        ReportStatus::AUTO_VERIFIED_CLOSED->value,
    ])->count();
    $expectedClosedByAudit = Report::query()->where('status', ReportStatus::CLOSED_BY_AUDIT->value)->count();

    expect($summary['total_reports'])->toBe($expectedTotal)
        ->and($summary['budget_scheduled_apbd'])->toBe($expectedBudgetScheduled)
        ->and($summary['budget_scheduled_apbd'])->toBeGreaterThanOrEqual(1)
        ->and($summary['verified_closed_physical'])->toBe($expectedPhysicalClosed)
        ->and($summary['closed_by_audit'])->toBe($expectedClosedByAudit)
        ->and($summary['rejected_tickets'] + $summary['budget_scheduled_apbd'] + $summary['verified_closed_physical'] + $summary['closed_by_audit'] + $summary['active_tickets'])
        ->toBe($summary['total_reports']);

    // Verify PST-SADA2027 is accounted under DPUPR budget_scheduled_count, NOT physical_closed_count
    $opdScorecard = collect($response->json('data.opd_scorecard'));
    expect($opdScorecard)->toHaveCount(OpdUnit::query()->active()->count())
        ->and($opdScorecard->sum('physical_closed_count'))->toBe($summary['verified_closed_physical'])
        ->and($opdScorecard->sum('closed_by_audit_count'))->toBe($summary['closed_by_audit'])
        ->and($opdScorecard->sum('overdue_count'))->toBe($summary['overdue_reports']);

    $dpupr = $opdScorecard->firstWhere('code', 'DPUPR');
    $dpuprBudgetCount = Report::query()
        ->where('opd_unit_id', OpdUnit::where('code', 'DPUPR')->value('id'))
        ->where('status', ReportStatus::BUDGET_SCHEDULED->value)
        ->count();
    $dpuprPhysicalClosedCount = Report::query()
        ->where('opd_unit_id', OpdUnit::where('code', 'DPUPR')->value('id'))
        ->whereIn('status', [
            ReportStatus::VERIFIED_CLOSED->value,
            ReportStatus::AUTO_VERIFIED_CLOSED->value,
        ])
        ->count();

    expect($dpupr)->not->toBeNull()
        ->and($dpupr['budget_scheduled_count'])->toBe($dpuprBudgetCount)
        ->and($dpupr['physical_closed_count'])->toBe($dpuprPhysicalClosedCount)
        ->and(array_key_exists('secret_count', $dpupr))->toBeFalse();

    // Verify period query filters work accurately
    $currentJakarta = CarbonImmutable::now('Asia/Jakarta');
    $currentMonthResponse = $this->getJson('/api/v1/public/scorecard?'.http_build_query([
        'month' => $currentJakarta->month,
        'year' => $currentJakarta->year,
    ]));
    $currentMonthResponse->assertStatus(200)
        ->assertJsonPath('data.period.month', $currentJakarta->month)
        ->assertJsonPath('data.period.year', $currentJakarta->year);
    expect($currentMonthResponse->json('data.summary.total_reports'))->toBeLessThanOrEqual($expectedTotal);

    $emptyFuture = $this->getJson('/api/v1/public/scorecard?month=12&year=2029');
    $emptyFuture->assertStatus(200)
        ->assertJsonPath('data.summary.total_reports', 0)
        ->assertJsonPath('data.summary.budget_scheduled_apbd', 0)
        ->assertJsonPath('data.summary.verified_closed_physical', 0);

    $this->getJson('/api/v1/public/scorecard?month=13')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});
