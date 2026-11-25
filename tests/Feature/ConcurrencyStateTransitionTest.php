<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\DisputeStatus;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Dispute;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportStateMachineService;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

function truncatePastaraTestTables(): void
{
    $tables = [
        'audit_logs',
        'notifications',
        'ratings',
        'disputes',
        'budget_milestones',
        'budget_schedules',
        'report_upvotes',
        'report_photos',
        'reports',
        'personal_access_tokens',
        'users',
        'categories',
        'villages',
        'districts',
        'opd_units',
    ];

    DB::statement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
}

/**
 * Spawn concurrent PHP CLI processes that boot Laravel's HTTP Kernel against the committed
 * testing database and fire HTTP JSON requests simultaneously past a shared timestamp barrier.
 *
 * @param  list<array{method: string, uri: string, token: string, payload: array<string, mixed>}>  $requests
 * @return list<array{status: int, body: array<string, mixed>, raw: string, stderr: string}>
 */
function dispatchConcurrentApiRequests(array $requests): array
{
    $basePathExport = var_export(base_path(), true);

    $workerScript = <<<PHP
<?php
declare(strict_types=1);

\$basePath = {$basePathExport};
require \$basePath . '/vendor/autoload.php';

\$input = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

\$app = require \$basePath . '/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\\Contracts\\Http\\Kernel::class);

// Synchronize workers to hit the database row lock at the same instant.
\$startAt = (float) \$input['start_at'];
\$remainingUs = (int) ((\$startAt - microtime(true)) * 1_000_000);
if (\$remainingUs > 0 && \$remainingUs < 5_000_000) {
    usleep(\$remainingUs);
}

\$request = Illuminate\\Http\\Request::create(
    \$input['uri'],
    \$input['method'],
    [],
    [],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . \$input['token'],
    ],
    json_encode(\$input['payload'], JSON_THROW_ON_ERROR)
);

\$response = \$kernel->handle(\$request);
\$kernel->terminate(\$request, \$response);

echo json_encode([
    'status' => \$response->getStatusCode(),
    'body' => json_decode((string) \$response->getContent(), true) ?? [],
    'raw' => (string) \$response->getContent(),
], JSON_THROW_ON_ERROR);
PHP;

    $scriptPath = storage_path('framework/testing/concurrency-worker-'.bin2hex(random_bytes(6)).'.php');
    if (! is_dir(dirname($scriptPath))) {
        mkdir(dirname($scriptPath), 0775, true);
    }
    file_put_contents($scriptPath, $workerScript);

    $env = array_merge(getenv() ?: [], $_ENV, [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_DATABASE' => (string) config('database.connections.'.config('database.default').'.database'),
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ]);

    // Filter to scalar env values for proc_open compatibility on Windows/Linux
    $cleanEnv = [];
    foreach ($env as $key => $val) {
        if (is_string($key) && (is_string($val) || is_int($val) || is_float($val))) {
            $cleanEnv[$key] = (string) $val;
        }
    }

    $startAt = microtime(true) + 0.6;
    $processes = [];

    try {
        foreach ($requests as $index => $req) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open(
                [PHP_BINARY, $scriptPath],
                $descriptors,
                $pipes,
                base_path(),
                $cleanEnv
            );

            if (! is_resource($proc)) {
                throw new RuntimeException("Failed to spawn concurrency worker #{$index}.");
            }

            fwrite($pipes[0], json_encode($req + ['start_at' => $startAt], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);

            $processes[$index] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($processes as $index => $entry) {
            $stdout = (string) stream_get_contents($entry['pipes'][1]);
            $stderr = (string) stream_get_contents($entry['pipes'][2]);
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            proc_close($entry['proc']);

            $decoded = json_decode($stdout, true);
            $results[$index] = [
                'status' => is_array($decoded) && isset($decoded['status']) ? (int) $decoded['status'] : 0,
                'body' => is_array($decoded) && isset($decoded['body']) && is_array($decoded['body']) ? $decoded['body'] : [],
                'raw' => $stdout,
                'stderr' => $stderr,
            ];
        }

        return $results;
    } finally {
        @unlink($scriptPath);
    }
}

