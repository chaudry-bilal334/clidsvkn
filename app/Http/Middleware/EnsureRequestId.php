<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EnsureRequestId
{
    private const HEADER_NAME = 'X-Request-Id';

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $requestId = $request->headers->get(self::HEADER_NAME) ?? (string) Str::uuid();

        $request->headers->set(self::HEADER_NAME, $requestId);

        /** @var SymfonyResponse $response */
        $response = $next($request);

        $response->headers->set(self::HEADER_NAME, $requestId);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if (is_array($data)) {
                $data['meta'] = $data['meta'] ?? [];
                $data['meta']['request_id'] = $requestId;
                $response->setData($data);
            }
        }

        return $response;
    }
}
