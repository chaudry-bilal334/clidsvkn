<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Single source of truth for the API response envelope (PRD §14, API_CONTRACT.md §3).
 *
 * Every API response — success and error path alike — is built through this
 * helper so the shape `{success, message, data, meta:{request_id}}` and the
 * `X-Request-Id` correlation header are guaranteed in one place. Controllers
 * reach it via the App\Http\Concerns\ApiResponds trait; the exception handler
 * and middleware normalizers call it directly.
 */
final class ApiEnvelope
{
    /**
     * Build a full envelope response.
     *
     * @param  mixed  $data  Payload. `null` when the action has nothing to return.
     * @param  array<string, array<int, string>>  $errors  Only present on validation-collected responses (422).
     * @param  string|null  $requestId  Correlation id. Defaults to the incoming X-Request-Id header
     *                                  (as set by EnsureRequestId) or a fresh UUID for unmapped routes.
     */
    public static function make(
        bool $success,
        string $message,
        mixed $data = null,
        int $status = 200,
        array $errors = [],
        ?string $requestId = null,
        ?Request $request = null,
    ): JsonResponse {
        $request ??= request();
        $requestId ??= $request?->header('X-Request-Id') ?: (string) Str::uuid();

        $body = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => $requestId],
        ];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, ['X-Request-Id' => $requestId]);
    }

    /** Success envelope (default 200). */
    public static function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return self::make(true, $message, $data, $status);
    }

    /** Created-envelope (201). */
    public static function created(mixed $data = null, string $message = 'Resource created.'): JsonResponse
    {
        return self::make(true, $message, $data, 201);
    }

    /** Error envelope (default 422). */
    public static function error(
        string $message,
        int $status = 422,
        mixed $data = null,
        array $errors = [],
        ?string $requestId = null,
        ?Request $request = null,
    ): JsonResponse {
        return self::make(false, $message, $data, $status, $errors, $requestId, $request);
    }
}
