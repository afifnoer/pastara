<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request and verify active status and allowed roles.
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException('Sesi Anda telah berakhir. Silakan masuk kembali.');
        }

        if (! $user->is_active) {
            $user->tokens()->delete();

            throw new AuthenticationException('Akun Anda sedang dinonaktifkan.');
        }

        if ($roles !== [] && ! in_array($user->role->value, $roles, true)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melakukan aksi ini.');
        }

        return $next($request);
    }
}
