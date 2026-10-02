<?php

namespace App\Http\Concerns;

use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;

/**
 * Shared response helpers for API controllers.
 *
 * Growing the whole controller layer onto this trait is the long-term goal;
 * each controller adopts it by replacing its hand-rolled `response()->json(...)`
 * with `$this->ok(...)` / `$this->created(...)` / `$this->error(...)`. Until the
 * retrofit is complete the `EnsureApiEnvelope` middleware adds the same
 * additive defaulting to any hand-rolled response, so envelopes are consistent
 * from day one either way.
 */
trait ApiResponds
{
    /**
     * Standard success envelope (HTTP 200).
     */
    protected function ok(mixed $data = null, string $message = ''): JsonResponse
    {
        return ApiEnvelope::success($data, $message);
    }

    /**
     * Resource created envelope (HTTP 201).
     */
    protected function created(mixed $data = null, string $message = 'Resource created.'): JsonResponse
    {
        return ApiEnvelope::created($data, $message);
    }

    /**
     * Error envelope. `$errors` is rendered as the top-level `errors` mapping
     * (422 validation style), matching what `assertJsonValidationErrors` reads.
     */
    protected function error(string $message, int $status = 422, mixed $data = null, array $errors = []): JsonResponse
    {
        return ApiEnvelope::error($message, $status, $data, $errors);
    }
}
