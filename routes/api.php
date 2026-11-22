<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PublicPortalController;
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
    Route::prefix('public')->middleware('throttle:api')->group(function (): void {
        Route::get('/reference-data', [PublicPortalController::class, 'referenceData']);
        Route::get('/map-markers', [PublicPortalController::class, 'mapMarkers']);
    });
});
