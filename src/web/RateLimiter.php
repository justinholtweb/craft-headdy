<?php

namespace justinholtweb\headdy\web;

use Craft;

/**
 * A fixed-window request counter.
 *
 * Deliberately the simplest thing that works: one cache key per identity per minute. A sliding
 * window would be fairer at the boundary, but it needs a sorted set per caller, and Craft's cache
 * abstraction has no such thing — implementing one on top of `get`/`set` would be slower and less
 * correct than the bucket it replaced.
 */
abstract class RateLimiter
{
    /**
     * Counts a request and returns how many remain in this window. A negative result means the
     * caller is over the limit.
     */
    public static function hit(string $identity, int $limit): int
    {
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }

        $window = (int)floor(time() / 60);
        $cacheKey = "headdy.rate.$identity.$window";
        $cache = Craft::$app->getCache();

        $count = (int)$cache->get($cacheKey) + 1;

        // Two minutes rather than one so the row survives a request that straddles the boundary;
        // the window is in the key, so a stale bucket is never read.
        $cache->set($cacheKey, $count, 120);

        return $limit - $count;
    }

    public static function reset(string $identity): void
    {
        $window = (int)floor(time() / 60);
        Craft::$app->getCache()->delete("headdy.rate.$identity.$window");
    }
}
