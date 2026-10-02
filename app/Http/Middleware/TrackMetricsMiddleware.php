<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TrackMetricsMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $start = microtime(true);

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
        } catch (Throwable $throwable) {
            // Record the failure before rethrowing. The previous version only
            // ran its bookkeeping after `$next()` returned, so any request that
            // threw -- precisely the 500s that matter for the error-rate alert
            // -- recorded no request at all. An exception-driven outage was
            // therefore invisible in http_requests_total.
            $this->record(microtime(true) - $start, 500);

            throw $throwable;
        }

        $this->record(microtime(true) - $start, $status);

        return $response;
    }

    protected function record(float $duration, int $status): void
    {
        try {
            $total = (int) Cache::get('metrics:http_requests_total', 0);
            Cache::put('metrics:http_requests_total', $total + 1, now()->addDays(30));

            $durationSum = (float) Cache::get('metrics:http_request_duration_sum', 0);
            Cache::put('metrics:http_request_duration_sum', $durationSum + $duration, now()->addDays(30));

            $durationCount = (int) Cache::get('metrics:http_request_duration_count', 0);
            Cache::put('metrics:http_request_duration_count', $durationCount + 1, now()->addDays(30));

            // Per-status breakdown. Without this there is no `status` label to
            // select on, so `http_requests_total{status=~"5.."}` matches
            // nothing and the error-rate rule can never fire.
            //
            // These are read-modify-write on a cached JSON blob, so concurrent
            // requests can lose an increment. That undercounts errors slightly.
            // It is accepted deliberately: the alternative is a per-status key
            // that the controller cannot enumerate, and a marginal undercount
            // is better than a rule that structurally cannot fire. The alert
            // threshold is a ratio over a 5m window, which is far more robust
            // to this than an exact count would be.
            $decoded = json_decode(
                (string) Cache::get('metrics:http_requests_by_status', '{}'),
                true
            );
            $byStatus = is_array($decoded) ? $decoded : [];

            $byStatus[$status] = (int) ($byStatus[$status] ?? 0) + 1;

            Cache::put(
                'metrics:http_requests_by_status',
                json_encode($byStatus),
                now()->addDays(30)
            );
        } catch (Throwable) {
            // Metrics collection must never break request handling.
        }
    }
}
