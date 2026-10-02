<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees the API envelope shape `{success, message, data, meta}` on every
 * JSON response that arrives back from a controller or middleware.
 *
 * The normalizer is strictly additive: existing keys are never moved or
 * removed, so flat hand-rolled payloads (auth endpoints, 2FA challenges) and
 * responses that already carry their own `meta` (pagination) keep working —
 * the role check in the frontend interceptor and `assertJson*` assertions all
 * match untouched keys. `meta.request_id` is filled by EnsureRequestId (outer)
 * and by the exception handler for exception paths.
 */
class EnsureApiEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $body = $response->getData(true);

        if (! is_array($body)) {
            return $response;
        }

        $status = $response->getStatusCode();
        $isError = $status >= 400;

        if (! array_key_exists('success', $body)) {
            $body['success'] = ! $isError;
        }

        if (! array_key_exists('message', $body)) {
            $body['message'] = $isError ? Response::$statusTexts[$status] ?? 'Error.' : '';
        }

        if (! array_key_exists('data', $body)) {
            $body['data'] = null;
        }

        if (! array_key_exists('meta', $body) || ! is_array($body['meta'])) {
            $body['meta'] = [];
        }

        $response->setData($body);

        return $response;
    }
}
