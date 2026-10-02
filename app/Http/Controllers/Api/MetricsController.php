<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MetricsController extends Controller
{
    public function index(): Response
    {
        $metrics = implode("\n", $this->collectMetrics())."\n";

        return response($metrics, 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    protected function collectMetrics(): array
    {
        $groups = [$this->uptimeMetrics()];

        foreach (['http', 'database', 'cache', 'queue', 'reverb'] as $group) {
            $groups[] = $this->{$group.'Metrics'}();
        }

        return array_merge(...$groups);
    }

    protected function uptimeMetrics(): array
    {
        $uptime = max(0, microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : microtime(true)));

        return [
            '# HELP app_uptime_seconds Application uptime in seconds',
            '# TYPE app_uptime_seconds gauge',
            $this->formatMetric('app_uptime_seconds', $uptime),
        ];
    }

    protected function httpMetrics(): array
    {
        try {
            $durationSum = (float) Cache::get('metrics:http_request_duration_sum', 0);
            $durationCount = (int) Cache::get('metrics:http_request_duration_count', 0);
            $byStatus = $this->decodeStatusBreakdown(
                (string) Cache::get('metrics:http_requests_by_status', '{}')
            );
        } catch (Throwable) {
            $durationSum = 0.0;
            $durationCount = 0;
            $byStatus = [];
        }

        $lines = [
            '# HELP http_requests_total Total number of HTTP requests',
            '# TYPE http_requests_total counter',
        ];

        // Emitted per status code. The previous version exported a single
        // unlabelled series, which made every rule of the form
        // `http_requests_total{status=~"5.."}` permanently unmatchable: there
        // was no `status` label to select on. HighErrorRate could never fire,
        // however many 500s the API returned.
        foreach ($byStatus as $status => $value) {
            $lines[] = $this->formatMetric('http_requests_total', $value, [
                'status' => (string) $status,
            ]);
        }

        // Unlabelled total retained for backward compatibility. Prometheus
        // treats the unlabelled and labelled series as distinct series in the
        // same metric family, so existing queries keep working while
        // status-scoped ones become possible for the first time.
        $lines[] = $this->formatMetric('http_requests_total', array_sum($byStatus));
        $lines[] = '# HELP http_request_duration_seconds HTTP request latency in seconds';
        $lines[] = '# TYPE http_request_duration_seconds summary';
        $lines[] = $this->formatMetric('http_request_duration_seconds_sum', $durationSum);
        $lines[] = $this->formatMetric('http_request_duration_seconds_count', $durationCount);

        return $lines;
    }

    /**
     * Decode the per-status request counts accumulated by TrackMetricsMiddleware.
     *
     * @return array<int|string, int>
     */
    protected function decodeStatusBreakdown(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $counts = [];
        foreach ($decoded as $status => $value) {
            if (is_numeric($status) && is_numeric($value)) {
                $counts[(int) $status] = (int) $value;
            }
        }

        return $counts;
    }

    protected function databaseMetrics(): array
    {
        $active = 1;

        $driver = config('database.default');
        $reachable = true;

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                $result = DB::select('SELECT COUNT(*) AS active FROM information_schema.processlist WHERE command != "Sleep"');
                $active = (int) data_get($result, '0.active', 0);
            } catch (Throwable) {
                $active = 0;
                $reachable = false;
            }
        }

        // The previous code used `$active ?: 1`, and the catch branch also set
        // `$active = 1`. Both guarantee the gauge is at least 1, so
        // `database_connections_active == 0` -- the DatabaseConnectionDrop
        // alert -- could never fire, including when the database was genuinely
        // unreachable. Reporting 1 when the count is unknown turns an outage
        // into silence, which is the worst possible direction for a gauge that
        // exists to detect outages.
        //
        // `database_reachable` is the honest signal: it is 0 only when the
        // query actually failed, and stays 1 for a reachable database with
        // zero active connections.
        return [
            '# HELP database_connections_active Active database connections',
            '# TYPE database_connections_active gauge',
            $this->formatMetric('database_connections_active', $active),
            '# HELP database_reachable 1 if the database answered a live query',
            '# TYPE database_reachable gauge',
            $this->formatMetric('database_reachable', $reachable ? 1 : 0),
        ];
    }

    protected function cacheMetrics(): array
    {
        try {
            $hits = (int) Cache::get('metrics:cache_hits', 0);
            $misses = (int) Cache::get('metrics:cache_misses', 0);
        } catch (Throwable) {
            $hits = 0;
            $misses = 0;
        }

        return [
            '# HELP redis_cache_hit_total Redis cache hit count',
            '# TYPE redis_cache_hit_total counter',
            $this->formatMetric('redis_cache_hit_total', $hits),
            '# HELP redis_cache_miss_total Redis cache miss count',
            '# TYPE redis_cache_miss_total counter',
            $this->formatMetric('redis_cache_miss_total', $misses),
        ];
    }

    protected function queueMetrics(): array
    {
        try {
            $failedJobs = (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            $failedJobs = 0;
        }

        return [
            '# HELP failed_queue_jobs_total Total failed queue jobs',
            '# TYPE failed_queue_jobs_total counter',
            $this->formatMetric('failed_queue_jobs_total', $failedJobs),
        ];
    }

    protected function reverbMetrics(): array
    {
        try {
            $connections = (int) Cache::get('metrics:reverb_connections', 0);
        } catch (Throwable) {
            $connections = 0;
        }

        return [
            '# HELP reverb_websocket_connections_active Active Reverb WebSocket connections',
            '# TYPE reverb_websocket_connections_active gauge',
            $this->formatMetric('reverb_websocket_connections_active', $connections),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function formatMetric(string $name, float|int $value, array $labels = []): string
    {
        $rendered = $name;

        if ($labels !== []) {
            $pairs = [];
            foreach ($labels as $key => $labelValue) {
                $pairs[] = $key.'="'.addcslashes($labelValue, "\\\"\n").'"';
            }
            $rendered .= '{'.implode(',', $pairs).'}';
        }

        return $rendered.' '.number_format((float) $value, 2, '.', '');
    }
}
