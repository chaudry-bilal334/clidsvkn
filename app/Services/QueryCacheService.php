<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Store-agnostic query cache with group-level invalidation.
 *
 * The application is backed by the database cache store (no tags, and no PHP
 * classes may be unserialized from cache), so each group carries a version
 * integer that is bumped on mutation. Cached values live under the current
 * version and become orphaned — but never stale — when the version advances.
 * Only plain serializable payloads (arrays, scalars) are stored.
 */
class QueryCacheService
{
    private const DEFAULT_TTL_SECONDS = 600;

    private const VERSION_KEY = 'qcv';

    private const GROUP_PREFIX = 'qc';

    /**
     * Resolve and cache a value, or return the cached copy.
     *
     * The $key should already include the group prefix (e.g. "appointments.availability:b1:2024-01-01").
     * If a TTL is not provided, the default (600s) is used.
     */
    public function remember(string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Resolve and cache a value belonging to a group, or return the cached copy.
     *
     * The cache key embeds the group version so that forgetGroup() invalidates
     * the entry atomically by advancing the version.
     */
    public function rememberGroup(string $group, string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        return Cache::remember(
            $this->key($group, $key),
            $ttlSeconds ?? self::DEFAULT_TTL_SECONDS,
            $callback,
        );
    }

    /**
     * Read an already-cached value, without writing on a miss.
     */
    public function get(string $key): mixed
    {
        return Cache::get($key);
    }

    /**
     * Cache a value directly.
     */
    public function put(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        Cache::put($key, $value, $ttlSeconds ?? self::DEFAULT_TTL_SECONDS);
    }

    /**
     * Invalidate every cached value belonging to a group atomically.
     *
     * The group prefix is the first segment of the cache key (e.g. "appointments.availability").
     */
    public function forgetGroup(string $group): void
    {
        $versionKey = $this->versionKey($group);

        if (! Cache::add($versionKey, 1)) {
            Cache::increment($versionKey);
        }
    }

    /**
     * The key that tracks the current version of a group.
     */
    private function versionKey(string $group): string
    {
        return self::VERSION_KEY.':'.$group;
    }

    /**
     * Clear a single member of a group without touching its siblings.
     */
    public function forget(string $group, string $key): void
    {
        Cache::forget($this->key($group, $key));
    }

    /**
     * Build a cache key from a group prefix and a key segment.
     *
     * @param  string  $group  The group prefix (e.g. "appointments.availability")
     * @param  string  $key  The key segment (e.g. "b1:2024-01-01")
     * @return string The full cache key with versioning
     */
    private function key(string $group, string $key): string
    {
        return implode(':', [
            self::GROUP_PREFIX,
            $group,
            'v'.$this->version($group),
            $key,
        ]);
    }

    /**
     * Resolve and cache a value for a group, or return the cached copy.
     *
     * @param  array{group: string, key: string}  $params
     */
    public function rememberParams(array $params): mixed
    {
        return $this->remember($params['key'], $params['callback'], $params['ttl'] ?? null);
    }

    private function version(string $group): int
    {
        return (int) Cache::get($this->versionKey($group), 0);
    }
}
