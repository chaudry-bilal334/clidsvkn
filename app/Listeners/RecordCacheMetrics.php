<?php

namespace App\Listeners;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Accumulates the cache hit/miss counters that MetricsController exports.
 *
 * Without this, `metrics:cache_hits` and `metrics:cache_misses` were only ever
 * read -- nothing in the application ever incremented them, so both stayed at
 * zero forever. RedisCacheHitRatioLow then evaluated 0/0, which is NaN in
 * PromQL, and a NaN comparison is false. The rule could therefore never fire,
 * whether the cache was healthy or badly misbehaving.
 *
 * Registered in AppServiceProvider and also picked up by listener
 * auto-discovery; the explicit registration is the documented one.
 */
class RecordCacheMetrics
{
    public function handle(CacheHit|CacheMissed $event): void
    {
        $key = $event->key;

        // The counter keys themselves are read by MetricsController on every
        // scrape. Counting those reads would make every scrape increment the
        // numbers it is about to report, so the ratio would measure Prometheus,
        // not the application.
        if (str_starts_with($key, 'metrics:')) {
            return;
        }

        try {
            $counter = $event instanceof CacheHit
                ? 'metrics:cache_hits'
                : 'metrics:cache_misses';

            $value = (int) Cache::get($counter, 0);
            Cache::put($counter, $value + 1, now()->addDays(30));
        } catch (Throwable) {
            // Metrics collection must never break cache reads.
        }
    }
}
