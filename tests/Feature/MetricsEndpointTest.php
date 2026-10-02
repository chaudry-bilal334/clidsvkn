<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the metrics endpoint that the Prometheus alert rules scrape.
 *
 * These tests exist because every alert rule in deploy/monitoring/prometheus-rules.yml
 * reads a series this endpoint or an exporter emits, and a series that stops being
 * emitted turns its rule into a silent no-op. Seven rules in this repository were
 * dead for exactly that reason while the endpoint still returned HTTP 200, so a
 * passing status code was never evidence that alerting worked.
 */
class MetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function scrapeMetrics(): string
    {
        $response = $this->get('/api/v1/metrics');

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'text/plain; version=0.0.4; charset=utf-8',
            'Prometheus requires the 0.0.4 exposition content type.',
        );

        return $response->getContent();
    }

    public function test_endpoint_emits_every_metric_the_alert_rules_select(): void
    {
        $body = $this->scrapeMetrics();

        // Each name below is read by a rule in prometheus-rules.yml.
        foreach ([
            'database_connections_active',
            'database_reachable',
            'failed_queue_jobs_total',
            'http_requests_total',
            'http_request_duration_seconds_sum',
            'http_request_duration_seconds_count',
            'redis_cache_hit_total',
            'redis_cache_miss_total',
            'reverb_websocket_connections_active',
        ] as $metric) {
            $this->assertStringContainsString(
                $metric,
                $body,
                "The metrics endpoint no longer exports {$metric}, so every alert rule reading it is now dead.",
            );
        }
    }

    /**
     * HighErrorRate selects `http_requests_total{status=~"5.."}`. The endpoint
     * previously emitted a single unlabelled series, so that selector matched
     * nothing and the rule could not fire however many 500s were returned.
     */
    public function test_http_requests_total_carries_a_status_label(): void
    {
        Cache::put('metrics:http_requests_by_status', json_encode([
            '200' => 97,
            '422' => 2,
            '500' => 1,
        ]), now()->addDay());

        $body = $this->scrapeMetrics();

        $this->assertMatchesRegularExpression(
            '/^http_requests_total\{status="500"\} 1\.00$/m',
            $body,
            'http_requests_total must emit per-status series, or HighErrorRate can never match.',
        );
        $this->assertMatchesRegularExpression(
            '/^http_requests_total\{status="200"\} 97\.00$/m',
            $body,
        );
    }

    /**
     * The unlabelled total is retained for backward compatibility, and the rule
     * scopes its numerator to labelled series so the aggregate cannot dilute the
     * error ratio.
     */
    public function test_unlabelled_request_total_is_still_exported(): void
    {
        Cache::put('metrics:http_requests_by_status', json_encode([
            '200' => 90,
            '500' => 10,
        ]), now()->addDay());

        $body = $this->scrapeMetrics();

        $this->assertMatchesRegularExpression(
            '/^http_requests_total 100\.00$/m',
            $body,
            'The unlabelled total must remain for dashboards and existing queries.',
        );
    }

    public function test_missing_status_breakdown_does_not_break_the_endpoint(): void
    {
        Cache::forget('metrics:http_requests_by_status');

        $body = $this->scrapeMetrics();

        $this->assertStringContainsString('http_requests_total', $body);
        $this->assertMatchesRegularExpression('/^http_requests_total 0\.00$/m', $body);
    }

    /**
     * Malformed cached JSON must not produce a 500 or an unparseable payload --
     * a broken scrape endpoint means every clinic-api alert goes silent at once.
     */
    public function test_corrupt_status_breakdown_falls_back_to_zero(): void
    {
        Cache::put('metrics:http_requests_by_status', 'not-json{{{', now()->addDay());

        $body = $this->scrapeMetrics();

        $this->assertStringContainsString('http_requests_total', $body);
        $this->assertStringNotContainsString('{status=', $body);
    }

    /**
     * The original code was `$count ?: 1` plus `$active = 1` in the catch block,
     * so the gauge could never be below 1 and `database_connections_active == 0`
     * was a contradiction. On SQLite the driver is not mysql, so the gauge keeps
     * its initial value; this test pins the contract that a reachable database
     * reports a positive count and database_reachable = 1.
     */
    public function test_reachable_database_reports_positive_connections(): void
    {
        $body = $this->scrapeMetrics();

        $this->assertMatchesRegularExpression('/^database_reachable 1\.00$/m', $body);

        preg_match('/^database_connections_active (\S+)$/m', $body, $matches);
        $this->assertNotEmpty($matches, 'database_connections_active was not exported.');
        $this->assertGreaterThan(
            0,
            (float) $matches[1],
            'A reachable database must never report zero active connections; that value '
                .'is reserved for the unreachable case, and DatabaseConnectionDrop fires on it.',
        );
    }

    /**
     * On an unreachable database the gauge must go to 0 and database_reachable to
     * 0, so DatabaseConnectionDrop and DatabaseUnreachable can both fire.
     *
     * Swapping the DB facade for a mock tears down the real connection, which
     * breaks the surrounding RefreshDatabase transaction for every later test.
     * Instead the query is made to fail by pointing the mysql connection at a
     * closed port, so the facade is left intact.
     */
    public function test_unreachable_database_reports_zero_and_unreachable(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            // Port 1 is reserved and nothing listens on it, so the connection is
            // refused immediately rather than hanging on a timeout.
            'database.connections.mysql.port' => '1',
            'database.connections.mysql.database' => 'clinic',
            'database.connections.mysql.username' => 'nobody',
            'database.connections.mysql.password' => 'nothing',
        ]);

        DB::purge('mysql');

        try {
            $body = $this->scrapeMetrics();

            $this->assertMatchesRegularExpression('/^database_connections_active 0\.00$/m', $body);
            $this->assertMatchesRegularExpression('/^database_reachable 0\.00$/m', $body);
        } finally {
            // Restore so the test's own database connection and the remaining
            // tests in this file are unaffected.
            config([
                'database.default' => 'sqlite',
                'database.connections.mysql' => config('database.connections.mysql'),
            ]);
            DB::purge('mysql');
        }
    }

    /**
     * The cache counters had no writer anywhere in the codebase, so both stayed
     * at 0 and the hit-ratio rule evaluated 0/0 = NaN. Laravel fires CacheHit and
     * CacheMissed on reads; these assert the listener turns them into counters.
     */
    public function test_cache_hits_and_misses_are_recorded(): void
    {
        Cache::forget('metrics:cache_hits');
        Cache::forget('metrics:cache_misses');

        Cache::put('probe:key', 'value', 60);

        Cache::get('probe:key');   // hit
        Cache::get('probe:missing'); // miss

        $body = $this->scrapeMetrics();

        preg_match('/^redis_cache_hit_total (\S+)$/m', $body, $hit);
        preg_match('/^redis_cache_miss_total (\S+)$/m', $body, $miss);

        $this->assertNotEmpty($hit, 'redis_cache_hit_total was not exported.');
        $this->assertNotEmpty($miss, 'redis_cache_miss_total was not exported.');
        $this->assertGreaterThan(0, (float) $hit[1], 'Cache hits were never counted.');
        $this->assertGreaterThan(0, (float) $miss[1], 'Cache misses were never counted.');
    }

    /**
     * Counting the counter keys themselves would make every scrape increment the
     * numbers it is about to report, so the ratio would measure Prometheus rather
     * than the application.
     */
    public function test_reading_the_counter_keys_does_not_inflate_them(): void
    {
        Cache::forget('metrics:cache_hits');
        Cache::forget('metrics:cache_misses');

        Cache::put('probe:key', 'value', 60);
        Cache::get('probe:key');

        // A scrape reads both counter keys.
        $this->scrapeMetrics();

        preg_match('/^redis_cache_hit_total (\S+)$/m', $this->scrapeMetrics(), $hit);

        $this->assertNotEmpty($hit);
        $this->assertLessThanOrEqual(
            5,
            (float) $hit[1],
            'Scraping the endpoint appears to inflate the cache hit counter.',
        );
    }

    public function test_latency_summary_is_exported_for_the_average_latency_rule(): void
    {
        $body = $this->scrapeMetrics();

        $this->assertMatchesRegularExpression(
            '/^http_request_duration_seconds_sum \S+$/m',
            $body,
            'HighAverageLatency reads the _sum series.',
        );
        $this->assertMatchesRegularExpression(
            '/^http_request_duration_seconds_count \S+$/m',
            $body,
            'HighAverageLatency reads the _count series.',
        );
    }

    /**
     * Every line must be valid Prometheus exposition format, otherwise Prometheus
     * rejects the whole scrape and the clinic-api job goes down silently.
     */
    public function test_every_line_is_valid_prometheus_exposition_format(): void
    {
        $body = $this->scrapeMetrics();

        foreach (explode("\n", trim($body)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{[^}]*\})? -?[0-9.eE+]+$/',
                $line,
                "Malformed exposition line would fail the scrape and silence every clinic-api alert: {$line}",
            );
        }
    }

    public function test_metrics_endpoint_is_reachable_without_authentication(): void
    {
        // Prometheus scrapes this endpoint with no credentials, so requiring auth
        // would leave every application alert permanently unevaluated.
        User::query()->delete();

        $this->getJson('/api/v1/metrics')->assertOk();
    }
}
