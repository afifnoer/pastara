<?php

declare(strict_types=1);

use App\Exceptions\EscrowLockViolationException;
use App\Exceptions\InvalidStateTransitionException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

it('loads pastara configuration constants and UTC timezone', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('pastara.cilacap.min_lat'))->toBe(-7.85)
        ->and(config('pastara.cilacap.max_lat'))->toBe(-7.15)
        ->and(config('pastara.cilacap.min_lng'))->toBe(108.5)
        ->and(config('pastara.cilacap.max_lng'))->toBe(109.45)
        ->and(config('pastara.signoff_window_hours'))->toBe(72)
        ->and(config('pastara.signoff_reminder_hours'))->toBe(24)
        ->and(config('pastara.apbd_doc_due_weekdays'))->toBe(7)
        ->and(config('pastara.exif_max_age_hours'))->toBe(24)
        ->and(config('logging.channels.security.path'))->toBe(storage_path('logs/security.log'));
});

it('attaches security headers and CORS headers on API responses', function (): void {
    $origin = (string) config('pastara.frontend_url', 'http://localhost:5173');

    $response = $this->withHeaders(['Origin' => $origin])
        ->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'ok')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('Access-Control-Allow-Origin', $origin);
});

it('renders standardized JSON 404 envelope for unknown API routes', function (): void {
    $response = $this->getJson('/api/v1/route-yang-tidak-ada');

    $response->assertStatus(404)
        ->assertJsonStructure(['success', 'server_time', 'error' => ['code', 'message']])
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('renders global JSON exception responses according to constitution contract', function (): void {
    Route::get('/api/v1/_test/unauthenticated', function (): void {
        throw new AuthenticationException;
    });
    Route::get('/api/v1/_test/escrow-violation', function (): void {
        throw new EscrowLockViolationException;
    });
    Route::get('/api/v1/_test/forbidden', function (): void {
        throw new AuthorizationException;
    });
    Route::get('/api/v1/_test/invalid-transition', function (): void {
        throw new InvalidStateTransitionException;
    });
    Route::post('/api/v1/_test/validation', function (Request $request): JsonResponse {
        $request->validate(['email' => ['required', 'email']]);

        return ApiResponse::success();
    });
    Route::get('/api/v1/_test/server-error', function (): void {
        throw new RuntimeException('Simulated internal error');
    });

    $this->getJson('/api/v1/_test/unauthenticated')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $this->getJson('/api/v1/_test/escrow-violation')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ESCROW_LOCK_VIOLATION');

    expect(file_exists(storage_path('logs/security.log')))->toBeTrue();

    $this->getJson('/api/v1/_test/forbidden')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->getJson('/api/v1/_test/invalid-transition')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    $this->postJson('/api/v1/_test/validation', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['email']]]);

    config(['app.debug' => false]);

    $this->getJson('/api/v1/_test/server-error')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_SERVER_ERROR');
});

it('registers all required rate limiters and returns HTTP 429 on the 6th login attempt', function (): void {
    expect(RateLimiter::limiter('login'))->not->toBeNull()
        ->and(RateLimiter::limiter('track'))->not->toBeNull()
        ->and(RateLimiter::limiter('create-report'))->not->toBeNull()
        ->and(RateLimiter::limiter('api'))->not->toBeNull();

    Route::post('/api/v1/_test/login', fn (): JsonResponse => ApiResponse::success(['ok' => true]))
        ->middleware('throttle:login');

    $email = 'rate-limit-test@pastara.id';

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/_test/login', ['email' => $email])
            ->assertStatus(200);
    }

    $this->postJson('/api/v1/_test/login', ['email' => $email])
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});
