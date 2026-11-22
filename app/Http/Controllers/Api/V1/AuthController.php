<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\Notification;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'role' => UserRole::WARGA,
            'is_active' => true,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
        ], status: 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()
            ->with('opdUnit')
            ->where('email', $validated['email'])
            ->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password) || ! $user->is_active) {
            Log::channel('security')->warning('Failed login attempt', [
                'email' => $validated['email'],
                'ip' => $request->ip(),
                'reason' => $user !== null && ! $user->is_active ? 'inactive_account' : 'invalid_credentials',
            ]);

            return ApiResponse::error('UNAUTHENTICATED', 'Email atau password salah.', 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success([
            'message' => 'Berhasil keluar dari sesi.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('opdUnit');

        $unreadCount = Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return ApiResponse::success([
            'user' => new UserResource($user),
            'unread_notifications_count' => $unreadCount,
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $user->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
        ]);

        $user->loadMissing('opdUnit');

        return ApiResponse::success(new UserResource($user));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update([
            'password' => $request->validated('password'),
        ]);

        $currentToken = $user->currentAccessToken();
        $query = $user->tokens();
        if ($currentToken instanceof PersonalAccessToken) {
            $query->where('id', '!=', $currentToken->id);
        }
        $query->delete();

        return ApiResponse::success([
            'message' => 'Kata sandi berhasil diperbarui.',
        ]);
    }
}
