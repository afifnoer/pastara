<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn (): JsonResponse => ApiResponse::success([
        'status' => 'ok',
        'app' => config('app.name'),
    ]));
});
