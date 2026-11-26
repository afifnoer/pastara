<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuditLogAdminController;
use App\Http\Controllers\Api\V1\Admin\CategoryAdminController;
use App\Http\Controllers\Api\V1\Admin\LocationAdminController;
use App\Http\Controllers\Api\V1\Admin\OpdUnitAdminController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CitizenReportController;
use App\Http\Controllers\Api\V1\CitizenSignOffController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InspectorateController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfficerTaskController;
use App\Http\Controllers\Api\V1\PublicPortalController;
use App\Http\Controllers\Api\V1\UnitReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

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

    // M06 — Escrow Citizen Sign-Off & Micro-Rating (Opsional auth:sanctum / secret_token)
    Route::middleware('throttle:api')->group(function (): void {
        Route::post('/reports/{report:ticket_code}/sign-off/approve', [CitizenSignOffController::class, 'approve'])
            ->where('report', '[A-Za-z0-9-]+');
        Route::post('/reports/{report:ticket_code}/sign-off/reject', [CitizenSignOffController::class, 'reject'])
            ->where('report', '[A-Za-z0-9-]+');
        Route::post('/reports/{report:ticket_code}/rating', [CitizenSignOffController::class, 'rate'])
            ->where('report', '[A-Za-z0-9-]+');
    });

    // M04 — Verifikasi & Disposisi Admin Unit
    Route::prefix('unit')->middleware(['auth:sanctum', 'role:ADMIN_UNIT', 'throttle:api'])->group(function (): void {
        Route::get('/reports', [UnitReportController::class, 'index']);
        Route::get('/reports/{report}', [UnitReportController::class, 'show'])->whereNumber('report');
        Route::get('/officers', [UnitReportController::class, 'officers']);
        Route::post('/reports/{report}/assign', [UnitReportController::class, 'assign'])->whereNumber('report');
        Route::post('/reports/{report}/schedule-apbd', [UnitReportController::class, 'scheduleApbd'])->whereNumber('report');
        Route::post('/reports/{report}/apbd-milestones', [UnitReportController::class, 'storeMilestone'])->whereNumber('report');
        Route::post('/reports/{report}/start-execution', [UnitReportController::class, 'startExecution'])->whereNumber('report');
        Route::patch('/reports/{report}/reassign-officer', [UnitReportController::class, 'reassignOfficer'])->whereNumber('report');
        Route::post('/reports/{report}/reject', [UnitReportController::class, 'reject'])->whereNumber('report');
        Route::post('/reports/{report}/reroute', [UnitReportController::class, 'reroute'])->whereNumber('report');
    });

    // M05 — Tugas Lapangan Petugas
    Route::prefix('officer')->middleware(['auth:sanctum', 'role:PETUGAS', 'throttle:api'])->group(function (): void {
        Route::get('/tasks', [OfficerTaskController::class, 'index']);
        Route::post('/tasks/{report}/progress', [OfficerTaskController::class, 'storeProgress'])->whereNumber('report');
        Route::post('/tasks/{report}/evidence', [OfficerTaskController::class, 'submitEvidence'])->whereNumber('report');
    });

    // M08 — Pengawasan & Sidang Inspektorat
    Route::prefix('inspectorate')->middleware(['auth:sanctum', 'role:INSPEKTORAT', 'throttle:api'])->group(function (): void {
        Route::get('/reports', [InspectorateController::class, 'reports']);
        Route::post('/disputes/{dispute}/resolve', [InspectorateController::class, 'resolveDispute'])->whereNumber('dispute');
        Route::get('/scorecard', [InspectorateController::class, 'scorecard']);
    });

    // M10 — Notifikasi In-App
    Route::prefix('notifications')->middleware(['auth:sanctum', 'role', 'throttle:api'])->group(function (): void {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'markAsRead']);
    });

    // M11 — Manajemen Super Admin & Audit Log Sistem
    Route::prefix('admin')->middleware(['auth:sanctum', 'role:SUPER_ADMIN', 'throttle:api'])->group(function (): void {
        Route::get('/users', [UserAdminController::class, 'index']);
        Route::post('/users', [UserAdminController::class, 'store']);
        Route::patch('/users/{user}', [UserAdminController::class, 'update'])->whereNumber('user');

        Route::get('/opd-units', [OpdUnitAdminController::class, 'index']);
        Route::post('/opd-units', [OpdUnitAdminController::class, 'store']);
        Route::patch('/opd-units/{opdUnit}', [OpdUnitAdminController::class, 'update'])->whereNumber('opdUnit');

        Route::get('/categories', [CategoryAdminController::class, 'index']);
        Route::patch('/categories/{category}', [CategoryAdminController::class, 'update'])->whereNumber('category');

        Route::get('/locations', [LocationAdminController::class, 'index']);
        Route::post('/locations', [LocationAdminController::class, 'store']);
        Route::patch('/locations/{type}/{id}', [LocationAdminController::class, 'update'])
            ->whereIn('type', ['district', 'village'])
            ->whereNumber('id');
    });

    Route::prefix('admin')->middleware(['auth:sanctum', 'role:SUPER_ADMIN,INSPEKTORAT', 'throttle:api'])->group(function (): void {
        Route::get('/audit-logs', [AuditLogAdminController::class, 'index']);
    });
});
