<?php

declare(strict_types=1);

use App\Http\Resources\AuditReportResource;
use App\Http\Resources\CitizenReportResource;
use App\Http\Resources\PublicReportCardResource;
use App\Http\Resources\PublicReportResource;
use App\Http\Resources\UnitReportResource;
use App\Http\Resources\UserResource;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('serializes UserResource and all 4 ReportResource contexts while protecting secret reporter identity (Hukum 4)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    Model::preventLazyLoading(true);

    try {
        $request = Request::create('/api/v1/test');

        $admin = User::with('opdUnit')->where('email', 'admin.dpupr@pastara.id')->firstOrFail();
        $userPayload = (new UserResource($admin))->toArray($request);

        expect($userPayload['role'])->toBe('ADMIN_UNIT')
            ->and($userPayload['opd_unit']['code'])->toBe('DPUPR');

        $relations = [
            'reporter',
            'category',
            'opdUnit',
            'assignedOfficer',
            'district',
            'village',
            'photos',
            'upvotes',
            'budgetSchedule.milestones',
            'disputes',
            'rating',
            'auditLogs',
        ];

        $escrow = Report::with($relations)->where('ticket_code', 'PST-ESCROW01')->firstOrFail();
        $purw = Report::with($relations)->where('ticket_code', 'PST-PURW2027')->firstOrFail();
        $sada = Report::with($relations)->where('ticket_code', 'PST-SADA2027')->firstOrFail();

        // 1. PublicReportCardResource & PublicReportResource
        $cardPayload = (new PublicReportCardResource($escrow))->toArray($request);
        $publicPayload = (new PublicReportResource($escrow))->toArray($request);

        expect($cardPayload['ticket_code'])->toBe('PST-ESCROW01')
            ->and($cardPayload['thumbnail_url'])->toContain('seed-before-thumb.webp')
            ->and($publicPayload['reporter'])->toBe(['name' => 'W***a P. U.'])
            ->and($publicPayload['reporter'])->not->toHaveKeys(['email', 'phone'])
            ->and($publicPayload['assigned_officer'])->toBe([
                'role_label' => 'Petugas Lapangan',
                'unit_name' => 'Dinas Perhubungan',
            ])
            ->and($publicPayload['photos_by_cycle'][0]['before'])->toHaveCount(1)
            ->and($publicPayload['photos_by_cycle'][0]['after'])->toHaveCount(1)
            ->and($publicPayload['sla']['sla_zone'])->toBe('PAUSED')
            ->and($publicPayload['signoff']['signoff_remaining_seconds'])->toBeGreaterThan(0);

        // 2. CitizenReportResource (owner sees own full identity; non-owner watcher sees masked identity without PII)
        $owner = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
        $ownerRequest = Request::create('/api/v1/citizen/reports');
        $ownerRequest->setUserResolver(static fn (): User => $owner);

        $citizenOwnerPayload = (new CitizenReportResource($purw))->toArray($ownerRequest);
        expect($citizenOwnerPayload['reporter']['email'])->toBe('warga.demo@pastara.id')
            ->and($citizenOwnerPayload['reporter']['name'])->toBe('Warga Pelapor Uji');

        $watcher = User::where('role', 'WARGA')->where('id', '!=', $owner->id)->firstOrFail();
        $watcherRequest = Request::create('/api/v1/citizen/reports?tab=watching');
        $watcherRequest->setUserResolver(static fn (): User => $watcher);

        $citizenWatcherPublicPayload = (new CitizenReportResource($escrow))->toArray($watcherRequest);
        expect($citizenWatcherPublicPayload['reporter'])->toBe(['name' => 'W***a P. U.'])
            ->and($citizenWatcherPublicPayload['reporter'])->not->toHaveKeys(['email', 'phone']);

        $citizenWatcherSecretPayload = (new CitizenReportResource($purw))->toArray($watcherRequest);
        expect($citizenWatcherSecretPayload['reporter'])->toBe(['name' => 'Pelapor Rahasia'])
            ->and($citizenWatcherSecretPayload['reporter'])->not->toHaveKeys(['email', 'phone']);

        // 3. UnitReportResource on secret ticket (Hukum 4 Konstitusi!)
        $unitSecretPayload = (new UnitReportResource($purw))->toArray($request);
        expect($unitSecretPayload['reporter'])->toBe([
            'name' => 'Pelapor Rahasia',
            'email' => null,
            'phone' => null,
        ]);

        // 4. UnitReportResource on non-secret ticket (name visible, phone masked)
        $unitPublicPayload = (new UnitReportResource($escrow))->toArray($request);
        expect($unitPublicPayload['reporter']['name'])->toBe('Warga Pelapor Uji')
            ->and($unitPublicPayload['reporter']['email'])->toBeNull()
            ->and($unitPublicPayload['reporter']['phone'])->toBe('0812*****001')
            ->and($unitPublicPayload['assigned_officer']['name'])->toBe('Petugas PJU Dishub');

        // 5. AuditReportResource on secret ticket (INSPEKTORAT sees full reporter identity + metadata)
        $auditPayload = (new AuditReportResource($purw))->toArray($request);
        expect($auditPayload['reporter']['email'])->toBe('warga.demo@pastara.id')
            ->and($auditPayload['audit_timeline'])->not->toBeEmpty()
            ->and(array_key_exists('metadata', $auditPayload['audit_timeline'][0]))->toBeTrue();

        // 6. Budget schedule serialization on PST-SADA2027
        $sadaPayload = (new PublicReportResource($sada))->toArray($request);
        expect($sadaPayload['budget_schedule']['proposal_ref_number'])->toBe('RKA-DPUPR-2027-014')
            ->and($sadaPayload['budget_schedule']['milestones'])->toHaveCount(1);
    } finally {
        Model::preventLazyLoading(false);
    }
});
