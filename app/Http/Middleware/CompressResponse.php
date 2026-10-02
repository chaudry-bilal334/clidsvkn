<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compresses compressible response bodies with Brotli (when available) or
 * gzip. Only applies to non-streamed payloads large enough to benefit.
 */
class CompressResponse
{
    private const MIN_LENGTH = 512;

    /**
     * @var array<int, string>
     */
    private const COMPRESSIBLE_PREFIXES = [
        'text/',
        'application/json',
        'application/javascript',
        'application/xml',
        'application/xhtml+xml',
        'image/svg+xml',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response || (method_exists($response, 'isStreamed') && $response->isStreamed()) || $response->isEmpty()) {
            return $response;
        }

        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if (! $this->isCompressibleType($contentType)) {
            return $response;
        }

        $body = $response->getContent();

        if ($body === false || strlen($body) < self::MIN_LENGTH) {
            return $response;
        }

        $encoding = $this->negotiateEncoding((string) $request->headers->get('Accept-Encoding', ''));

        if ($encoding === null) {
            return $response;
        }

        $compressed = $encoding === 'br'
            ? brotli_compress($body)
            : gzencode($body);

        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', $encoding);
        $response->headers->set('Vary', 'Accept-Encoding');
        $response->headers->set('Content-Length', (string) strlen($compressed));

        return $response;
    }

    private function isCompressibleType(string $contentType): bool
    {
        foreach (self::COMPRESSIBLE_PREFIXES as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pick the strongest encoding the client accepts and this process supports.
     *
     * @return 'br'|'gzip'|null
     */
    private function negotiateEncoding(string $acceptEncoding): ?string
    {
        $accepted = [];

        foreach (explode(',', $acceptEncoding) as $part) {
            $segments = explode(';', trim($part));
            $name = strtolower((string) array_shift($segments));
            $quality = 1.0;

            foreach ($segments as $param) {
                $param = trim($param);

                if (str_starts_with($param, 'q=')) {
                    $quality = (float) substr($param, 2);
                }
            }

            if ($name !== '' && $quality > 0) {
                $accepted[$name] = $quality;
            }
        }

        if (function_exists('brotli_compress') && ($accepted['br'] ?? 0) > 0) {
            return 'br';
        }

        if (function_exists('gzencode') && ($accepted['gzip'] ?? 0) > 0) {
            return 'gzip';
        }

        return null;
    }
}
