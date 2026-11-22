<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Build a standardized JSON success envelope.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(mixed $data = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'server_time' => now()->toIso8601ZuluString(),
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Build a standardized JSON error envelope.
     *
     * @param  array<string, mixed>|null  $fields
     */
    public static function error(string $code, string $message, int $status = 400, ?array $fields = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return response()->json([
            'success' => false,
            'server_time' => now()->toIso8601ZuluString(),
            'error' => $error,
        ], $status);
    }
}
