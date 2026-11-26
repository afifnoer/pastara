<?php

declare(strict_types=1);

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('/docs', [ApiDocsController::class, 'index'])->name('api.docs');
Route::get('/docs/openapi.yaml', [ApiDocsController::class, 'spec'])->name('api.docs.spec');
