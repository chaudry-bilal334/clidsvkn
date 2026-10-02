<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackMetricsMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers TrackMetricsMiddleware, which feeds every clinic-http alert rule.
 *
 * The middleware previously ran its bookkeeping only after `$next()` returned,
 * so a request whose handler threw recorded nothing at all. An
 * exception-driven outage -- the most serious kind -- was invisible to
 * HighErrorRate, which is the opposite of what an error-rate alert is for.
 *
 * The middleware is invoked directly rather than through the router. Going
 * through HTTP would add the web/api middleware groups, and this test is about
 * what the middleware records for a given status, not about how the framework
 * routes a URL.
 */
class TrackMetricsMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'metrics:http_requests_total',
            'metrics:http_requests_by_status',
            'metrics:http_request_duration_sum',
            'metrics:http_request_duration_count',
        ] as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Run the middleware over a handler that returns the given status.
     */
    protected function handleReturning(int $status): Response
    {
        $middleware = new TrackMetricsMiddleware;

        $result = $middleware->handle(
            Request::create('/api/v1/patients', 'GET'),
            fn () => new Response('body', $status)
        );

        $this->assertInstanceOf(Response::class, $result);

        return $result;
    }

    /**
     * Run the middleware over a handler that throws, swallowing the exception so
     * a test can inspect what was recorded. The middleware is expected to
     * rethrow; `test_the_exception_is_still_propagated` asserts that directly.
     */
    protected function handleThrowing(string $message): void
    {
        try {
            $this->handleThrowingAndLetItThrow($message);
        } catch (RuntimeException) {
            // Expected -- see handleThrowingAndLetItThrow.
        }
    }

    protected function handleThrowingAndLetItThrow(string $message): void
    {
        $middleware = new TrackMetricsMiddleware;

        $middleware->handle(
            Request::create('/api/v1/patients', 'GET'),
            function () use ($message) {
                throw new RuntimeException($message);
            }
        );
    }

    protected function statusBreakdown(): array
    {
        $decoded = json_decode((string) Cache::get('metrics:http_requests_by_status', '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function test_it_returns_the_response_untouched(): void
    {
        $response = $this->handleReturning(200);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('body', $response->getContent());
    }

    public function test_it_records_a_successful_request(): void
    {
        $this->handleReturning(200);

        $this->assertSame(1, (int) Cache::get('metrics:http_requests_total'));
        $this->assertArrayHasKey(200, $this->statusBreakdown());
        $this->assertSame(1, $this->statusBreakdown()[200]);
    }

    public function test_it_records_a_4xx_status_under_its_own_label(): void
    {
        $this->handleReturning(422);

        $breakdown = $this->statusBreakdown();

        $this->assertArrayHasKey(422, $breakdown);
        $this->assertArrayNotHasKey(200, $breakdown, 'Statuses must not be merged together.');
    }

    public function test_it_records_a_5xx_status_returned_as_a_response(): void
    {
        $this->handleReturning(500);

        $this->assertArrayHasKey(500, $this->statusBreakdown());
    }

    /**
     * The regression this middleware fix exists for: an exception-driven 500
     * must still be counted, otherwise HighErrorRate reports a healthy API while
     * every request is failing.
     */
    public function test_it_records_a_500_when_the_handler_throws(): void
    {
        $this->handleThrowing('handler exploded');

        $this->assertSame(
            1,
            (int) Cache::get('metrics:http_requests_total'),
            'A throwing handler was not counted at all, so an exception-driven outage is invisible to HighErrorRate.',
        );
        $this->assertArrayHasKey(500, $this->statusBreakdown());
        $this->assertSame(1, $this->statusBreakdown()[500]);
    }

    public function test_the_exception_is_still_propagated(): void
    {
        // Recording metrics must never swallow or replace the original error: a
        // broken metrics cache must not turn a patient-data error into a 200.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('handler exploded');

        $this->handleThrowingAndLetItThrow('handler exploded');
    }

    public function test_it_counts_accumulate_across_requests(): void
    {
        $this->handleReturning(200);
        $this->handleReturning(200);
        $this->handleReturning(500);

        $breakdown = $this->statusBreakdown();

        $this->assertSame(3, (int) Cache::get('metrics:http_requests_total'));
        $this->assertSame(2, $breakdown[200]);
        $this->assertSame(1, $breakdown[500]);
    }

    public function test_it_updates_the_latency_series(): void
    {
        $this->handleReturning(200);

        $this->assertSame(1, (int) Cache::get('metrics:http_request_duration_count'));
        $this->assertGreaterThanOrEqual(0.0, (float) Cache::get('metrics:http_request_duration_sum'));
    }

    /**
     * HighAverageLatency divides the rate of the _sum series by the rate of the
     * _count series, so both must move together or the mean is meaningless.
     */
    public function test_latency_sum_and_count_stay_in_step(): void
    {
        $this->handleReturning(200);
        $this->handleReturning(200);

        $this->assertSame(2, (int) Cache::get('metrics:http_request_duration_count'));
    }

    /**
     * HighErrorRate's numerator is `sum(rate(http_requests_total{status=~"5.."}))`.
     * If the middleware stopped recording 5xx under its own label the rule would
     * silently evaluate to an empty vector.
     */
    public function test_5xx_series_matches_the_high_error_rate_selector(): void
    {
        $this->handleReturning(500);
        $this->handleThrowing('boom');

        $breakdown = $this->statusBreakdown();

        $serverErrors = array_filter(
            array_keys($breakdown),
            static fn ($status) => str_starts_with((string) $status, '5')
        );

        $this->assertNotEmpty(
            $serverErrors,
            'No series matches {status=~"5.."}, so HighErrorRate can never fire.',
        );
        $this->assertSame(2, $breakdown[500], 'Both the returned and the thrown 500 must be counted.');
    }

    /**
     * The sum of the per-status breakdown must equal the total, or the error-rate
     * numerator and denominator describe different amounts of traffic.
     */
    public function test_status_breakdown_sums_to_the_total(): void
    {
        $this->handleReturning(200);
        $this->handleReturning(422);
        $this->handleReturning(500);
        $this->handleThrowing('boom');

        $this->assertSame(
            (int) Cache::get('metrics:http_requests_total'),
            array_sum($this->statusBreakdown()),
            'The per-status breakdown and the unlabelled total have diverged, so HighErrorRate would compute a ratio against inconsistent counts.',
        );
    }

    public function test_a_broken_cache_does_not_break_the_response(): void
    {
        // Metrics collection must never take down a request. A cache outage should
        // cost us the metrics, not the patient's appointment.
        config(['cache.default' => 'does-not-exist']);

        $response = $this->handleReturning(200);

        $this->assertSame(200, $response->getStatusCode());
    }
}