beforeEach(function (): void {
    if (! RefreshDatabaseState::$migrated) {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
    }

    truncatePastaraTestTables();
    $this->seed(CilacapSimulationSeeder::class);
});

afterEach(function (): void {
    truncatePastaraTestTables();
});

it('TC-I-06: serializes two concurrent Admin Unit disposition requests via PostgreSQL lockForUpdate (one 200 OK, one 409 Conflict) (TASK-BE-056)', function (): void {
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();

    $report = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->firstOrFail();

    $tokenA = $adminDpupr->createToken('race-admin-a')->plainTextToken;
    $tokenB = $adminDpupr->createToken('race-admin-b')->plainTextToken;

    // Two concurrent requests racing to transition the SAME ROUTED_TO_UNIT ticket:
    // Request A -> TR-04 assign operational (IN_PROGRESS)
    // Request B -> TR-05 schedule APBD (BUDGET_SCHEDULED)
    $results = dispatchConcurrentApiRequests([
        [
            'method' => 'POST',
            'uri' => "/api/v1/unit/reports/{$report->id}/assign",
            'token' => $tokenA,
            'payload' => [
                'officer_id' => $officerDpupr->id,
                'note' => 'Worker A menugaskan operasional Jalur 1.',
            ],
        ],
        [
            'method' => 'POST',
            'uri' => "/api/v1/unit/reports/{$report->id}/schedule-apbd",
            'token' => $tokenB,
            'payload' => [
                'fiscal_year' => 2027,
                'proposal_ref_number' => 'RKA-RACE-2027-01',
                'target_quarter' => 'Q2',
                'note' => 'Worker B mengajukan Jalur 2 APBD bersamaan.',
            ],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([200, 409]);

    $conflictResult = collect($results)->firstWhere('status', 409);
    expect($conflictResult['body']['error']['code'] ?? null)->toBe('INVALID_STATE_TRANSITION');

    $fresh = $report->fresh();
    expect($fresh->status)->toBeIn([ReportStatus::IN_PROGRESS, ReportStatus::BUDGET_SCHEDULED]);

    // Exactly ONE disposition audit entry was committed (no duplicate transition)
    $transitionAudits = $fresh->auditLogs()
        ->whereIn('action_type', [AuditActionType::ASSIGNED->value, AuditActionType::BUDGET_SCHEDULED->value])
        ->count();
    expect($transitionAudits)->toBe(1);
});

it('TC-I-06: serializes two concurrent Citizen Escrow Sign-Off requests on PST-ESCROW01 (one 200 OK, one 409 Conflict) (TASK-BE-056)', function (): void {
    $owner = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();

    $tokenA = $owner->createToken('race-signoff-a')->plainTextToken;
    $tokenB = $owner->createToken('race-signoff-b')->plainTextToken;

    $results = dispatchConcurrentApiRequests([
        [
            'method' => 'POST',
            'uri' => "/api/v1/reports/{$escrow->ticket_code}/sign-off/approve",
            'token' => $tokenA,
            'payload' => [],
        ],
        [
            'method' => 'POST',
            'uri' => "/api/v1/reports/{$escrow->ticket_code}/sign-off/approve",
            'token' => $tokenB,
            'payload' => [],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([200, 409]);

    $conflictResult = collect($results)->firstWhere('status', 409);
    expect($conflictResult['body']['error']['code'] ?? null)->toBe('INVALID_STATE_TRANSITION');

    $fresh = $escrow->fresh();
    expect($fresh->status)->toBe(ReportStatus::VERIFIED_CLOSED)
        ->and($fresh->auditLogs()->where('action_type', AuditActionType::CITIZEN_APPROVED->value)->count())->toBe(1);
});

it('TC-I-06: serializes two concurrent Inspectorate dispute resolutions on PST-VETO0001 (one 200 OK, one 409 Conflict) (TASK-BE-056)', function (): void {
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $veto = Report::with('disputes')->where('ticket_code', 'PST-VETO0001')->firstOrFail();
    $dispute = $veto->disputes->where('status', DisputeStatus::PENDING)->firstOrFail();

    $tokenA = $auditor->createToken('race-audit-a')->plainTextToken;
    $tokenB = $auditor->createToken('race-audit-b')->plainTextToken;

    $results = dispatchConcurrentApiRequests([
        [
            'method' => 'POST',
            'uri' => "/api/v1/inspectorate/disputes/{$dispute->id}/resolve",
            'token' => $tokenA,
            'payload' => [
                'decision' => 'REOPENED',
                'audit_notes' => 'Auditor A memutuskan kerjakan ulang 48 jam karena bukti foto menyimpang.',
                'reopen_sla_hours' => 48,
            ],
        ],
        [
            'method' => 'POST',
            'uri' => "/api/v1/inspectorate/disputes/{$dispute->id}/resolve",
            'token' => $tokenB,
            'payload' => [
                'decision' => 'CLOSED',
                'audit_notes' => 'Auditor B memutuskan klaim unit sah dan menutup tiket secara final.',
            ],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([200, 409]);

    $conflictResult = collect($results)->firstWhere('status', 409);
    expect($conflictResult['body']['error']['code'] ?? null)->toBe('INVALID_STATE_TRANSITION');

    $freshReport = $veto->fresh();
    $freshDispute = Dispute::findOrFail($dispute->id);

    expect($freshDispute->status)->toBeIn([DisputeStatus::REOPENED, DisputeStatus::CLOSED])
        ->and($freshReport->current_cycle)->toBeLessThanOrEqual(2);

    $auditDecisions = $freshReport->auditLogs()
        ->whereIn('action_type', [AuditActionType::AUDIT_REOPENED->value, AuditActionType::AUDIT_CLOSED->value])
        ->count();
    expect($auditDecisions)->toBe(1);
});

it('TC-I-06: executes SELECT FOR UPDATE row lock and rejects stale in-memory Report transitions with 409 InvalidStateTransitionException (TASK-BE-056)', function (): void {
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $stateMachine = app(ReportStateMachineService::class);

    $targetId = Report::where('opd_unit_id', $adminDpupr->opd_unit_id)
        ->where('status', ReportStatus::ROUTED_TO_UNIT->value)
        ->value('id');

    // Load two independent stale snapshots of the same ticket
    $staleSnapshotA = Report::findOrFail($targetId);
    $staleSnapshotB = Report::findOrFail($targetId);

    $executedSql = [];
    DB::listen(static function ($query) use (&$executedSql): void {
        $executedSql[] = strtolower($query->sql);
    });

    // First transition succeeds using SELECT ... FOR UPDATE on reports
    $updated = $stateMachine->assignOperational($staleSnapshotA, $adminDpupr, $officerDpupr, 'Penugasan pertama');
    expect($updated->status)->toBe(ReportStatus::IN_PROGRESS);

    $usedRowLock = collect($executedSql)->contains(
        static fn (string $sql): bool => str_contains($sql, 'from "reports"') && str_contains($sql, 'for update')
    );
    expect($usedRowLock)->toBeTrue();

    // Second transition using stale snapshot (still thinks status == ROUTED_TO_UNIT) reloads locked row and fails 409
    expect(fn () => $stateMachine->reject(
        $staleSnapshotB,
        $adminDpupr,
        RejectionReasonCode::HOAX,
        'Mencoba menolak tiket yang sudah ditugaskan melalui snapshot basi.'
    ))->toThrow(InvalidStateTransitionException::class);
});
