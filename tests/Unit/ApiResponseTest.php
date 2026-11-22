<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Support\Carbon;

it('returns standardized success envelope with ISO-8601 UTC server_time', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-22 02:00:00', 'UTC'));

    $response = ApiResponse::success(['ticket_code' => 'PST-TEST0001'], ['total' => 1], 201);
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['server_time'])->toBe('2026-11-22T02:00:00Z')
        ->and($payload['data'])->toBe(['ticket_code' => 'PST-TEST0001'])
        ->and($payload['meta'])->toBe(['total' => 1]);

    Carbon::setTestNow();
});

it('omits meta from success envelope when meta is null', function (): void {
    $response = ApiResponse::success(['ok' => true]);
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload)->toHaveKeys(['success', 'server_time', 'data'])
        ->and($payload)->not->toHaveKey('meta')
        ->and($payload['server_time'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('returns standardized error envelope with optional fields', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-22 02:05:00', 'UTC'));

    $response = ApiResponse::error(
        'VALIDATION_ERROR',
        'Data tidak valid.',
        422,
        ['title' => ['Judul wajib diisi.']]
    );
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(422)
        ->and($payload['success'])->toBeFalse()
        ->and($payload['server_time'])->toBe('2026-11-22T02:05:00Z')
        ->and($payload['error'])->toBe([
            'code' => 'VALIDATION_ERROR',
            'message' => 'Data tidak valid.',
            'fields' => ['title' => ['Judul wajib diisi.']],
        ]);

    Carbon::setTestNow();
});
