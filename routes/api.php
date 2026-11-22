<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CitizenReportController;
use App\Http\Controllers\Api\V1\PublicPortalController;
use App\Http\Controllers\Api\V1\UnitReportController;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn (): JsonResponse => ApiResponse::success([
        'status' => 'ok',
        'app' => config('app.name'),
    ]));

    // M01 — Autentikasi & Profil
    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:api');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware(['auth:sanctum', 'role', 'throttle:api'])->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::patch('/profile', [AuthController::class, 'updateProfile']);
            Route::put('/password', [AuthController::class, 'changePassword']);
        });
    });

    // M02 — Portal Transparansi Publik
    Route::prefix('public')->group(function (): void {
        Route::middleware('throttle:api')->group(function (): void {
            Route::get('/reference-data', [PublicPortalController::class, 'referenceData']);
            Route::get('/map-markers', [PublicPortalController::class, 'mapMarkers']);
            Route::get('/reports', [PublicPortalController::class, 'reports']);
            Route::get('/reports/{ticket_code}', [PublicPortalController::class, 'show'])
                ->where('ticket_code', '[A-Za-z0-9-]+');
            Route::get('/scorecard', [PublicPortalController::class, 'scorecard']);
        });

        Route::post('/track', [PublicPortalController::class, 'track'])
            ->middleware('throttle:track');
    });

    // M03 — Pelaporan Warga
    Route::middleware(['auth:sanctum', 'role:WARGA'])->group(function (): void {
        Route::middleware('throttle:api')->group(function (): void {
            Route::get('/reports/nearby', [CitizenReportController::class, 'nearby']);
            Route::post('/reports/{report}/upvote', [CitizenReportController::class, 'upvote'])
                ->whereNumber('report');
            Route::patch('/reports/{report}/watch', [CitizenReportController::class, 'watch'])
                ->whereNumber('report');
            Route::get('/citizen/reports', [CitizenReportController::class, 'index']);
        });

        Route::post('/reports', [CitizenReportController::class, 'store'])
            ->middleware('throttle:create-report');
    });

    // M04 — Verifikasi & Disposisi Admin Unit
    Route::prefix('unit')->middleware(['auth:sanctum', 'role:ADMIN_UNIT', 'throttle:api'])->group(function (): void {
        Route::get('/reports', [UnitReportController::class, 'index']);
        Route::get('/reports/{report}', [UnitReportController::class, 'show'])->whereNumber('report');
        Route::get('/officers', [UnitReportController::class, 'officers']);
        Route::post('/reports/{report}/assign', [UnitReportController::class, 'assign'])->whereNumber('report');
        Route::patch('/reports/{report}/reassign-officer', [UnitReportController::class, 'reassignOfficer'])->whereNumber('report');
        Route::post('/reports/{report}/reject', [UnitReportController::class, 'reject'])->whereNumber('report');
        Route::post('/reports/{report}/reroute', [UnitReportController::class, 'reroute'])->whereNumber('report');
    });
});
