<?php

declare(strict_types=1);

use App\Exceptions\DuplicateUpvoteException;
use App\Exceptions\EscrowLockViolationException;
use App\Exceptions\InvalidStateTransitionException;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
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

        $logSecurityEvent = static function (string $event, Request $request, int $status, string $message): void {
            Log::channel('security')->warning($event, [
                'status' => $status,
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_id' => $request->user()?->id,
                'message' => $message,
            ]);
        };

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            $rawMessage = trim($e->getMessage());
            $message = ($rawMessage !== '' && $rawMessage !== 'Unauthenticated.')
                ? $rawMessage
                : 'Sesi Anda telah berakhir. Silakan masuk kembali.';

            return ApiResponse::error('UNAUTHENTICATED', $message, 401);
        });

        $exceptions->render(function (EscrowLockViolationException $e, Request $request) use ($isApiRequest, $logSecurityEvent): ?JsonResponse {
            $message = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Penutupan tiket sepihak dilarang oleh aturan Escrow.';

            $logSecurityEvent('Escrow lock violation detected', $request, 403, $message);

            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error('ESCROW_LOCK_VIOLATION', $message, 403);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($isApiRequest, $logSecurityEvent): ?JsonResponse {
            $rawMessage = trim($e->getMessage());
            $message = ($rawMessage !== '' && $rawMessage !== 'This action is unauthorized.')
                ? $rawMessage
                : 'Anda tidak memiliki izin untuk melakukan aksi ini.';

            $logSecurityEvent('Forbidden access denied', $request, 403, $message);

            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error('FORBIDDEN', $message, 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error('NOT_FOUND', 'Data tidak ditemukan.', 404);
        });

        $exceptions->render(function (InvalidStateTransitionException $e, Request $request) use ($isApiRequest, $logSecurityEvent): ?JsonResponse {
            $message = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Status laporan telah berubah atau transisi tidak diizinkan.';

            $logSecurityEvent('Invalid state transition rejected', $request, 409, $message);

            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error('INVALID_STATE_TRANSITION', $message, 409);
        });

        $exceptions->render(function (DuplicateUpvoteException $e, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error('ALREADY_UPVOTED', $e->getMessage(), 409);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApiRequest): ?JsonResponse {
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

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                'TOO_MANY_REQUESTS',
                'Terlalu banyak percobaan. Coba lagi dalam beberapa saat.',
                429,
                null,
                $e->getHeaders()
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApiRequest, $logSecurityEvent): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $rawMessage = trim($e->getMessage());

            if ($status === 403) {
                $message = ($rawMessage !== '' && $rawMessage !== 'This action is unauthorized.')
                    ? $rawMessage
                    : 'Anda tidak memiliki izin untuk melakukan aksi ini.';
                $logSecurityEvent('Forbidden HTTP exception', $request, 403, $message);

                return ApiResponse::error('FORBIDDEN', $message, 403, null, $headers);
            }

            if ($status === 409) {
                $message = $rawMessage !== ''
                    ? $rawMessage
                    : 'Status laporan telah berubah atau transisi tidak diizinkan.';
                $logSecurityEvent('Conflict HTTP exception', $request, 409, $message);

                return ApiResponse::error('INVALID_STATE_TRANSITION', $message, 409, null, $headers);
            }

            return match ($status) {
                400 => ApiResponse::error('BAD_REQUEST', $rawMessage !== '' ? $rawMessage : 'Permintaan tidak valid.', 400, null, $headers),
                404 => ApiResponse::error('NOT_FOUND', $rawMessage !== '' ? $rawMessage : 'Data tidak ditemukan.', 404, null, $headers),
                405 => ApiResponse::error('METHOD_NOT_ALLOWED', 'Metode HTTP tidak diizinkan untuk endpoint ini.', 405, null, $headers),
                413 => ApiResponse::error('PAYLOAD_TOO_LARGE', 'Ukuran data atau berkas yang diunggah melebihi batas maksimum.', 413, null, $headers),
                422 => ApiResponse::error('VALIDATION_ERROR', $rawMessage !== '' ? $rawMessage : 'Data yang dikirim belum lengkap atau tidak valid.', 422, null, $headers),
                503 => ApiResponse::error('SERVICE_UNAVAILABLE', 'Layanan sedang dalam pemeliharaan. Silakan coba lagi nanti.', 503, null, $headers),
                default => ApiResponse::error(
                    $status >= 500 ? 'INTERNAL_SERVER_ERROR' : 'HTTP_ERROR',
                    $status >= 500 && ! config('app.debug')
                        ? 'Terjadi kesalahan pada sistem. Silakan coba lagi.'
                        : ($rawMessage !== '' ? $rawMessage : 'Terjadi kesalahan pada permintaan Anda.'),
                    $status,
                    null,
                    $headers
                ),
            };
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApiRequest): ?JsonResponse {
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
