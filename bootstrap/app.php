<?php

declare(strict_types=1);

use App\Exceptions\EscrowLockViolationException;
use App\Exceptions\InvalidStateTransitionException;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeadersMiddleware::class);
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        $isApiRequest = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'UNAUTHENTICATED',
                'Sesi Anda telah berakhir. Silakan masuk kembali.',
                401
            );
        });

        $exceptions->render(function (EscrowLockViolationException $e, Request $request) use ($isApiRequest) {
            Log::channel('security')->warning('Escrow lock violation detected', [
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'ESCROW_LOCK_VIOLATION',
                $e->getMessage() !== '' ? $e->getMessage() : 'Penutupan tiket sepihak dilarang oleh aturan Escrow.',
                403
            );
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'FORBIDDEN',
                'Anda tidak memiliki izin untuk melakukan aksi ini.',
                403
            );
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'NOT_FOUND',
                'Data tidak ditemukan.',
                404
            );
        });

        $exceptions->render(function (InvalidStateTransitionException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'INVALID_STATE_TRANSITION',
                $e->getMessage() !== '' ? $e->getMessage() : 'Status laporan telah berubah atau transisi tidak diizinkan.',
                409
            );
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Data yang dikirim belum lengkap atau tidak valid.',
                422,
                $e->errors()
            );
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'TOO_MANY_REQUESTS',
                'Terlalu banyak percobaan. Coba lagi dalam beberapa saat.',
                429
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return match ($e->getStatusCode()) {
                403 => ApiResponse::error('FORBIDDEN', $e->getMessage() ?: 'Anda tidak memiliki izin untuk melakukan aksi ini.', 403),
                404 => ApiResponse::error('NOT_FOUND', $e->getMessage() ?: 'Data tidak ditemukan.', 404),
                409 => ApiResponse::error('INVALID_STATE_TRANSITION', $e->getMessage() ?: 'Status laporan telah berubah atau transisi tidak diizinkan.', 409),
                default => null,
            };
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request) || config('app.debug')) {
                return null;
            }

            return ApiResponse::error(
                'INTERNAL_SERVER_ERROR',
                'Terjadi kesalahan pada sistem. Silakan coba lagi.',
                500
            );
        });
    })->create();
