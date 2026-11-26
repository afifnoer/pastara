<?php

declare(strict_types=1);

use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\OpdUnit;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns active reference data including categories, units, districts, villages, and Cilacap bounds', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $inactiveUnit = OpdUnit::create([
        'code' => 'UNIT_NONAKTIF',
        'name' => 'Unit Nonaktif',
        'level' => 'OPD',
        'is_active' => false,
    ]);

    $response = $this->getJson('/api/v1/public/reference-data');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(7, 'data.categories')
        ->assertJsonCount(8, 'data.opd_units')
        ->assertJsonCount(24, 'data.districts')
        ->assertJsonPath('data.cilacap_bounds.min_lat', -7.85);

    $unitCodes = collect($response->json('data.opd_units'))->pluck('code')->all();
    expect($unitCodes)->not->toContain($inactiveUnit->code);
});

it('returns non-secret map markers with filters and never leaks secret ticket PST-PURW2027 (AC-006, BR-026)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $allResponse = $this->getJson('/api/v1/public/map-markers');
    $allResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $ticketCodes = collect($allResponse->json('data'))->pluck('ticket_code')->all();

    expect($ticketCodes)->toContain('PST-ESCROW01', 'PST-DUPL0001', 'PST-SADA2027', 'PST-VETO0001')
        ->and($ticketCodes)->not->toContain('PST-PURW2027');

    // Filter by category CAT-03 & status IN_PROGRESS & narrow bbox around Jeruklegi (-7.6350, 109.0180)
    $cat03 = Category::where('code', 'CAT-03')->firstOrFail();

    $filtered = $this->getJson('/api/v1/public/map-markers?'.http_build_query([
        'category_id' => $cat03->id,
        'status' => ReportStatus::IN_PROGRESS->value,
        'bbox' => '-7.6400,109.0100,-7.6300,109.0250',
    ]));

    $filtered->assertStatus(200);
    $filteredCodes = collect($filtered->json('data'))->pluck('ticket_code')->all();

    expect($filteredCodes)->toContain('PST-DUPL0001')
        ->and($filteredCodes)->not->toContain('PST-ESCROW01', 'PST-PURW2027');
});
